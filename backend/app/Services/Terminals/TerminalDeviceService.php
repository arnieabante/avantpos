<?php

namespace App\Services\Terminals;

use App\Exceptions\DomainException;
use App\Models\Terminal;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Binds a physical device (PC, Android tablet, Sunmi, phone) to a POS
 * terminal by issuing a device token. One device per terminal: registering
 * a new device revokes the previous one.
 *
 * The device token can only call terminal endpoints (catalog pull, offline
 * sales sync) — never user/back-office endpoints.
 */
class TerminalDeviceService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{terminal: Terminal, token: string}
     */
    public function register(Terminal $terminal, string $label, ?string $platform): array
    {
        if (! $terminal->isActive()) {
            throw new DomainException('This terminal is not active.', 'TERMINAL_INACTIVE', 422);
        }

        return DB::transaction(function () use ($terminal, $label, $platform) {
            $replaced = $terminal->tokens()->count() > 0;
            $terminal->tokens()->delete();

            $token = $terminal->createToken('device:'.$label, [Terminal::ABILITY_SYNC]);

            $terminal->forceFill([
                'device_label' => $label,
                'device_platform' => $platform,
                'device_registered_at' => now(),
            ])->save();

            $this->audit->log('terminal.device_registered', $terminal, new: [
                'device_label' => $label,
                'device_platform' => $platform,
            ], meta: ['replaced_previous_device' => $replaced]);

            return ['terminal' => $terminal, 'token' => $token->plainTextToken];
        });
    }

    public function revoke(Terminal $terminal): void
    {
        DB::transaction(function () use ($terminal) {
            $terminal->tokens()->delete();
            $old = $terminal->only(['device_label', 'device_platform']);
            $terminal->forceFill([
                'device_label' => null,
                'device_platform' => null,
                'device_registered_at' => null,
            ])->save();

            $this->audit->log('terminal.device_revoked', $terminal, old: $old);
        });
    }
}
