<?php

namespace Vdrnn\AcornSync\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Vdrnn\AcornSync\Services\SyncService;

class SyncStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'sync:status
                           {environment? : Check specific environment (optional)}
                           {--debug : Show detailed debugging information}';

    /**
     * The console command description.
     */
    protected $description = 'Check environment connectivity and configuration status';

    /**
     * Execute the console command.
     */
    public function handle(SyncService $syncService): int
    {
        $environment = $this->argument('environment');

        if ($environment) {
            return $this->checkSingleEnvironment($syncService, $environment);
        }

        return $this->checkAllEnvironments($syncService);
    }

    /**
     * Check a single environment.
     */
    protected function checkSingleEnvironment(SyncService $syncService, string $environment): int
    {
        $environments = array_keys(Config::get('sync.environments', []));

        if (!in_array($environment, $environments)) {
            $this->error("Environment '{$environment}' not found in configuration.");
            $this->line('Available environments: ' . implode(', ', $environments));
            return 1;
        }

        $this->info("🔍 Checking {$environment} environment...");
        $this->newLine();

        $this->displayEnvironmentDetails($environment);
        $this->checkEnvironmentConnectivity($syncService, $environment);

        return 0;
    }

    /**
     * Check all configured environments.
     */
    protected function checkAllEnvironments(SyncService $syncService): int
    {
        $environments = Config::get('sync.environments', []);

        if (empty($environments)) {
            $this->warn('No environments configured. Run "wp acorn sync:init" to set up environments.');
            return 1;
        }

        $this->info('🔍 Checking all environments...');
        $this->newLine();

        $results = [];
        foreach ($environments as $name => $config) {
            $this->comment("Environment: {$name}");
            $this->displayEnvironmentDetails($name);
            $isConnected = $this->checkEnvironmentConnectivity($syncService, $name);
            $results[$name] = $isConnected;
            $this->newLine();
        }

        // Summary
        $this->displaySummary($results);

        return 0;
    }

    /**
     * Display environment configuration details.
     */
    protected function displayEnvironmentDetails(string $environment): void
    {
        $config = Config::get("sync.environments.{$environment}");

        $this->line("  URL: <comment>{$config['url']}</comment>");
        $this->line("  Uploads: <comment>{$config['uploads_path']}</comment>");

        if ($config['wp_cli_alias']) {
            $this->line("  WP-CLI Alias: <comment>{$config['wp_cli_alias']}</comment>");
        } else {
            $this->line("  WP-CLI Alias: <comment>Local environment</comment>");
        }

        if (isset($config['ssh_host'])) {
            $this->line("  SSH Host: <comment>{$config['ssh_host']}</comment>");
        }

        if (isset($config['remote_path'])) {
            $this->line("  Remote Path: <comment>{$config['remote_path']}</comment>");
        }
    }

    /**
     * Check environment connectivity.
     */
    protected function checkEnvironmentConnectivity(SyncService $syncService, string $environment): bool
    {
        // write(), not line(): line()'s second argument is a style, not a
        // newline suppressor — this keeps "Connectivity:" and its result on
        // one line.
        $this->output->write('  Connectivity: ');

        try {
            // Status is informational: a fresh environment (reachable database,
            // WordPress not installed yet) counts as connected — it is a valid
            // sync target.
            $result = $syncService->validateEnvironment($environment, allowFresh: true);

            if ($this->option('debug')) {
                $this->newLine();
                $this->line('  Debug Info: validation returned ' . ($result ? 'true' : 'false'));
            }

            if ($result) {
                $this->line('<info>✅ Connected</info>');
                return true;
            } else {
                $this->line('<error>❌ Failed</error>');

                if ($this->option('debug')) {
                    $config = Config::get("sync.environments.{$environment}");
                    $alias = $config['wp_cli_alias'] ?? 'local';
                    $wp = 'wp' . ($alias !== 'local' ? " {$alias}" : '');
                    $this->line("  Try running manually: <comment>{$wp} option get home</comment>");
                    $this->line("  For a fresh environment (WordPress not installed yet): <comment>{$wp} db check</comment>");
                    if ($alias === 'local') {
                        $this->line('  Note: the tool itself runs local commands through a clean environment (env -i PATH=… HOME=… wp …), which can behave differently from your shell.');
                    }
                }

                return false;
            }
        } catch (\Exception $e) {
            $this->line('<error>❌ Error: ' . $e->getMessage() . '</error>');

            if ($this->option('debug')) {
                $this->line('  Stack trace: ' . $e->getTraceAsString());
            }

            return false;
        }
    }

    /**
     * Display connectivity summary.
     */
    protected function displaySummary(array $results): void
    {
        $this->info('📊 Summary:');
        $this->newLine();

        $connected = array_filter($results);
        $total = count($results);
        $connectedCount = count($connected);

        $this->line("  Total environments: <comment>{$total}</comment>");
        $this->line("  Connected: <info>{$connectedCount}</info>");
        $this->line("  Failed: <error>" . ($total - $connectedCount) . "</error>");

        if ($connectedCount === $total) {
            $this->newLine();
            $this->info('🎉 All environments are accessible!');
        } elseif ($connectedCount === 0) {
            $this->newLine();
            $this->error('❌ No environments are accessible. Please check your configuration.');
        } else {
            $this->newLine();
            $this->warn('⚠️  Some environments are not accessible. Please check the failed connections.');
        }
    }
}
