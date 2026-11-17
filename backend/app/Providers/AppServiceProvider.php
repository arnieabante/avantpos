<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Support\Auth\TenantlessEloquentUserProvider;
use App\Support\Database\SchemaMacros;
use App\Support\FrontendUrl;
use App\Tenancy\BranchContext;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Request-scoped: reset between requests and queued jobs.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(BranchContext::class);
    }

    public function boot(): void
    {
        SchemaMacros::register();

        // Fail loudly on lazy loading / silently discarded attributes outside production.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Production: no migrate:fresh / db:wipe / migrate:reset by accident, HTTPS-only URLs.
        DB::prohibitDestructiveCommands($this->app->isProduction());
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Authentication runs BEFORE the tenant is resolved, so the user
        // provider must look users up without the tenant scope.
        Auth::provider('tenantless-eloquent', fn ($app, array $config) => new TenantlessEloquentUserProvider(
            $app['hash'],
            $config['model'],
        ));

        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(8)->letters()->mixedCase()->numbers()->uncompromised()
            : Password::min(8)->letters()->numbers());

        $this->configureRateLimiting();
        $this->configureAuthLinks();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($this->actorKey($request)));

        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(5)->by('auth:'.strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by('auth-ip:'.$request->ip()),
        ]);

        RateLimiter::for('pin', fn (Request $request) => Limit::perMinute(10)
            ->by('pin:'.$this->actorKey($request)));

        RateLimiter::for('sync', fn (Request $request) => Limit::perMinute(60)
            ->by('sync:'.$this->actorKey($request)));
    }

    private function actorKey(Request $request): string
    {
        $actor = $request->user();

        return $actor ? class_basename($actor).':'.$actor->getKey() : 'ip:'.$request->ip();
    }

    /**
     * E-mail links point at the SPA, which then calls the API.
     */
    private function configureAuthLinks(): void
    {
        ResetPassword::createUrlUsing(function ($user, string $token) {
            return $this->frontendUrl($user).'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);
        });

        VerifyEmail::createUrlUsing(function ($user) {
            // Signed API URL; the SPA page forwards it to the API.
            $signed = URL::temporarySignedRoute('api.v1.auth.verification.verify', now()->addHours(24), [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ]);

            return $this->frontendUrl($user).'/verify-email?'.http_build_query(['url' => $signed]);
        });
    }

    private function frontendUrl($user): string
    {
        return app(FrontendUrl::class)->forTenantId($user->tenant_id ?? null);
    }
}
