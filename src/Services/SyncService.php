<?php

namespace Vdrnn\AcornSync\Services;

use Illuminate\Support\Facades\Config;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Exception;

class SyncService
{
    /**
     * Get the project root directory.
     * In Acorn-based projects (Bedrock, Radicle), base_path() returns the theme directory,
     * but we need the project root where wp-cli.yml is located.
     *
     * This method searches upward from base_path() for marker files
     * (wp-cli.yml and web/wp) to find the project root.
     */
    public function getProjectRoot(): string
    {
        $currentPath = base_path();
        $maxLevels = 10; // Prevent infinite loops

        for ($i = 0; $i < $maxLevels; $i++) {
            // Check for Bedrock/Radicle/WordPress project markers
            // Must have BOTH wp-cli.yml AND WordPress core directory
            // Bedrock uses web/wp, Radicle uses public/wp
            // (themes can have wp-cli.yml and composer.json but not the WP core dir)
            if (file_exists($currentPath . '/wp-cli.yml') &&
                (file_exists($currentPath . '/web/wp') || file_exists($currentPath . '/public/wp'))) {
                return $currentPath;
            }

            $parentPath = dirname($currentPath);

            // Stop if we've reached the root or can't go further up
            if ($parentPath === $currentPath || $parentPath === '/') {
                break;
            }

            $currentPath = $parentPath;
        }

        // Fallback: assume standard Bedrock structure (4 levels up)
        return dirname(base_path(), 4);
    }

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
     *
     * With $allowFresh, an environment whose database is reachable but where
     * WordPress is not installed yet (empty database) also counts as valid.
     * That is only safe for a sync TARGET — a fresh source would sync an empty
     * database over the target — so callers must pass it deliberately.
     */
    public function validateEnvironment(string $environment, bool $allowFresh = false): bool
    {
        $config = $this->getEnvironmentConfig($environment);
        $alias = $config['wp_cli_alias'];

        $wp = $alias ? "wp {$alias}" : $this->buildLocalWpCommand();

        if ($this->probe("{$wp} option get home 2>&1")) {
            return true;
        }

        // "option get home" fails on a fresh environment even though SSH and
        // the database are reachable. "db check" probes exactly what a sync
        // target needs — a reachable database — so fall back to it.
        return $allowFresh && $this->probe("{$wp} db check 2>&1");
    }

