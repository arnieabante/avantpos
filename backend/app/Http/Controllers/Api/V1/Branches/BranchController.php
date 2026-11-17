<?php

namespace App\Http\Controllers\Api\V1\Branches;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branches\BranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Services\Branches\BranchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BranchController extends Controller
{
    public function __construct(private readonly BranchService $branches) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', 'in:active,inactive'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $branches = Branch::query()
            ->withCount(['terminals', 'users'])
            ->when($request->input('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->input('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('search')->toString()).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('code', 'like', $term));
            })
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get();

        return BranchResource::collection($branches);
    }

    public function store(BranchRequest $request): JsonResponse
    {
        $branch = $this->branches->create($request->validated());

        return (new BranchResource($branch->loadCount(['terminals', 'users'])))->response()->setStatusCode(201);
    }

    public function show(Branch $branch): BranchResource
    {
        return new BranchResource($branch->loadCount(['terminals', 'users']));
    }

    public function update(BranchRequest $request, Branch $branch): BranchResource
    {
        return new BranchResource($this->branches->update($branch, $request->validated())->loadCount(['terminals', 'users']));
    }

    public function activate(Branch $branch): BranchResource
    {
        return new BranchResource($this->branches->activate($branch));
    }

    public function deactivate(Branch $branch): BranchResource
    {
        return new BranchResource($this->branches->deactivate($branch));
    }

    public function makeMain(Branch $branch): BranchResource
    {
        return new BranchResource($this->branches->makeMain($branch));
    }
}
