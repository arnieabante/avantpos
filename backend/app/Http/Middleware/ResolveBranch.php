<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use App\Models\Branch;
use App\Models\Terminal;
use App\Models\User;
use App\Tenancy\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the working branch from the X-Branch-Id header and verifies the
 * actor may access it. Usage: `branch` (optional) or `branch:required`.
 * Must run after ResolveTenant (the branch lookup is tenant-scoped).
 */
class ResolveBranch
{
    public const HEADER = 'X-Branch-Id';

    public function __construct(private readonly BranchContext $context) {}

    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $actor = $request->user();

        // A terminal always works in its own branch.
        if ($actor instanceof Terminal) {
            $this->context->set($actor->branch()->firstOrFail());

            return $next($request);
        }

        $raw = (string) $request->header(self::HEADER, '');

        if ($raw === '') {
            if ($mode === 'required') {
                throw new DomainException('Select a branch first.', 'BRANCH_REQUIRED', 422);
            }

            return $next($request);
        }

        if (! ctype_digit($raw)) {
            throw new DomainException('Invalid branch.', 'BRANCH_INVALID', 422);
        }

        // Tenant-scoped lookup: another tenant's branch id is simply not found.
        $branch = Branch::query()->where('is_active', true)->find((int) $raw);

        if (! $branch || ! ($actor instanceof User && $actor->canAccessBranch($branch))) {
            throw new DomainException('You do not have access to this branch.', 'BRANCH_FORBIDDEN', 403);
        }

        $this->context->set($branch);

        return $next($request);
    }
}
