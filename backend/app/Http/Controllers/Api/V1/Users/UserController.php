<?php

namespace App\Http\Controllers\Api\V1\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\RoleResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Rules\TenantExists;
use App\Services\Users\UserManagementService;
use App\Support\Api\ApiResponse;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function __construct(private readonly UserManagementService $users) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')->where('tenant_id', app(TenantContext::class)->requireId())],
            'branch_id' => ['nullable', 'integer', TenantExists::on('branches')],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = $this->users->visibleTo($request->user())
            ->with(['roles', 'branches'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('search')->toString()).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('role_id'), fn ($q) => $q->whereHas('roles', fn ($r) => $r->whereKey($request->integer('role_id'))))
            ->when($request->filled('branch_id'), fn ($q) => $q->whereHas('branches', fn ($b) => $b->whereKey($request->integer('branch_id'))))
            ->orderBy('status')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return UserResource::collection($users);
    }

    public function show(Request $request, User $user): UserResource
    {
        $this->users->assertVisible($request->user(), $user);

        return new UserResource($user->load(['roles', 'branches']));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->users->create($request->user(), $request->validated());

        return (new UserResource($user->load(['roles', 'branches'])))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        return new UserResource($this->users->update($request->user(), $user, $request->validated())->load(['roles', 'branches']));
    }

    public function activate(Request $request, User $user): UserResource
    {
        return new UserResource($this->users->activate($request->user(), $user)->load(['roles', 'branches']));
    }

    public function deactivate(Request $request, User $user): UserResource
    {
        return new UserResource($this->users->deactivate($request->user(), $user)->load(['roles', 'branches']));
    }

    public function clearPin(Request $request, User $user): UserResource
    {
        return new UserResource($this->users->clearPin($request->user(), $user)->load(['roles', 'branches']));
    }

    public function sendPasswordReset(Request $request, User $user): JsonResponse
    {
        $this->users->sendPasswordReset($request->user(), $user);

        return ApiResponse::message("A password reset link has been sent to {$user->email}.");
    }

    public function setPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'confirmed', Password::defaults()]]);
        $this->users->setPassword($request->user(), $user, $data['password']);

        return ApiResponse::message("{$user->name} can now sign in with the new password and will be asked to change it.");
    }

    /**
     * Roles the signed-in user may assign (invitation and user forms).
     */
    public function assignableRoles(Request $request): AnonymousResourceCollection
    {
        return RoleResource::collection($this->users->assignableRoles($request->user()));
    }
}
