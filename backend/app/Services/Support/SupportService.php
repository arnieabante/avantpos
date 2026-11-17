<?php

namespace App\Services\Support;

use App\Exceptions\DomainException;
use App\Models\PlatformAdmin;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Support tickets between a business and AvantPOS.
 * A reply from the business re-opens the ticket; a reply from AvantPOS sets
 * it to "pending" (waiting for the business) unless resolved at the same time.
 */
class SupportService
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{subject: string, category?: string|null, priority?: string|null, body: string}  $data
     */
    public function open(array $data, User $user): SupportTicket
    {
        return DB::transaction(function () use ($data, $user) {
            $ticket = new SupportTicket;
            $ticket->forceFill([
                'user_id' => $user->getKey(),
                'number' => 'TMP-'.bin2hex(random_bytes(6)),
                'subject' => $data['subject'],
                'category' => $data['category'] ?? 'other',
                'priority' => $data['priority'] ?? 'normal',
                'status' => 'open',
            ])->save();
            $ticket->forceFill(['number' => 'T-'.str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT)])->save();
            $this->message($ticket, 'user', $user->getKey(), $data['body']);
            $this->audit->log('support.ticket_opened', $ticket, meta: ['number' => $ticket->number]);

            return $ticket;
        });
    }

    public function replyAsUser(SupportTicket $ticket, string $body, User $user): SupportTicket
    {
        if ($ticket->status === 'closed') {
            throw new DomainException('This ticket is closed. Open a new one if you still need help.', 'TICKET_CLOSED', 409);
        }
        $this->message($ticket, 'user', $user->getKey(), $body);
        $ticket->forceFill(['status' => 'open'])->save();

        return $ticket;
    }

    public function replyAsAdmin(SupportTicket $ticket, string $body, ?string $status, PlatformAdmin $admin): SupportTicket
    {
        return $this->tenants->runAs($ticket->tenant_id, function () use ($ticket, $body, $status, $admin) {
            $this->message($ticket, 'platform_admin', $admin->getKey(), $body);
            $ticket->forceFill([
                'status' => $status ?? 'pending',
                'assigned_to' => $ticket->assigned_to ?? $admin->getKey(),
            ])->save();
            $this->audit->log('support.ticket_replied', $ticket, meta: ['number' => $ticket->number], actor: $admin, tenantId: $ticket->tenant_id);

            return $ticket;
        });
    }

    /**
     * @param  array{status?: string, priority?: string, assigned_to?: int|null}  $data
     */
    public function update(SupportTicket $ticket, array $data, PlatformAdmin $admin): SupportTicket
    {
        $ticket->forceFill(array_intersect_key($data, array_flip(['status', 'priority', 'assigned_to'])))->save();
        $this->audit->log('support.ticket_updated', $ticket, new: $data, actor: $admin, tenantId: $ticket->tenant_id);

        return $ticket;
    }

    private function message(SupportTicket $ticket, string $authorType, int $authorId, string $body): TicketMessage
    {
        $m = new TicketMessage;
        $m->forceFill([
            'tenant_id' => $ticket->tenant_id,
            'support_ticket_id' => $ticket->getKey(),
            'author_type' => $authorType,
            'author_id' => $authorId,
            'body' => $body,
        ])->save();
        $ticket->touch();

        return $m;
    }
}
