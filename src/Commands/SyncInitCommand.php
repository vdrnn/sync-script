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

        // Detect existing configuration sources
        $this->info('🔍 Detecting existing configuration...');
        $this->newLine();

        $wpCliConfig = $this->detectWpCliConfig($syncService);
        $syncShConfig = $this->detectSyncShConfig($syncService);

        // Show what was found
        $foundSources = [];
        if ($wpCliConfig) {
            $foundSources[] = 'wp-cli.yml (SSH connection details)';
        }
        if ($syncShConfig) {
            $foundSources[] = 'sync.sh (URLs and uploads paths)';
        }

        if (!empty($foundSources)) {
            $this->line('<info>Found:</info>');
            foreach ($foundSources as $source) {
                $this->line("  ✓ {$source}");
            }
            $this->newLine();
        }

        // Collect environment data (merges wp-cli.yml and sync.sh intelligently)
        $environments = $this->collectEnvironmentData($syncService, $wpCliConfig, $syncShConfig);

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
            $this->updateEnvFile($environments, $syncService);
        }

        // Offer to backup/remove sync.sh if it was detected
        if ($syncShConfig && isset($syncShConfig['_sync_sh_path'])) {
            $this->newLine();
            $this->info('📦 Migration from sync.sh complete!');
            $this->newLine();

            $syncShPath = $syncShConfig['_sync_sh_path'];
            $relativePath = str_replace($syncService->getProjectRoot() . '/', '', $syncShPath);

            if ($this->confirm("Remove {$relativePath}? (it will be backed up to {$relativePath}.backup)", true)) {
                $backupPath = $syncShPath . '.backup';
                if (rename($syncShPath, $backupPath)) {
                    $this->line("✅ Moved {$relativePath} to {$relativePath}.backup");
                } else {
                    $this->warn("⚠️  Could not move {$relativePath}. Please remove it manually.");
                }
            }
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
    protected function detectWpCliConfig(SyncService $syncService): ?array
    {
        $wpCliPath = $syncService->getProjectRoot() . '/wp-cli.yml';
        if (!File::exists($wpCliPath)) {
            return null;
        }

        try {
            // Read the file content
            $content = file_get_contents($wpCliPath);

            // Fix unquoted @ symbols at the start of lines (common in wp-cli.yml)
            // Pattern: @something: becomes "@something":
            $content = preg_replace('/^(@[a-zA-Z0-9_-]+):/m', '"$1":', $content);

            // Parse the fixed YAML
            $config = Yaml::parse($content);

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
     * Detect existing sync.sh configuration by searching the project.
     */
    protected function detectSyncShConfig(SyncService $syncService): ?array
    {
        $projectRoot = $syncService->getProjectRoot();

        // Search for sync.sh anywhere in the project (excluding vendor, node_modules, etc.)
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            // Skip directories and non-sync.sh files
            if ($file->isDir() || $file->getFilename() !== 'sync.sh') {
                continue;
            }

            // Skip vendor, node_modules, and hidden directories
            $path = $file->getPathname();
            if (preg_match('#/(vendor|node_modules|\.git|\.)|/\.|^\.|^\.#', $path)) {
                continue;
            }

            // Found sync.sh, parse it
            $config = $syncService->parseSyncShConfig($path);
            if ($config) {
                $config['_sync_sh_path'] = $path; // Store path for later backup/removal
                return $config;
            }
        }

        return null;
    }

    /**
     * Detect the default uploads path using WordPress's wp_upload_dir().
     * Returns a relative path from the project root.
     */
    protected function getDefaultUploadsPath(SyncService $syncService): string
    {
        try {
            // Use WordPress's built-in function to get the uploads directory
            $uploadDir = wp_upload_dir();
            $absoluteUploadPath = $uploadDir['basedir'];

            // Get project root
            $projectRoot = $syncService->getProjectRoot();

            // Convert to relative path
            $relativePath = str_replace($projectRoot . '/', '', $absoluteUploadPath . '/');

            return $relativePath;
        } catch (\Exception $e) {
            // Fallback: use structure detection
            $structure = $syncService->detectProjectStructure();
            return $syncService->getUploadsPathForStructure($structure);
        }
    }

    /**
     * Collect environment data from user input, merging wp-cli.yml and sync.sh config.
     */
    protected function collectEnvironmentData(SyncService $syncService, ?array $wpCliConfig = null, ?array $syncShConfig = null): array
    {
        $this->info('📝 Environment Configuration');
        $this->line('Please provide the following information for each environment:');
        $this->newLine();

        $environments = [];

        // Development environment
        $this->comment('Development Environment:');

        $devUrl = $syncShConfig['development']['url'] ?? env('WP_HOME', 'https://example.test');
        $devUploads = $syncShConfig['development']['uploads_path'] ?? $this->getDefaultUploadsPath($syncService);

        $environments['development'] = [
            'url' => $this->ask('Site URL', $devUrl),
            'uploads_path' => $this->ask('Uploads directory path', $devUploads),
            'wp_cli_alias' => null,
        ];
        $this->newLine();

        // Staging environment
        $this->comment('Staging Environment:');
        $stagingDetected = $wpCliConfig['staging'] ?? null;

        if ($stagingDetected && $this->option('auto')) {
            // Merge wp-cli.yml (SSH/paths) with sync.sh (URLs)
            $environments['staging'] = $this->buildEnvironmentFromWpCli('staging', $stagingDetected, $syncService, $syncShConfig);
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
            $environments['production'] = $this->buildEnvironmentFromWpCli('production', $productionDetected, $syncService, $syncShConfig);
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
     * Build environment configuration from wp-cli.yml data, merging with sync.sh if available.
     */
    protected function buildEnvironmentFromWpCli(string $name, array $wpCliData, SyncService $syncService, ?array $syncShConfig = null): array
    {
        $sshHost = $wpCliData['ssh'] ?? null;
        $remotePath = $wpCliData['path'] ?? null;
        $sshPort = '22';

        // Detect project structure from path hints
        $structure = $syncService->detectProjectStructure($remotePath);
        $wpCorePath = $syncService->getWpCorePathForStructure($structure);

        // Parse rsync-style format: user@host:/remote/path
        if ($sshHost && preg_match('/^([^:]+):(\/.*?)$/', $sshHost, $matches)) {
            // Format is "user@host:/path"
            $sshHost = $matches[1];
            $extractedRemotePath = $matches[2];

            // If we have a WP core path from 'path:' line, strip it from SSH path to get project root
            // Example: /home/user/project/public/wp -> /home/user/project
            if ($remotePath === $wpCorePath) {
                $suffix = '/' . $wpCorePath;
                if (str_ends_with($extractedRemotePath, $suffix)) {
                    $remotePath = substr($extractedRemotePath, 0, -strlen($suffix));
                } else {
                    // Fallback: use extracted path as-is (might already be project root)
                    $remotePath = $extractedRemotePath;
                }
            } elseif (!$remotePath) {
                // No separate path line, use extracted
                $remotePath = $extractedRemotePath;
            }
        }

        // Check for SSH port only if no rsync-style path was found
        // Format would be "user@host:2222" (port) vs "user@host:/path" (rsync)
        if ($sshHost && str_contains($sshHost, ':') && !str_contains($sshHost, ':/')) {
            [$sshHost, $sshPort] = explode(':', $sshHost, 2);
        }

        // Construct uploads path using structure-aware helper
        $uploadsSubPath = $syncService->getUploadsPathForStructure($structure);
        $uploadsPath = $sshHost && $remotePath
            ? $sshHost . ':' . rtrim($remotePath, '/') . '/' . $uploadsSubPath
            : $uploadsSubPath;

        // Merge with sync.sh config if available
        $defaultUrl = $syncShConfig[$name]['url'] ?? "https://{$name}.example.com";
        $defaultUploads = $syncShConfig[$name]['uploads_path'] ?? $uploadsPath;

        return [
            'url' => $this->ask("URL for {$name}", $defaultUrl),
            'uploads_path' => $defaultUploads,
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

        // Generate config content with env() helpers
        $configContent = $this->generateConfigContent($environments);

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
     * Generate configuration file content with env() helpers.
     */
    protected function generateConfigContent(array $environments): string
    {
        $envs = [];
        foreach ($environments as $name => $config) {
            $envPrefix = 'SYNC_' . strtoupper($name);

            $envs[] = "        '{$name}' => [";
            $envs[] = "            'url' => env('{$envPrefix}_URL', '{$config['url']}'),";
            $envs[] = "            'uploads_path' => env('{$envPrefix}_UPLOADS_PATH', '{$config['uploads_path']}'),";
            $envs[] = "            'wp_cli_alias' => " . ($config['wp_cli_alias'] ? "'{$config['wp_cli_alias']}'" : 'null') . ",";

            if (isset($config['ssh_host'])) {
                $envs[] = "            'ssh_host' => env('{$envPrefix}_SSH_HOST', " . ($config['ssh_host'] ? "'{$config['ssh_host']}'" : 'null') . "),";
            }
            if (isset($config['ssh_port'])) {
                $envs[] = "            'ssh_port' => env('{$envPrefix}_SSH_PORT', '{$config['ssh_port']}'),";
            }
            if (isset($config['remote_path'])) {
                $envs[] = "            'remote_path' => env('{$envPrefix}_REMOTE_PATH', " . ($config['remote_path'] ? "'{$config['remote_path']}'" : 'null') . "),";
            }

            $envs[] = "        ],";
        }

        return <<<PHP
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sync Environments
    |--------------------------------------------------------------------------
    |
    | Define your sync environments. Values can be configured via .env file
    | using SYNC_{ENVIRONMENT}_{KEY} format (e.g., SYNC_PRODUCTION_URL).
    |
    */

    'environments' => [
{$this->indent(implode("\n", $envs), 2)}
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Options
    |--------------------------------------------------------------------------
    */

    'options' => [
        'backup_before_sync' => true,
        'confirm_destructive_operations' => true,
        'set_upload_permissions' => true,
        'upload_permissions' => '755',
        'database_charset' => 'utf8mb4',
        'rsync_options' => '-az --progress',
        'ssh_options' => '-o StrictHostKeyChecking=no',
        'enable_slack_notifications' => env('SYNC_SLACK_NOTIFICATIONS', false),
        'slack_webhook_url' => env('SYNC_SLACK_WEBHOOK'),
        'slack_channel' => env('SYNC_SLACK_CHANNEL', '#general'),
    ],

    /*
    |--------------------------------------------------------------------------
    | WP-CLI Configuration
    |--------------------------------------------------------------------------
    */

    'wp_cli' => [
        'config_file' => 'wp-cli.yml',
        'auto_update_aliases' => true,
        'backup_config_before_update' => true,
    ],
];

PHP;
    }

    /**
     * Helper to indent multi-line strings.
     */
    protected function indent(string $text, int $spaces): string
    {
        $indent = str_repeat(' ', $spaces);
        return $indent . str_replace("\n", "\n{$indent}", $text);
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
    protected function updateEnvFile(array $environments, SyncService $syncService): void
    {
        $envPath = $syncService->getProjectRoot() . '/.env';
        $envContent = File::exists($envPath) ? File::get($envPath) : '';

        $envVars = [];
        foreach ($environments as $name => $config) {
            $prefix = 'SYNC_' . strtoupper($name);

            // Add all environment-specific variables
            $envVars["{$prefix}_URL"] = $config['url'];
            $envVars["{$prefix}_UPLOADS_PATH"] = $config['uploads_path'];

            if (isset($config['ssh_host']) && $config['ssh_host']) {
                $envVars["{$prefix}_SSH_HOST"] = $config['ssh_host'];
            }
            if (isset($config['ssh_port']) && $config['ssh_port']) {
                $envVars["{$prefix}_SSH_PORT"] = $config['ssh_port'];
            }
            if (isset($config['remote_path']) && $config['remote_path']) {
                $envVars["{$prefix}_REMOTE_PATH"] = $config['remote_path'];
            }
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
