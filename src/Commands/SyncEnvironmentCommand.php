<?php

namespace Vdrnn\AcornSync\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Vdrnn\AcornSync\Services\SyncService;
use Exception;

class SyncEnvironmentCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'sync:env
                           {from : Source environment (development, staging, production)}
                           {to : Target environment (development, staging, production)}
                           {--skip-db : Skip database synchronization}
                           {--skip-assets : Skip assets synchronization}
                           {--local : Use local WP-CLI for development environment}
                           {--no-slack : Skip Slack notification}
                           {--no-permissions : Skip setting upload permissions}
                           {--force : Skip confirmation prompts}';

    /**
     * The console command description.
     */
    protected $description = 'Sync data between WordPress environments';

    /**
     * Execute the console command.
     */
    public function handle(SyncService $syncService): int
    {
        $from = $this->argument('from');
        $to = $this->argument('to');

        // Validate environments
        if (!$this->validateEnvironments($from, $to)) {
            return 1;
        }

        // Check environment connectivity
        if (!$this->validateConnectivity($syncService, $from, $to)) {
            return 1;
        }

        // Show sync preview and get confirmation
        if (!$this->option('force') && !$this->confirmSync($from, $to)) {
            $this->info('Sync cancelled.');
            return 0;
        }

        // Perform sync operations
        try {
            $this->performSync($syncService, $from, $to);

            // Send Slack notification if enabled
            if (!$this->option('no-slack')) {
                $syncService->sendSlackNotification($from, $to);
            }

            $this->newLine();
            $this->info("🔄 Sync from {$from} to {$to} complete.");
            $this->displayPostSyncInfo($to);

        } catch (Exception $e) {
            $this->error("❌ Sync failed: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * Validate environment arguments.
     */
    protected function validateEnvironments(string $from, string $to): bool
    {
        $validEnvironments = array_keys(Config::get('sync.environments', []));

        if (!in_array($from, $validEnvironments)) {
            $this->error("Invalid source environment: {$from}");
            $this->line('Available environments: ' . implode(', ', $validEnvironments));
            return false;
        }

        if (!in_array($to, $validEnvironments)) {
            $this->error("Invalid target environment: {$to}");
            $this->line('Available environments: ' . implode(', ', $validEnvironments));
            return false;
        }

        if ($from === $to) {
            $this->error('Source and target environments cannot be the same.');
            return false;
        }

        // Validate sync direction
        $validCombinations = [
            'production-development',
            'staging-development',
            'development-production',
            'development-staging',
            'production-staging',
            'staging-production',
        ];

        $combination = "{$from}-{$to}";
        if (!in_array($combination, $validCombinations)) {
            $this->error("Invalid sync direction: {$from} → {$to}");
            $this->line('Valid combinations: production↔development, staging↔development, production↔staging');
            return false;
        }

        return true;
    }

    /**
     * Validate environment connectivity.
     */
    protected function validateConnectivity(SyncService $syncService, string $from, string $to): bool
    {
        $this->info('🔍 Checking environment connectivity...');

        // Check source environment
        if (!$syncService->validateEnvironment($from)) {
            $this->error("❌ Unable to connect to {$from} environment");
            return false;
        }
        $this->line("✅ Able to connect to {$from}");

        // Check target environment
        if (!$syncService->validateEnvironment($to)) {
            $this->error("❌ Unable to connect to {$to} environment");
            return false;
        }
        $this->line("✅ Able to connect to {$to}");

        return true;
    }

    /**
     * Show sync preview and get user confirmation.
     */
    protected function confirmSync(string $from, string $to): bool
    {
        $fromConfig = Config::get("sync.environments.{$from}");
        $toConfig = Config::get("sync.environments.{$to}");

        $direction = $this->getSyncDirection($from, $to);
        $directionEmoji = match($direction) {
            'up' => '⬆️',
            'down' => '⬇️',
            'horizontal' => '↔️',
            default => '🔄',
        };

        $this->newLine();
        $this->info('📋 Sync Preview:');
        $this->newLine();

        if (!$this->option('skip-db')) {
            $this->line("  • <comment>Reset the {$to} database</comment> ({$toConfig['url']})");
        }

        if (!$this->option('skip-assets')) {
            $this->line("  • <comment>Sync assets {$directionEmoji}</comment> from {$from} ({$fromConfig['url']})");
        }

        if ($this->option('skip-db') && $this->option('skip-assets')) {
            $this->warn('Nothing to synchronize (both database and assets are skipped).');
            return false;
        }

        $this->newLine();
        return $this->confirm('Would you like to proceed with this sync?');
    }

    /**
     * Perform the actual sync operations.
     */
    protected function performSync(SyncService $syncService, string $from, string $to): void
    {
        // Sync database
        if (!$this->option('skip-db')) {
            $this->info('📊 Syncing database...');
            $this->executeWithProgress(function () use ($syncService, $from, $to) {
                $syncService->syncDatabase($from, $to, $this->option('local'));
            });
            $this->newLine();
            $this->line('✅ Database sync complete');
        }

        // Sync assets
        if (!$this->option('skip-assets')) {
            $this->info('📁 Syncing assets...');

            // Set permissions if not disabled
            if (!$this->option('no-permissions')) {
                $syncService->setUploadsPermissions();
            }

            $this->executeWithProgress(function () use ($syncService, $from, $to) {
                $syncService->syncAssets($from, $to);
            });
            $this->newLine();
            $this->line('✅ Assets sync complete');
        }
    }

    /**
     * Get sync direction for display purposes.
     */
    protected function getSyncDirection(string $from, string $to): string
    {
        $remoteEnvs = ['production', 'staging'];

        if (in_array($from, $remoteEnvs) && in_array($to, $remoteEnvs)) {
            return 'horizontal';
        } elseif ($from === 'development') {
            return 'up';
        } else {
            return 'down';
        }
    }

    /**
     * Display post-sync information.
     */
    protected function displayPostSyncInfo(string $environment): void
    {
        $config = Config::get("sync.environments.{$environment}");

        $this->newLine();
        $this->line("🌐 <comment>{$config['url']}</comment>");
        $this->newLine();
    }

    /**
     * Execute a callback with a progress bar.
     */
    protected function executeWithProgress(callable $callback): void
    {
        $bar = $this->output->createProgressBar(1);
        $bar->start();

        $callback();

        $bar->advance();
        $bar->finish();
    }
}
