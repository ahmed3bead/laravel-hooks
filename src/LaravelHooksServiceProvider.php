<?php

namespace Ahmed3bead\LaravelHooks;

use Ahmed3bead\LaravelHooks\Console\HooksManagementCommand;
use Ahmed3bead\LaravelHooks\Console\MakeHookCommand;
use Illuminate\Support\ServiceProvider;

class LaravelHooksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/laravel-hooks.php', 'laravel-hooks');

        $this->app->singleton(HookRegistry::class, function ($app) {
            return new HookRegistry();
        });

        $this->app->singleton(HookManager::class, function ($app) {
            return new HookManager($app->make(HookRegistry::class));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/laravel-hooks.php' => config_path('laravel-hooks.php'),
        ], 'laravel-hooks-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeHookCommand::class,
                HooksManagementCommand::class,
            ]);
        }
    }
}
