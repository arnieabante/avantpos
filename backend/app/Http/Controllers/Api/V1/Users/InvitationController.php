<?php

namespace App\Http\Controllers\Api\V1\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Models\UserInvitation;
use App\Services\Users\InvitationService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $invitations = $this->invitations->visibleTo($request->user())
            ->with('role')
            ->when($request->query('status') === 'pending', fn ($q) => $q->pending())
            ->latest()
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return InvitationResource::collection($invitations);
    }

    public function store(StoreInvitationRequest $request): JsonResponse
    {
        $invitation = $this->invitations->invite($request->user(), $request->validated());

        return (new InvitationResource($invitation->load('role')))->response()->setStatusCode(201);
    }

    public function resend(Request $request, UserInvitation $invitation): InvitationResource
    {
        $this->invitations->assertVisible($request->user(), $invitation);

        return new InvitationResource($this->invitations->resend($invitation)->load('role'));
    }

    public function destroy(Request $request, UserInvitation $invitation): JsonResponse
    {
        $this->invitations->assertVisible($request->user(), $invitation);
        $this->invitations->revoke($invitation);

        return ApiResponse::noContent();
    }
}
