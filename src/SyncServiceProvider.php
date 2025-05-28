<?php

namespace Vdrnn\AcornSync;

use Illuminate\Support\ServiceProvider;
use Vdrnn\AcornSync\Commands\SyncInitCommand;
use Vdrnn\AcornSync\Commands\SyncEnvironmentCommand;
use Vdrnn\AcornSync\Commands\SyncStatusCommand;
use Vdrnn\AcornSync\Commands\SyncConfigCommand;
use Vdrnn\AcornSync\Services\SyncService;

class SyncServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/sync.php',
            'sync'
        );

        $this->app->singleton(SyncService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncInitCommand::class,
                SyncEnvironmentCommand::class,
                SyncStatusCommand::class,
                SyncConfigCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/sync.php' => config_path('sync.php'),
            ], 'acorn-sync');
        }
    }
}
