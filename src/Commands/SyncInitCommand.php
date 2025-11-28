<?php

namespace Vdrnn\AcornSync\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Vdrnn\AcornSync\Services\SyncService;
use Symfony\Component\Yaml\Yaml;
use Exception;

class SyncInitCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'sync:init
                           {--force : Overwrite existing configuration}
                           {--auto : Auto-detect configuration from wp-cli.yml}';

    /**
     * The console command description.
     */
    protected $description = 'Initialize Acorn Sync configuration';

    /**
     * Execute the console command.
     */
    public function handle(SyncService $syncService): int
    {
        $this->info('🚀 Initializing Acorn Sync...');
        $this->newLine();

        // Check if config already exists
        $configPath = config_path('sync.php');
        if (File::exists($configPath) && !$this->option('force')) {
            if (!$this->confirm('Sync configuration already exists. Do you want to reconfigure?')) {
                $this->info('Configuration unchanged.');
                return 0;
            }
        }

        // Try to auto-detect from wp-cli.yml
        $wpCliConfig = $this->detectWpCliConfig();

        // Collect environment data
        $environments = $this->collectEnvironmentData($wpCliConfig);

        // Update configuration file
        if (!$this->updateConfigFile($environments)) {
            $this->error('Failed to create configuration file.');
            return 1;
        }

        // Update wp-cli.yml
        if ($this->confirm('Update wp-cli.yml with remote aliases?', true)) {
            $this->updateWpCliConfig($environments, $syncService);
        }

        // Update .env file
        if ($this->confirm('Update .env file with environment variables?', true)) {
            $this->updateEnvFile($environments);
        }

        $this->newLine();
        $this->info('🎉 Acorn Sync setup complete!');
        $this->newLine();
        $this->line('Available commands:');
        $this->line('  <comment>wp acorn sync:env {from} {to}</comment> - Sync between environments');
        $this->line('  <comment>wp acorn sync:status</comment>           - Check environment connectivity');
        $this->line('  <comment>wp acorn sync:config</comment>           - Manage configuration');
        $this->newLine();

        return 0;
    }

    /**
     * Detect existing wp-cli.yml configuration.
     */
    protected function detectWpCliConfig(): ?array
    {
        $wpCliPath = base_path('wp-cli.yml');
        if (!File::exists($wpCliPath)) {
            return null;
        }

        try {
            $config = Yaml::parseFile($wpCliPath);

            if (empty($config)) {
                return null;
            }

            $detected = [];
            foreach ($config as $key => $value) {
                if (str_starts_with($key, '@')) {
                    $envName = ltrim($key, '@');
                    $detected[$envName] = $value;
                }
            }

            if (!empty($detected)) {
                $this->info('✅ Detected existing wp-cli.yml configuration');
                $this->line('Found environments: ' . implode(', ', array_keys($detected)));
                $this->newLine();
            }

            return $detected;
        } catch (Exception $e) {
            $this->warn('Could not parse wp-cli.yml: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Collect environment data from user input.
     */
    protected function collectEnvironmentData(?array $wpCliConfig = null): array
    {
        $this->info('📝 Environment Configuration');
        $this->line('Please provide the following information for each environment:');
        $this->newLine();

        $environments = [];

        // Development environment
        $this->comment('Development Environment:');

        $devUrl = env('WP_HOME', 'https://example.test');
        $environments['development'] = [
            'url' => $this->ask('Site URL', $devUrl),
            'uploads_path' => $this->ask('Uploads directory path', 'web/app/uploads/'),
            'wp_cli_alias' => null,
        ];
        $this->newLine();

        // Staging environment
        $this->comment('Staging Environment:');
        $stagingDetected = $wpCliConfig['staging'] ?? null;

        if ($stagingDetected && $this->option('auto')) {
            $environments['staging'] = $this->buildEnvironmentFromWpCli('staging', $stagingDetected);
        } else {
            $stagingUrl = $this->ask('Site URL (leave empty to skip staging)');
            if ($stagingUrl) {
                $stagingUploads = $this->ask(
                    'Uploads path with SSH (e.g., web@staging.example.com:/srv/www/example.com/shared/uploads/)'
                );

                $sshPort = $this->ask('SSH port (leave empty for default 22)', '22');

                $remoteDetails = $this->extractRemoteDetails($stagingUploads, $sshPort);

                $environments['staging'] = [
                    'url' => $stagingUrl,
                    'uploads_path' => $stagingUploads,
                    'wp_cli_alias' => '@staging',
                    'ssh_host' => $remoteDetails['ssh_host'],
                    'ssh_port' => $remoteDetails['ssh_port'],
                    'remote_path' => $remoteDetails['remote_path'],
                ];
            }
        }
        $this->newLine();

        // Production environment
        $this->comment('Production Environment:');
        $productionDetected = $wpCliConfig['production'] ?? null;

        if ($productionDetected && $this->option('auto')) {
            $environments['production'] = $this->buildEnvironmentFromWpCli('production', $productionDetected);
        } else {
            $productionUrl = $this->ask('Site URL (leave empty to skip production)');
            if ($productionUrl) {
                $productionUploads = $this->ask(
                    'Uploads path with SSH (e.g., web@example.com:/srv/www/example.com/shared/uploads/)'
                );

                $sshPort = $this->ask('SSH port (leave empty for default 22)', '22');

                $remoteDetails = $this->extractRemoteDetails($productionUploads, $sshPort);

                $environments['production'] = [
                    'url' => $productionUrl,
                    'uploads_path' => $productionUploads,
                    'wp_cli_alias' => '@production',
                    'ssh_host' => $remoteDetails['ssh_host'],
                    'ssh_port' => $remoteDetails['ssh_port'],
                    'remote_path' => $remoteDetails['remote_path'],
                ];
            }
        }

        return $environments;
    }

    /**
     * Build environment configuration from wp-cli.yml data.
     */
    protected function buildEnvironmentFromWpCli(string $name, array $wpCliData): array
    {
        $sshHost = $wpCliData['ssh'] ?? null;
        $remotePath = $wpCliData['path'] ?? null;

        // Extract SSH port from ssh string if present (e.g., "user@host:port" or "user@host")
        $sshPort = '22';
        if ($sshHost && str_contains($sshHost, ':')) {
            [$sshHost, $sshPort] = explode(':', $sshHost, 2);
        }

        // Construct uploads path
        $uploadsPath = $sshHost && $remotePath
            ? $sshHost . ':' . rtrim($remotePath, '/') . '/web/app/uploads/'
            : 'web/app/uploads/';

        return [
            'url' => $this->ask("URL for {$name}", "https://{$name}.example.com"),
            'uploads_path' => $uploadsPath,
            'wp_cli_alias' => "@{$name}",
            'ssh_host' => $sshHost,
            'ssh_port' => $sshPort,
            'remote_path' => $remotePath,
        ];
    }

    /**
     * Extract SSH host and remote path from uploads path.
     */
    protected function extractRemoteDetails(string $uploadsPath, string $sshPort = '22'): array
    {
        if (preg_match('/^(.+):(.+)$/', $uploadsPath, $matches)) {
            $host = $matches[1];
            $path = $matches[2];

            // Extract base path (remove uploads directory)
            $basePath = dirname($path);
            if (str_ends_with($basePath, '/shared')) {
                $basePath = dirname($basePath) . '/current';
            }

            return [
                'ssh_host' => $host,
                'ssh_port' => $sshPort,
                'remote_path' => $basePath,
            ];
        }

        return [
            'ssh_host' => null,
            'ssh_port' => '22',
            'remote_path' => null,
        ];
    }

    /**
     * Update the configuration file.
     */
    protected function updateConfigFile(array $environments): bool
    {
        $configPath = config_path('sync.php');
        $configDir = dirname($configPath);

        // Ensure config directory exists
        if (!File::isDirectory($configDir)) {
            if (!File::makeDirectory($configDir, 0755, true)) {
                $this->error("Failed to create config directory: {$configDir}");
                return false;
            }
        }

        // Read existing config or use default
        $config = File::exists($configPath) ? include $configPath : [];

        // Update environments
        $config['environments'] = $environments;

        // Ensure default options exist
        if (!isset($config['options'])) {
            $config['options'] = [
                'backup_before_sync' => true,
                'confirm_destructive_operations' => true,
                'set_upload_permissions' => true,
                'upload_permissions' => '755',
                'database_charset' => 'utf8mb4',
                'rsync_options' => '-az --progress',
                'ssh_options' => '-o StrictHostKeyChecking=no',
                'enable_slack_notifications' => false,
                'slack_webhook_url' => env('SYNC_SLACK_WEBHOOK'),
                'slack_channel' => env('SYNC_SLACK_CHANNEL', '#general'),
            ];
        }

        if (!isset($config['wp_cli'])) {
            $config['wp_cli'] = [
                'config_file' => 'wp-cli.yml',
                'auto_update_aliases' => true,
                'backup_config_before_update' => true,
            ];
        }

        // Write config file
        $configContent = "<?php\n\nreturn " . var_export($config, true) . ";\n";

        try {
            File::put($configPath, $configContent);
            $this->info('✅ Configuration file created at: ' . str_replace(base_path(), '', $configPath));
            return true;
        } catch (Exception $e) {
            $this->error('Failed to write configuration file: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update wp-cli.yml with environment aliases.
     */
    protected function updateWpCliConfig(array $environments, SyncService $syncService): void
    {
        try {
            if ($syncService->updateWpCliConfig($environments)) {
                $this->info('✅ wp-cli.yml updated successfully');
            } else {
                $this->warn('⚠️  Could not update wp-cli.yml automatically');
                $this->displayManualWpCliInstructions($environments);
            }
        } catch (Exception $e) {
            $this->warn('⚠️  Could not update wp-cli.yml automatically: ' . $e->getMessage());
            $this->displayManualWpCliInstructions($environments);
        }
    }

    /**
     * Display manual wp-cli.yml instructions.
     */
    protected function displayManualWpCliInstructions(array $environments): void
    {
        $this->newLine();
        $this->info('Please add the following to your wp-cli.yml manually:');
        $this->newLine();

        foreach ($environments as $name => $config) {
            if (isset($config['wp_cli_alias'], $config['ssh_host'], $config['remote_path'])) {
                $this->line($config['wp_cli_alias'] . ':');
                $this->line("  ssh: {$config['ssh_host']}");
                $this->line("  path: {$config['remote_path']}");
                $this->newLine();
            }
        }
    }

    /**
     * Update .env file with environment variables.
     */
    protected function updateEnvFile(array $environments): void
    {
        $envPath = base_path('.env');
        $envContent = File::exists($envPath) ? File::get($envPath) : '';

        $envVars = [];
        foreach ($environments as $name => $config) {
            $prefix = 'SYNC_' . strtoupper($name);
            $envVars["{$prefix}_URL"] = $config['url'];
            $envVars["{$prefix}_UPLOADS_PATH"] = $config['uploads_path'];
        }

        // Add or update environment variables
        foreach ($envVars as $key => $value) {
            $pattern = "/^{$key}=.*$/m";
            $replacement = "{$key}=\"{$value}\"";

            if (preg_match($pattern, $envContent)) {
                $envContent = preg_replace($pattern, $replacement, $envContent);
            } else {
                $envContent .= "\n{$replacement}";
            }
        }

        File::put($envPath, $envContent);
        $this->info('✅ .env file updated');
    }
}
