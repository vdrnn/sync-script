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
                           {--skip-slack : Skip Slack notification}
                           {--skip-permissions : Skip setting upload permissions}
                           {--dry-run : Preview the commands a sync would run, without executing anything}
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

        // Preview only — nothing below this point runs
        if ($this->option('dry-run')) {
            return $this->performDryRun($syncService, $from, $to);
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
            if (!$this->option('skip-slack')) {
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
     * Show what a sync would execute, without running any of it.
     *
     * Database commands are only listed (they are destructive); the assets
     * step runs as a real rsync --dry-run, which is read-only and reports
     * what would actually transfer. Exits non-zero when the preview itself
     * fails, so scripts cannot mistake a broken preview for a clean one.
     */
    protected function performDryRun(SyncService $syncService, string $from, string $to): int
    {
        try {
            return $this->renderDryRun($syncService, $from, $to);
        } catch (\Throwable $e) {
            $this->error('❌ Dry run failed: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Render the dry-run preview. Extracted so performDryRun can wrap it in
     * error handling matching the real sync path.
     */
    protected function renderDryRun(SyncService $syncService, string $from, string $to): int
    {
        if ($this->option('skip-db') && $this->option('skip-assets')) {
            $this->warn('Nothing to preview (both database and assets are skipped).');
            return 1;
        }

        $toConfig = Config::get("sync.environments.{$to}");

        $this->newLine();
        $this->info("🔍 Dry run {$from} → {$to} — nothing will be changed.");
        if (!$this->option('skip-db')) {
            $this->warn("⚠️  A real run would RESET and overwrite the {$to} database at {$toConfig['url']}.");
        }

        if (!$this->option('skip-db')) {
            $this->newLine();
            $this->info('📊 Database commands that would run (in order):');
            foreach ($syncService->getDatabaseSyncCommands($from, $to, $this->option('local')) as $label => $command) {
                $this->line("  • {$label}:");
                $this->line('    <comment>' . $this->compactCommand($command) . '</comment>');
            }
        }

        if (!$this->option('skip-assets')) {
            $this->newLine();
            $this->info('📁 Assets commands that would run:');
            if (!$this->option('skip-permissions') && ($permissions = $syncService->getPermissionsCommand($from, $to))) {
                $this->line("  • Set local uploads permissions: <comment>{$permissions}</comment>");
            }
            $this->line('  • Transfer: <comment>' . $syncService->getAssetsSyncCommand($from, $to) . '</comment>');

            $this->newLine();
            $this->info('📁 Transfer preview (rsync --dry-run):');
            $preview = $syncService->dryRunAssets($from, $to);
            $report = trim($preview['output']);

            if (!$preview['success']) {
                $this->error('❌ The rsync dry run failed:');
                $this->line($report !== '' ? $report : '(no output)');
                $this->newLine();
                $this->error("❌ Dry run aborted — a real sync's assets step would fail the same way.");
                return 1;
            }

            $this->line($report !== '' ? $report : '(rsync reported nothing to compare)');
        }

        $this->newLine();
        $this->info('✅ Dry run complete — no changes were made.');

        return 0;
    }

    /**
     * Elide the inlined PATH of the local WP-CLI wrapper for display only —
     * the executed command is untouched.
     */
    protected function compactCommand(string $command): string
    {
        $path = getenv('PATH');

        return $path ? str_replace($path, '…', $command) : $command;
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

        // The source must be a working WordPress install — syncing from a
        // fresh/empty environment would overwrite the target with nothing.
        if (!$syncService->validateEnvironment($from)) {
            $this->error("❌ Unable to connect to {$from} environment (a sync source needs a working WordPress install)");
            return false;
        }
        $this->line("✅ Able to connect to {$from}");

        // The target only needs a reachable database — a fresh environment
        // is bootstrapped by its first sync.
        if (!$syncService->validateEnvironment($to, allowFresh: true)) {
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

            $setPermissions = !$this->option('skip-permissions');

            $this->executeWithProgress(function () use ($syncService, $from, $to, $setPermissions) {
                $syncService->syncAssets($from, $to, $setPermissions);
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
