<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Tenancy\BranchContext;
use Illuminate\Http\Request;

/**
 * Inventory screens work in the branch selected in the app (X-Branch-Id).
 */
abstract class InventoryController extends Controller
{
    protected function branch(): Branch
    {
        return app(BranchContext::class)->branch()
            ?? throw new DomainException('Select a branch first.', 'BRANCH_REQUIRED', 422);
    }

    protected function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * A document of a branch the user cannot access is treated as not found.
     */
    protected function assertBranchAccess(Request $request, int ...$branchIds): void
    {
        foreach ($branchIds as $id) {
            if ($this->actor($request)->canAccessBranch($id)) {
                return;
            }
        }

        throw new DomainException('Resource not found.', 'NOT_FOUND', 404);
    }

    /**
     * LIKE pattern with the wildcards of the user's text escaped.
     */
    protected function like(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
    }
}
