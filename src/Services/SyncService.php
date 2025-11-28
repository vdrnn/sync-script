<?php

namespace Vdrnn\AcornSync\Services;

use Illuminate\Support\Facades\Config;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Exception;

class SyncService
{
    /**
     * Get environment configuration.
     */
    public function getEnvironmentConfig(string $environment): array
    {
        $environments = Config::get('sync.environments', []);

        if (!isset($environments[$environment])) {
            throw new Exception("Environment '{$environment}' not found in configuration.");
        }

        return $environments[$environment];
    }

    /**
     * Validate environment connectivity.
     */
    public function validateEnvironment(string $environment): bool
    {
        $config = $this->getEnvironmentConfig($environment);
        $alias = $config['wp_cli_alias'];

        if ($alias) {
            $process = Process::fromShellCommandline("wp {$alias} option get home");
        } else {
            $process = Process::fromShellCommandline("wp option get home");
        }

        // Set working directory to base path for local WP-CLI commands
        $process->setWorkingDirectory(base_path());
        $process->run();

        return $process->isSuccessful() && !str_contains($process->getOutput(), 'Error');
    }

    /**
     * Sync database between environments.
     */
    public function syncDatabase(string $from, string $to, bool $useLocal = false): bool
    {
        $fromConfig = $this->getEnvironmentConfig($from);
        $toConfig = $this->getEnvironmentConfig($to);
        $charset = Config::get('sync.options.database_charset', 'utf8mb4');

        // Determine WP-CLI commands based on local flag and environment
        $fromCmd = $this->getWpCliCommand($from, $useLocal);
        $toCmd = $this->getWpCliCommand($to, $useLocal);

        $workingDir = base_path();

        // Export backup of target database
        $backupProcess = Process::fromShellCommandline("{$toCmd} db export --default-character-set={$charset}");
        $backupProcess->setWorkingDirectory($workingDir);
        $backupProcess->run();
        if (!$backupProcess->isSuccessful()) {
            throw new Exception("Failed to backup {$to} database: " . $backupProcess->getErrorOutput());
        }

        // Reset target database
        $resetProcess = Process::fromShellCommandline("{$toCmd} db reset --yes");
        $resetProcess->setWorkingDirectory($workingDir);
        $resetProcess->run();
        if (!$resetProcess->isSuccessful()) {
            throw new Exception("Failed to reset {$to} database: " . $resetProcess->getErrorOutput());
        }

        // Import from source to target
        $importProcess = Process::fromShellCommandline("{$fromCmd} db export --default-character-set={$charset} - | {$toCmd} db import -");
        $importProcess->setWorkingDirectory($workingDir);
        $importProcess->run();
        if (!$importProcess->isSuccessful()) {
            throw new Exception("Failed to import database: " . $importProcess->getErrorOutput());
        }

        // Search and replace URLs
        $searchReplaceProcess = Process::fromShellCommandline("{$toCmd} search-replace \"{$fromConfig['url']}\" \"{$toConfig['url']}\" --all-tables-with-prefix");
        $searchReplaceProcess->setWorkingDirectory($workingDir);
        $searchReplaceProcess->run();
        if (!$searchReplaceProcess->isSuccessful()) {
            throw new Exception("Failed to search-replace URLs: " . $searchReplaceProcess->getErrorOutput());
        }

        return true;
    }

    /**
     * Sync assets between environments.
     */
    public function syncAssets(string $from, string $to): bool
    {
        $fromConfig = $this->getEnvironmentConfig($from);
        $toConfig = $this->getEnvironmentConfig($to);
        $rsyncOptions = Config::get('sync.options.rsync_options', '-az --progress');

        // Set permissions if enabled
        if (Config::get('sync.options.set_upload_permissions', true)) {
            $this->setUploadsPermissions();
        }

        $syncDirection = $this->getSyncDirection($from, $to);

        if ($syncDirection === 'horizontal') {
            return $this->performHorizontalSync($fromConfig, $toConfig, $rsyncOptions);
        } else {
            return $this->performDirectSync($fromConfig, $toConfig, $rsyncOptions);
        }
    }

