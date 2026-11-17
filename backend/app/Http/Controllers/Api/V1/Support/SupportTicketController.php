<?php

namespace App\Http\Controllers\Api\V1\Support;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The business's support tickets to AvantPOS. Owners and admins (settings
 * access) see all of the business's tickets; others see their own.
 */
class SupportTicketController extends Controller
{
    public function __construct(private readonly SupportService $support) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $tickets = SupportTicket::query()->with('user')
            ->when(! $this->seesAll($user), fn ($q) => $q->where('user_id', $user->getKey()))
            ->latest('updated_at')->limit(100)->get();

        return ApiResponse::data($tickets->map(fn ($t) => $this->ticket($t))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'category' => ['nullable', Rule::in(SupportTicket::CATEGORIES)],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high'])],
            'body' => ['required', 'string', 'min:5', 'max:5000'],
        ]);
        $ticket = $this->support->open($data, $this->user($request));

        return ApiResponse::data($this->detail($ticket->fresh(['user', 'messages'])), 201);
    }

    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->assertVisible($request, $ticket);

        return ApiResponse::data($this->detail($ticket->load(['user', 'messages'])));
    }

    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->assertVisible($request, $ticket);
        $body = $request->validate(['body' => ['required', 'string', 'min:1', 'max:5000']])['body'];
        $this->support->replyAsUser($ticket, $body, $this->user($request));

        return ApiResponse::data($this->detail($ticket->fresh(['user', 'messages'])));
    }

    private function seesAll(User $user): bool
    {
        return $user->isOwner() || $user->can('business.manage');
    }

    private function assertVisible(Request $request, SupportTicket $ticket): void
    {
        $user = $this->user($request);
        abort_unless($this->seesAll($user) || $ticket->user_id === $user->getKey(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function ticket(SupportTicket $t): array
    {
        return [
            'id' => $t->id, 'number' => $t->number, 'subject' => $t->subject, 'category' => $t->category,
            'priority' => $t->priority, 'status' => $t->status,
            'opened_by' => $t->user ? ['id' => $t->user->id, 'name' => $t->user->name] : null,
            'created_at' => $t->created_at?->toIso8601String(), 'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(SupportTicket $t): array
    {
        $users = User::query()->whereIn('id', $t->messages->where('author_type', 'user')->pluck('author_id'))->pluck('name', 'id');
        $admins = PlatformAdmin::query()->whereIn('id', $t->messages->where('author_type', 'platform_admin')->pluck('author_id'))->pluck('name', 'id');

        return $this->ticket($t) + ['messages' => $t->messages->map(fn ($m) => [
            'id' => $m->id,
            'from' => $m->author_type === 'platform_admin' ? 'support' : 'business',
            // AvantPOS staff are shown by first name only.
            'author' => $m->author_type === 'platform_admin' ? (strtok((string) ($admins[$m->author_id] ?? 'AvantPOS'), ' ').' (AvantPOS support)') : ($users[$m->author_id] ?? 'Someone'),
            'body' => $m->body,
            'created_at' => $m->created_at?->toIso8601String(),
        ])->all()];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
