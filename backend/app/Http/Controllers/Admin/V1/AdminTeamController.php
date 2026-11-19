<?php

namespace App\Http\Controllers\Admin\V1;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Services\Audit\AuditLogger;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * AvantPOS staff accounts (platform admins), managed by super admins.
 *
 * Roles: super_admin (everything, incl. this screen), billing (plans,
 * payments, subscriptions), support (tickets, activity). Safeguards: nobody
 * changes their own role or deactivates themselves, and there is always at
 * least one active super admin. Passwords are set here as a starting
 * password; two-factor can be reset for someone who lost their phone.
 */
class AdminTeamController extends Controller
{
    private const ROLES = [PlatformAdmin::ROLE_SUPER_ADMIN, PlatformAdmin::ROLE_BILLING, PlatformAdmin::ROLE_SUPPORT];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return ApiResponse::data(PlatformAdmin::query()->orderByDesc('is_active')->orderBy('name')->get()->map(fn ($a) => $this->present($a))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:150', Rule::unique('platform_admins', 'email')],
            'role' => ['required', Rule::in(self::ROLES)],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);
        $admin = PlatformAdmin::query()->create([...$data, 'email' => Str::lower($data['email']), 'is_active' => true]);
        $this->audit->log('platform.admin_created', $admin, new: ['email' => $admin->email, 'role' => $admin->role], actor: $this->me($request));

        return ApiResponse::data($this->present($admin->fresh()), 201);
    }

    public function update(Request $request, PlatformAdmin $admin): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'role' => ['sometimes', Rule::in(self::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $me = $this->me($request);
        $demotes = isset($data['role']) && $data['role'] !== $admin->role;
        $deactivates = array_key_exists('is_active', $data) && ! $data['is_active'] && $admin->is_active;
        if ($admin->is($me) && ($demotes || $deactivates)) {
            throw new DomainException('You cannot change your own role or deactivate yourself. Ask another super admin.', 'CANNOT_CHANGE_SELF', 403);
        }

        return DB::transaction(function () use ($admin, $data, $me, $demotes, $deactivates) {
            $old = $admin->only(['name', 'role', 'is_active']);
            $admin->fill($data)->save();
            $this->assertSuperAdminLeft($admin, $demotes || $deactivates);
            $this->audit->log('platform.admin_updated', $admin, $old, $admin->only(['name', 'role', 'is_active']), actor: $me);

            return ApiResponse::data($this->present($admin->fresh()));
        });
    }

    public function resetPassword(Request $request, PlatformAdmin $admin): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'confirmed', Password::defaults()]]);
        $admin->forceFill(['password' => $data['password'], 'remember_token' => null])->save();
        $this->audit->log('platform.admin_password_reset', $admin, actor: $this->me($request));

        return ApiResponse::message("{$admin->name} can now sign in with the new password.");
    }

    public function resetTwoFactor(Request $request, PlatformAdmin $admin): JsonResponse
    {
        $admin->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        $this->audit->log('platform.admin_two_factor_reset', $admin, actor: $this->me($request));

        return ApiResponse::data($this->present($admin->fresh()));
    }

    /** Lock the super admins and refuse a change that leaves none active. */
    private function assertSuperAdminLeft(PlatformAdmin $changed, bool $mayRemove): void
    {
        if (! $mayRemove) {
            return;
        }
        $left = PlatformAdmin::query()->where('role', PlatformAdmin::ROLE_SUPER_ADMIN)->where('is_active', true)->lockForUpdate()->count();
        if ($left === 0) {
            throw new DomainException('There must always be at least one active super admin.', 'LAST_SUPER_ADMIN', 409);
        }
    }

    private function me(Request $request): PlatformAdmin
    {
        /** @var PlatformAdmin */
        return $request->user('platform');
    }

    /** @return array<string, mixed> */
    private function present(PlatformAdmin $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->name,
            'email' => $a->email,
            'role' => $a->role,
            'is_active' => (bool) $a->is_active,
            'two_factor' => $a->two_factor_confirmed_at !== null,
            'last_login_at' => $a->last_login_at?->toIso8601String(),
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