    /**
     * Set upload directory permissions.
     */
    public function setUploadsPermissions(string $path = 'web/app/uploads/'): bool
    {
        $permissions = Config::get('sync.options.upload_permissions', '755');
        $process = Process::fromShellCommandline("chmod -R {$permissions} {$path}");
        $process->setWorkingDirectory(base_path());
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Perform horizontal sync (server to server).
     */
    protected function performHorizontalSync(array $fromConfig, array $toConfig, string $rsyncOptions): bool
    {
        $fromParts = $this->parseRemotePath($fromConfig['uploads_path']);
        $toParts = $this->parseRemotePath($toConfig['uploads_path']);
        $sshOptions = Config::get('sync.options.ssh_options', '-o StrictHostKeyChecking=no');

        // Add SSH port support
        $fromPort = $fromConfig['ssh_port'] ?? '22';
        $toPort = $toConfig['ssh_port'] ?? '22';
        $fromSshPort = $fromPort !== '22' ? "-p {$fromPort}" : '';
        $toSshPort = $toPort !== '22' ? "-p {$toPort}" : '';

        $command = "ssh {$fromSshPort} -o ForwardAgent=yes {$fromParts['host']} \"rsync -aze 'ssh {$sshOptions} {$toSshPort}' --progress {$fromParts['path']} {$toParts['host']}:{$toParts['path']}\"";

        $process = Process::fromShellCommandline($command);
        $process->setWorkingDirectory(base_path());
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Perform direct sync (local to remote or remote to local).
     */
    protected function performDirectSync(array $fromConfig, array $toConfig, string $rsyncOptions): bool
    {
        $fromPath = $fromConfig['uploads_path'];
        $toPath = $toConfig['uploads_path'];

        // Add SSH port support for rsync
        $sshPort = null;
        if (isset($fromConfig['ssh_port']) && $fromConfig['ssh_port'] !== '22') {
            $sshPort = $fromConfig['ssh_port'];
        } elseif (isset($toConfig['ssh_port']) && $toConfig['ssh_port'] !== '22') {
            $sshPort = $toConfig['ssh_port'];
        }

        if ($sshPort) {
            $rsyncOptions .= " -e 'ssh -p {$sshPort}'";
        }

        $process = Process::fromShellCommandline("rsync {$rsyncOptions} \"{$fromPath}\" \"{$toPath}\"");
        $process->setWorkingDirectory(base_path());
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Parse remote path to extract host and path components.
     */
    protected function parseRemotePath(string $remotePath): array
    {
        if (preg_match('/^(.+):(.+)$/', $remotePath, $matches)) {
            return [
                'host' => $matches[1],
                'path' => $matches[2],
            ];
        }

        return [
            'host' => null,
            'path' => $remotePath,
        ];
    }

    /**
     * Determine sync direction.
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
     * Get appropriate WP-CLI command for environment.
     */
    protected function getWpCliCommand(string $environment, bool $useLocal = false): string
    {
        if ($useLocal && $environment === 'development') {
            return 'wp';
        }

        $config = $this->getEnvironmentConfig($environment);
        $alias = $config['wp_cli_alias'];

        return $alias ? "wp \"{$alias}\"" : 'wp';
    }

    /**
     * Send Slack notification.
     */
    public function sendSlackNotification(string $from, string $to): bool
    {
        if (!Config::get('sync.options.enable_slack_notifications', false)) {
            return true;
        }

        $webhookUrl = Config::get('sync.options.slack_webhook_url');
        $channel = Config::get('sync.options.slack_channel', '#general');

        if (!$webhookUrl) {
            return false;
        }

        $user = $this->getCurrentUser();
        $fromConfig = $this->getEnvironmentConfig($from);
        $toConfig = $this->getEnvironmentConfig($to);

        $payload = [
            'channel' => $channel,
            'attachments' => [
                [
                    'fallback' => '',
                    'color' => '#36a64f',
                    'text' => "🔄 Sync from {$fromConfig['url']} to {$toConfig['url']} by {$user} complete",
                ],
            ],
        ];

        $process = new Process([
            'curl',
            '-X', 'POST',
            '-H', 'Content-type: application/json',
            '--data', json_encode($payload),
            $webhookUrl,
        ]);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Get current user from git config.
     */
    public function getCurrentUser(): string
    {
        $process = Process::fromShellCommandline('git config user.name');
        $process->setWorkingDirectory(base_path());
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : 'Unknown';
    }

    /**
     * Update wp-cli.yml with environment aliases.
     */
    public function updateWpCliConfig(array $environments): bool
    {
        // Always use the Bedrock root wp-cli.yml, not theme folder
        $wpCliPath = base_path('wp-cli.yml');

        // Backup existing config if enabled
        if (Config::get('sync.wp_cli.backup_config_before_update', true) && file_exists($wpCliPath)) {
            copy($wpCliPath, $wpCliPath . '.backup.' . date('Y-m-d-H-i-s'));
        }

        // Read existing config
        $config = file_exists($wpCliPath) ? Yaml::parseFile($wpCliPath) : [];

        // Add development alias if not exists
        if (!isset($config['@development'])) {
            $config['@development'] = [
                'path' => 'web/wp',
            ];
        }

        // Add aliases for remote environments
        foreach ($environments as $name => $envConfig) {
            if ($envConfig['wp_cli_alias'] && isset($envConfig['ssh_host'], $envConfig['remote_path'])) {
                $config[$envConfig['wp_cli_alias']] = [
                    'ssh' => $envConfig['ssh_host'],
                    'path' => $envConfig['remote_path'] . '/web/wp',
                ];
            }
        }

        // Write back to file
        try {
            file_put_contents($wpCliPath, Yaml::dump($config, 4, 2));
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Extract SSH host and remote path from uploads path.
     */
    public function extractRemoteDetails(string $uploadsPath): array
    {
        $parts = $this->parseRemotePath($uploadsPath);

        if ($parts['host']) {
            // Extract base path (remove uploads directory)
            $basePath = dirname($parts['path']);
            if (str_ends_with($basePath, '/shared')) {
                $basePath = dirname($basePath) . '/current';
            }

            return [
                'ssh_host' => $parts['host'],
                'remote_path' => $basePath,
            ];
        }

        return [
            'ssh_host' => null,
            'remote_path' => null,
        ];
    }
}
