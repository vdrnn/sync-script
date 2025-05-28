<?php

namespace Vdrnn\AcornSync\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

class SyncConfigCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'sync:config
                           {action? : Action to perform (show, edit, reset)}
                           {--environment= : Specific environment to configure}';

    /**
     * The console command description.
     */
    protected $description = 'Manage Acorn Sync configuration';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = $this->argument('action') ?? 'show';

        return match($action) {
            'show' => $this->showConfiguration(),
            'edit' => $this->editConfiguration(),
            'reset' => $this->resetConfiguration(),
            default => $this->invalidAction($action),
        };
    }

    /**
     * Show current configuration.
     */
    protected function showConfiguration(): int
    {
        $environment = $this->option('environment');

        if ($environment) {
            return $this->showEnvironmentConfiguration($environment);
        }

        return $this->showAllConfiguration();
    }

    /**
     * Show configuration for a specific environment.
     */
    protected function showEnvironmentConfiguration(string $environment): int
    {
        $environments = Config::get('sync.environments', []);

        if (!isset($environments[$environment])) {
            $this->error("Environment '{$environment}' not found in configuration.");
            $this->line('Available environments: ' . implode(', ', array_keys($environments)));
            return 1;
        }

        $this->info("Configuration for {$environment} environment:");
        $this->newLine();

        $config = $environments[$environment];
        $this->displayConfigArray($config);

        return 0;
    }

    /**
     * Show all configuration.
     */
    protected function showAllConfiguration(): int
    {
        $config = Config::get('sync');

        if (empty($config)) {
            $this->warn('No sync configuration found. Run "wp acorn sync:init" to set up.');
            return 1;
        }

        $this->info('🔧 Acorn Sync Configuration:');
        $this->newLine();

        // Show environments
        if (isset($config['environments'])) {
            $this->comment('Environments:');
            foreach ($config['environments'] as $name => $envConfig) {
                $this->line("  <info>{$name}:</info>");
                $this->line("    URL: <comment>{$envConfig['url']}</comment>");
                $this->line("    Uploads: <comment>{$envConfig['uploads_path']}</comment>");
                if ($envConfig['wp_cli_alias']) {
                    $this->line("    WP-CLI Alias: <comment>{$envConfig['wp_cli_alias']}</comment>");
                }
                $this->newLine();
            }
        }

        // Show options
        if (isset($config['options'])) {
            $this->comment('Options:');
            foreach ($config['options'] as $key => $value) {
                $displayValue = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                $this->line("  {$key}: <comment>{$displayValue}</comment>");
            }
            $this->newLine();
        }

        // Show WP-CLI settings
        if (isset($config['wp_cli'])) {
            $this->comment('WP-CLI Settings:');
            foreach ($config['wp_cli'] as $key => $value) {
                $displayValue = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                $this->line("  {$key}: <comment>{$displayValue}</comment>");
            }
        }

        return 0;
    }

    /**
     * Edit configuration interactively.
     */
    protected function editConfiguration(): int
    {
        $this->info('🔧 Interactive Configuration Editor');
        $this->newLine();

        $configPath = config_path('sync.php');
        if (!File::exists($configPath)) {
            $this->error('Configuration file not found. Run "wp acorn sync:init" first.');
            return 1;
        }

        $config = include $configPath;

        $this->comment('What would you like to edit?');
        $choice = $this->choice('Select an option:', [
            'environments' => 'Environment settings',
            'options' => 'Sync options',
            'wp_cli' => 'WP-CLI settings',
        ]);

        switch ($choice) {
            case 'environments':
                return $this->editEnvironments($config);
            case 'options':
                return $this->editOptions($config);
            case 'wp_cli':
                return $this->editWpCliSettings($config);
        }

        return 0;
    }

    /**
     * Edit environment settings.
     */
    protected function editEnvironments(array &$config): int
    {
        $environments = array_keys($config['environments'] ?? []);

        if (empty($environments)) {
            $this->warn('No environments configured.');
            return 1;
        }

        $environment = $this->choice('Which environment would you like to edit?', $environments);
        $envConfig = &$config['environments'][$environment];

        $this->info("Editing {$environment} environment:");
        $this->newLine();

        $envConfig['url'] = $this->ask('Site URL', $envConfig['url']);
        $envConfig['uploads_path'] = $this->ask('Uploads path', $envConfig['uploads_path']);

        if ($environment !== 'development') {
            $envConfig['wp_cli_alias'] = $this->ask('WP-CLI alias', $envConfig['wp_cli_alias'] ?? "@{$environment}");
        }

        $this->saveConfiguration($config);
        $this->info('✅ Environment configuration updated');

        return 0;
    }

    /**
     * Edit sync options.
     */
    protected function editOptions(array &$config): int
    {
        $this->info('Editing sync options:');
        $this->newLine();

        $options = &$config['options'];

        $options['backup_before_sync'] = $this->confirm(
            'Create backup before sync?',
            $options['backup_before_sync'] ?? true
        );

        $options['confirm_destructive_operations'] = $this->confirm(
            'Confirm destructive operations?',
            $options['confirm_destructive_operations'] ?? true
        );

        $options['set_upload_permissions'] = $this->confirm(
            'Set upload permissions automatically?',
            $options['set_upload_permissions'] ?? true
        );

        if ($options['set_upload_permissions']) {
            $options['upload_permissions'] = $this->ask(
                'Upload permissions',
                $options['upload_permissions'] ?? '755'
            );
        }

        $options['enable_slack_notifications'] = $this->confirm(
            'Enable Slack notifications?',
            $options['enable_slack_notifications'] ?? false
        );

        $this->saveConfiguration($config);
        $this->info('✅ Options updated');

        return 0;
    }

    /**
     * Edit WP-CLI settings.
     */
    protected function editWpCliSettings(array &$config): int
    {
        $this->info('Editing WP-CLI settings:');
        $this->newLine();

        $wpCli = &$config['wp_cli'];

        $wpCli['config_file'] = $this->ask(
            'WP-CLI config file path',
            $wpCli['config_file'] ?? 'wp-cli.yml'
        );

        $wpCli['auto_update_aliases'] = $this->confirm(
            'Auto-update WP-CLI aliases?',
            $wpCli['auto_update_aliases'] ?? true
        );

        $wpCli['backup_config_before_update'] = $this->confirm(
            'Backup config before updating?',
            $wpCli['backup_config_before_update'] ?? true
        );

        $this->saveConfiguration($config);
        $this->info('✅ WP-CLI settings updated');

        return 0;
    }

    /**
     * Reset configuration to defaults.
     */
    protected function resetConfiguration(): int
    {
        if (!$this->confirm('Are you sure you want to reset the configuration? This will remove all current settings.')) {
            $this->info('Reset cancelled.');
            return 0;
        }

        $configPath = config_path('sync.php');

        if (File::exists($configPath)) {
            File::delete($configPath);
            $this->info('✅ Configuration reset. Run "wp acorn sync:init" to reconfigure.');
        } else {
            $this->warn('No configuration file found to reset.');
        }

        return 0;
    }

    /**
     * Handle invalid action.
     */
    protected function invalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}");
        $this->line('Available actions: show, edit, reset');
        return 1;
    }

    /**
     * Display configuration array in a readable format.
     */
    protected function displayConfigArray(array $config, int $indent = 0): void
    {
        $prefix = str_repeat('  ', $indent);

        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $this->line("{$prefix}<info>{$key}:</info>");
                $this->displayConfigArray($value, $indent + 1);
            } else {
                $displayValue = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                $this->line("{$prefix}{$key}: <comment>{$displayValue}</comment>");
            }
        }
    }

    /**
     * Save configuration to file.
     */
    protected function saveConfiguration(array $config): void
    {
        $configPath = config_path('sync.php');
        $configContent = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        File::put($configPath, $configContent);
    }
}
