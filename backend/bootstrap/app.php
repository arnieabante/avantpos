<?php

use App\Exceptions\DomainException;
use App\Http\Middleware\EnsureAdminRole;
use App\Http\Middleware\EnsureSubscriptionWritable;
use App\Http\Middleware\EnsureTerminalActor;
use App\Http\Middleware\EnsureUserActor;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\RequirePlanFeature;
use App\Http\Middleware\ResolveBranch;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Api\ApiResponse;
use App\Tenancy\Exceptions\MissingTenantContextException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException as PermissionUnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA cookie authentication for first-party frontends.
        $middleware->statefulApi();

        $middleware->append(SecurityHeaders::class);

        $middleware->prependToGroup('api', ForceJsonResponse::class);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'subscription.writable' => EnsureSubscriptionWritable::class,
            'admin.role' => EnsureAdminRole::class,
            'branch' => ResolveBranch::class,
            'actor.user' => EnsureUserActor::class,
            'actor.terminal' => EnsureTerminalActor::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'feature' => RequirePlanFeature::class,
        ]);

        // The tenant must be resolved (after authentication) BEFORE route
        // model binding runs, because bindings use tenant-scoped queries.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);

        // API never redirects to a login page.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->dontReport([DomainException::class]);

        /*
         * Standard error envelope: { "message", "code", "errors"?, "context"? }
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return match (true) {
                $e instanceof DomainException => ApiResponse::error($e->getMessage(), $e->errorCode, $e->status, context: $e->context),
                $e instanceof ValidationException => ApiResponse::error($e->getMessage(), 'VALIDATION_ERROR', 422, $e->errors()),
                $e instanceof AuthenticationException => ApiResponse::error('Unauthenticated.', 'UNAUTHENTICATED', 401),
                $e instanceof TokenMismatchException => ApiResponse::error('Your session has expired. Please refresh and try again.', 'CSRF_TOKEN_MISMATCH', 419),
                $e instanceof AuthorizationException,
                $e instanceof PermissionUnauthorizedException => ApiResponse::error('You do not have permission to perform this action.', 'FORBIDDEN', 403),
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::error('Resource not found.', 'NOT_FOUND', 404),
                $e instanceof ThrottleRequestsException => ApiResponse::error('Too many requests. Please slow down.', 'TOO_MANY_REQUESTS', 429),
                $e instanceof MissingTenantContextException => null, // programming error: report + generic 500 below
                // Laravel converts TokenMismatchException to a 419 HttpException before rendering.
                $e instanceof HttpExceptionInterface && $e->getStatusCode() === 419 => ApiResponse::error('Your session has expired. Please refresh and try again.', 'CSRF_TOKEN_MISMATCH', 419),
                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    $e->getMessage() ?: 'Request failed.',
                    $e->getStatusCode() === 403 ? 'FORBIDDEN' : 'HTTP_'.$e->getStatusCode(),
                    $e->getStatusCode(),
                ),
                default => null,
            } ?? (config('app.debug') ? null : ApiResponse::error('Something went wrong. Please try again.', 'SERVER_ERROR', 500));
        });
    })->create();
