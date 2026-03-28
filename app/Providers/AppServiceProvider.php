<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->register(SqlLogServiceProvider::class);

        // Bind repository interfaces to implementations
        $this->app->bind(
            \App\Repositories\Contracts\UserRepositoryInterface::class,
            \App\Repositories\UserRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\UserTokenRepositoryInterface::class,
            \App\Repositories\UserTokenRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\SecurityLogRepositoryInterface::class,
            \App\Repositories\SecurityLogRepository::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
