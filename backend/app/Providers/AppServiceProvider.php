<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keep recovery credentials out of access logs and referrer headers.
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => rtrim(config('app.url'), '/').'/reset-password#'
            .http_build_query(['token' => $token, 'email' => $user->getEmailForPasswordReset()]));
    }
}