    /**
     * Run a WP-CLI probe command and report whether it succeeded cleanly.
     *
     * WP-CLI exits non-zero on errors, so the exit code is authoritative.
     * The non-empty check guards against a probe that silently produced
     * nothing (e.g. a misconfigured alias resolving to a no-op).
     */
    protected function probe(string $command): bool
    {
        $process = Process::fromShellCommandline($command);

        // Set working directory to project root so WP-CLI can find wp-cli.yml
        $process->setWorkingDirectory($this->getProjectRoot());

        // Set reasonable timeout for remote connections (2 minutes)
        $process->setTimeout(120);

        try {
            $process->run();

            return $process->getExitCode() === 0 &&
                   trim($process->getOutput()) !== '';
        } catch (\Exception $e) {
            // Process timeout or other exception
            if (function_exists('error_log')) {
                error_log("SyncService::probe exception: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Sync database between environments.
     */
    public function syncDatabase(string $from, string $to, bool $useLocal = false): bool
    {
        $this->assertMatchingTablePrefixes($from, $to, $useLocal);

        if (Config::get('sync.options.backup_before_sync', true)) {
            $backupsDir = $this->getProjectRoot() . '/backups';
            if (!is_dir($backupsDir)) {
                mkdir($backupsDir, 0755, true);
            }
        }

        foreach ($this->getDatabaseSyncCommands($from, $to, $useLocal) as $label => $command) {
            $process = Process::fromShellCommandline($command);
            $process->setWorkingDirectory($this->getProjectRoot());

            // No timeout: database dumps routinely exceed Symfony's 60s
            // default, and a timeout after "db reset" would strand the target
            // half-synced (same reasoning as the rsync fix in 6477e2f).
            $process->setTimeout(null);

            $process->run();

            if (!$process->isSuccessful()) {
                throw new Exception("{$label} failed: " . $process->getErrorOutput());
            }
        }

        return true;
    }

    /**
     * Build the ordered list of commands a database sync would execute.
     *
     * Shared by the real sync and --dry-run so the preview can never drift
     * from what actually runs.
     */
    public function getDatabaseSyncCommands(string $from, string $to, bool $useLocal = false): array
    {
        $fromConfig = $this->getEnvironmentConfig($from);
        $toConfig = $this->getEnvironmentConfig($to);
        $charset = Config::get('sync.options.database_charset', 'utf8mb4');

        // Determine WP-CLI commands based on local flag and environment
        $fromCmd = $this->getWpCliCommand($from, $useLocal);
        $toCmd = $this->getWpCliCommand($to, $useLocal);

        $commands = [];

        if (Config::get('sync.options.backup_before_sync', true)) {
            // Streamed to a LOCAL file: a dump left on the host that is about
            // to be reset — or inside a rotating deploy release — is not a
            // safety net.
            $backupFile = 'backups/' . $to . '-db-backup-' . date('Y-m-d-His') . '.sql';
            $commands["Backup of {$to} database to {$backupFile}"] =
                "{$toCmd} db export --default-character-set={$charset} - > {$backupFile}";
        }

        $commands["Reset of {$to} database"] = "{$toCmd} db reset --yes";

        // Run the pipeline under bash with pipefail: /bin/sh reports only the
        // LAST command's status, so a failed source export would otherwise
        // "succeed" into an empty import right after the target was reset.
        // The charset is forced on BOTH ends (db import passes it through to
        // the mysql client) so export and import cannot disagree.
        $pipeline = "set -o pipefail; {$fromCmd} db export --default-character-set={$charset} - | {$toCmd} db import - --default-character-set={$charset}";
        $commands["Import of {$from} database into {$to}"] = 'bash -c ' . escapeshellarg($pipeline);

        // guid is a permanent post identifier consumed by feed readers —
        // WP-CLI's own URL-migration recipe skips it.
        foreach ($this->getUrlReplacements($fromConfig['url'], $toConfig['url']) as $label => $pair) {
            $commands[$label] =
                "{$toCmd} search-replace \"{$pair[0]}\" \"{$pair[1]}\" --skip-columns=guid --all-tables-with-prefix";
        }

        return $commands;
    }

    /**
     * Abort when source and target use different table prefixes: the imported
     * tables would carry the source's prefix while search-replace
     * --all-tables-with-prefix resolves the target's, silently rewriting
     * nothing.
     */
    protected function assertMatchingTablePrefixes(string $from, string $to, bool $useLocal = false): void
    {
        $fromPrefix = $this->getTablePrefix($this->getWpCliCommand($from, $useLocal));
        $toPrefix = $this->getTablePrefix($this->getWpCliCommand($to, $useLocal));

        if ($fromPrefix === null || $toPrefix === null) {
            return; // Unable to determine — don't block the sync on a probe failure
        }

        if ($fromPrefix !== $toPrefix) {
            throw new Exception(
                "Table prefix mismatch: {$from} uses '{$fromPrefix}' but {$to} uses '{$toPrefix}' — " .
                "after import, the URL rewrite (--all-tables-with-prefix) would silently miss every imported table."
            );
        }
    }

    /**
     * The environment's table prefix via `wp db prefix`, or null when the
     * probe fails.
     */
    protected function getTablePrefix(string $wpCommand): ?string
    {
        $process = Process::fromShellCommandline("{$wpCommand} db prefix 2>/dev/null");
        $process->setWorkingDirectory($this->getProjectRoot());
        $process->setTimeout(120);
        $process->run();

        $prefix = trim($process->getOutput());

        return $process->isSuccessful() && $prefix !== '' ? $prefix : null;
    }

    /**
     * Build the ordered search/replace pairs for a URL migration.
     *
     * Beyond the exact URL, content stores JSON-escaped URLs (Gutenberg block
     * attributes — search-replace unserializes PHP but does not unescape
     * JSON), alternate-scheme absolute URLs, and protocol-relative ones. The
     * scheme-ful passes run first so the final protocol-relative pass only
     * sees what they left behind.
     */
    public function getUrlReplacements(string $fromUrl, string $toUrl): array
    {
        $pairs = [
            "URL rewrite {$fromUrl} → {$toUrl}" => [$fromUrl, $toUrl],
            'URL rewrite (JSON-escaped)' => [str_replace('/', '\/', $fromUrl), str_replace('/', '\/', $toUrl)],
        ];

        $fromAuthority = $this->urlAuthority($fromUrl);
        $toAuthority = $this->urlAuthority($toUrl);

        if ($fromAuthority !== null && $toAuthority !== null) {
            $altScheme = (parse_url($fromUrl, PHP_URL_SCHEME) ?: 'https') === 'https' ? 'http' : 'https';
            $toScheme = parse_url($toUrl, PHP_URL_SCHEME) ?: 'https';

            // Replace authority-for-authority, NOT with the full target URL:
            // on a subdirectory install (https://ex.com/blog) the full URL
            // would double the path (…/blog/blog/…).
            $pairs["URL rewrite ({$altScheme}:// variant)"] = ["{$altScheme}://{$fromAuthority}", "{$toScheme}://{$toAuthority}"];
            $pairs['URL rewrite (protocol-relative)'] = ["//{$fromAuthority}", "//{$toAuthority}"];
        }

        // Identical pairs would churn every table for nothing — drop them.
        return array_filter($pairs, fn ($pair) => $pair[0] !== $pair[1]);
    }

    /**
     * host[:port] of a URL, or null when it has no parsable host.
     */
    protected function urlAuthority(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!$host) {
            return null;
        }

        $port = parse_url($url, PHP_URL_PORT);

        return $host . ($port ? ":{$port}" : '');
    }

    /**
     * Sync assets between environments.
     */
    public function syncAssets(string $from, string $to, bool $setPermissions = true): bool
    {
        if ($setPermissions && ($permissions = $this->getPermissionsCommand($from, $to))) {
            // Best-effort, as before — a chmod failure should not abort the sync
            $process = Process::fromShellCommandline($permissions);
            $process->setWorkingDirectory($this->getProjectRoot());
            $process->run();
        }

        return $this->runAssetsCommand($this->getAssetsSyncCommand($from, $to));
    }

    /**
     * Build the permissions command an assets sync would run, or null when no
     * chmod applies to this sync. Shared by the real sync and --dry-run.
     */
    public function getPermissionsCommand(string $from, string $to): ?string
    {
        if (!Config::get('sync.options.set_upload_permissions', true)) {
            return null;
        }

        // rsync -a preserves source modes, so fixing the local tree only
        // matters when it IS the source ("up"). On a down or horizontal sync
        // the chmod would be overwritten or irrelevant.
        if ($this->getSyncDirection($from, $to) !== 'up') {
            return null;
        }

        $path = $this->getUploadsPathForStructure($this->detectProjectStructure());
        $dirPermissions = Config::get('sync.options.upload_permissions', '755');

        // Directories {$dirPermissions}, files 644 — a blanket recursive chmod
        // would make every uploaded file executable.
        return "find {$path} -type d -exec chmod {$dirPermissions} {} + && find {$path} -type f -exec chmod 644 {} +";
    }

    /**
     * Build the rsync command an assets sync would execute.
     *
     * Shared by the real sync and --dry-run so the preview can never drift
     * from what actually runs. With $dryRun, rsync gets --dry-run --stats
     * (and loses --progress) so it reports what would transfer without
     * writing anything.
     */
    public function getAssetsSyncCommand(string $from, string $to, bool $dryRun = false): string
    {
        $fromConfig = $this->getEnvironmentConfig($from);
        $toConfig = $this->getEnvironmentConfig($to);

        if ($this->getSyncDirection($from, $to) === 'horizontal') {
            return $this->buildHorizontalSyncCommand($fromConfig, $toConfig, $dryRun);
        }

        $rsyncOptions = Config::get('sync.options.rsync_options', '-az --progress');
        if ($dryRun) {
            $rsyncOptions = trim(str_replace('--progress', '', $rsyncOptions)) . ' --dry-run --stats';
        }

        return $this->buildDirectSyncCommand($fromConfig, $toConfig, $rsyncOptions);
    }

    /**
     * Run the assets sync as an rsync dry run.
     *
     * @return array{success: bool, output: string} the transfer report and
     *         whether rsync itself succeeded — a failed preview must not be
     *         mistaken for a clean one.
     */
    public function dryRunAssets(string $from, string $to): array
    {
        $output = '';

        $success = $this->runAssetsCommand(
            $this->getAssetsSyncCommand($from, $to, true),
            function ($type, $buffer) use (&$output) {
                $output .= $buffer;
            }
        );

        return ['success' => $success, 'output' => $output];
    }

    /**
     * Build the horizontal sync command (server to server).
     */
    protected function buildHorizontalSyncCommand(array $fromConfig, array $toConfig, bool $dryRun = false): string
    {
        $fromParts = $this->parseRemotePath($fromConfig['uploads_path']);
        $toParts = $this->parseRemotePath($toConfig['uploads_path']);
        $sshOptions = Config::get('sync.options.ssh_options', '-o StrictHostKeyChecking=no');

        // Add SSH port support
        $fromPort = $fromConfig['ssh_port'] ?? '22';
        $toPort = $toConfig['ssh_port'] ?? '22';
        $fromSshPort = $fromPort !== '22' ? "-p {$fromPort}" : '';
        $toSshPort = $toPort !== '22' ? "-p {$toPort}" : '';

        $rsyncFlags = $dryRun ? '--dry-run --stats' : '--progress';

        return "ssh {$fromSshPort} -o ForwardAgent=yes {$fromParts['host']} \"rsync -aze 'ssh {$sshOptions} {$toSshPort}' {$rsyncFlags} {$fromParts['path']} {$toParts['host']}:{$toParts['path']}\"";
    }

    /**
     * Build the direct sync command (local to remote or remote to local).
     */
    protected function buildDirectSyncCommand(array $fromConfig, array $toConfig, string $rsyncOptions): string
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

        return "rsync {$rsyncOptions} \"{$fromPath}\" \"{$toPath}\"";
    }

    /**
     * Execute a (possibly long-running) assets command.
     */
    protected function runAssetsCommand(string $command, ?callable $output = null): bool
    {
        $process = Process::fromShellCommandline($command);
        $process->setWorkingDirectory($this->getProjectRoot());
        $process->setTimeout(null); // No timeout for large file transfers

        // Run with output callback to prevent pipe blocking on large transfers
        $process->run($output ?? function ($type, $buffer) {
            // Consume output to prevent pipe blocking
        });

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
            return $this->buildLocalWpCommand();
        }

        $config = $this->getEnvironmentConfig($environment);
        $alias = $config['wp_cli_alias'];

        return $alias ? "wp \"{$alias}\"" : $this->buildLocalWpCommand();
    }

    /**
     * Build a wp command that runs in a clean environment.
     *
     * Nested wp-cli calls (wp acorn -> wp option get) fail on some setups
     * because the parent process inherits env vars that poison the child's
     * DB connection. Stripping env with `env -i` while preserving PATH + HOME
     * gives the child a clean slate so it can re-bootstrap WordPress.
     */
    protected function buildLocalWpCommand(): string
    {
        $path = escapeshellarg(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin');
        $home = escapeshellarg(getenv('HOME') ?: '/tmp');

        return "env -i PATH={$path} HOME={$home} wp";
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
        $process->setWorkingDirectory($this->getProjectRoot());
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : 'Unknown';
    }

    /**
     * Update wp-cli.yml with environment aliases.
     */
    public function updateWpCliConfig(array $environments): bool
    {
        // Always use the project root wp-cli.yml, not theme folder
        $wpCliPath = $this->getProjectRoot() . '/wp-cli.yml';

        // Backup existing config if enabled
        if (Config::get('sync.wp_cli.backup_config_before_update', true) && file_exists($wpCliPath)) {
            copy($wpCliPath, $wpCliPath . '.backup.' . date('Y-m-d-H-i-s'));
        }

        // Read existing config
        if (file_exists($wpCliPath)) {
            $content = file_get_contents($wpCliPath);
            // Fix unquoted @ symbols at the start of lines (common in wp-cli.yml)
            $content = preg_replace('/^(@[a-zA-Z0-9_-]+):/m', '"$1":', $content);
            $config = Yaml::parse($content);
        } else {
            $config = [];
        }

        // Detect project structure (Bedrock uses web/wp, Radicle uses public/wp)
        $wpPath = $this->getWpCorePath();

        // Add development alias if not exists
        if (!isset($config['@development'])) {
            $config['@development'] = [
                'path' => $wpPath,
            ];
        }

        // Add aliases for remote environments
        foreach ($environments as $name => $envConfig) {
            if ($envConfig['wp_cli_alias'] && isset($envConfig['ssh_host'], $envConfig['remote_path'])) {
                // WP-CLI format: ssh line has base path, path is relative
                $sshWithPath = $envConfig['ssh_host'] . ':' . $envConfig['remote_path'];

                $config[$envConfig['wp_cli_alias']] = [
                    'ssh' => $sshWithPath,
                    'path' => $wpPath,  // Use local structure detection for relative path
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
     * Detect project structure type (Bedrock or Radicle).
     *
     * @param string|null $pathHint Optional path hint for remote structure detection
     * @return string 'bedrock' or 'radicle'
     */
    public function detectProjectStructure(?string $pathHint = null): string
    {
        // If path hint provided (e.g., from remote wp-cli.yml), use it
        if ($pathHint) {
            // Check for Radicle patterns
            if (str_contains($pathHint, '/public/wp') || $pathHint === 'public/wp') {
                return 'radicle';
            }
            // Check for Bedrock patterns
            if (str_contains($pathHint, '/web/wp') || $pathHint === 'web/wp') {
                return 'bedrock';
            }
        }

        // Check local project structure
        $projectRoot = $this->getProjectRoot();
        if (file_exists($projectRoot . '/public/wp')) {
            return 'radicle';
        } elseif (file_exists($projectRoot . '/web/wp')) {
            return 'bedrock';
        }

        // Default to Bedrock structure
        return 'bedrock';
    }

    /**
     * Get WordPress core path for a given structure.
     */
    public function getWpCorePathForStructure(string $structure): string
    {
        return $structure === 'radicle' ? 'public/wp' : 'web/wp';
    }

    /**
     * Get uploads directory path for a given structure.
     */
    public function getUploadsPathForStructure(string $structure): string
    {
        return $structure === 'radicle' ? 'public/content/uploads/' : 'web/app/uploads/';
    }

    /**
     * Get the WordPress core path relative to project root.
     * Returns 'web/wp' for Bedrock or 'public/wp' for Radicle.
     */
    protected function getWpCorePath(): string
    {
        $structure = $this->detectProjectStructure();
        return $this->getWpCorePathForStructure($structure);
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

    /**
     * Parse sync.sh bash script to extract environment configuration.
     *
     * @param string $syncShPath Path to sync.sh file
     * @return array|null Parsed configuration or null if file doesn't exist
     */
    public function parseSyncShConfig(string $syncShPath): ?array
    {
        if (!file_exists($syncShPath)) {
            return null;
        }

        $content = file_get_contents($syncShPath);
        $config = [
            'development' => [],
            'staging' => [],
            'production' => [],
        ];

        // Parse development environment
        if (preg_match('/DEVDIR="([^"]+)"/', $content, $matches)) {
            $config['development']['uploads_path'] = $matches[1];
        }
        if (preg_match('/DEVSITE="([^"]+)"/', $content, $matches)) {
            $config['development']['url'] = $matches[1];
        }

        // Parse staging environment
        if (preg_match('/STAGDIR="([^"]+)"/', $content, $matches)) {
            $config['staging']['uploads_path'] = $matches[1];
        }
        if (preg_match('/STAGSITE="([^"]+)"/', $content, $matches)) {
            $config['staging']['url'] = $matches[1];
        }

        // Parse production environment
        if (preg_match('/PRODDIR="([^"]+)"/', $content, $matches)) {
            $config['production']['uploads_path'] = $matches[1];
        }
        if (preg_match('/PRODSITE="([^"]+)"/', $content, $matches)) {
            $config['production']['url'] = $matches[1];
        }

        // Parse Slack webhook (optional)
        if (preg_match('/https:\/\/hooks\.slack\.com\/services\/[^\s"\']+/', $content, $matches)) {
            $config['slack_webhook'] = $matches[0];
        }

        return $config;
    }
}
