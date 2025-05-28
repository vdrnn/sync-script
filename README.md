# Acorn Sync

A powerful WordPress environment synchronization package for Acorn (Laravel + WordPress). Easily sync databases and assets between development, staging, and production environments with a simple command-line interface.

## Features

- 🔄 **Database Synchronization** - Export, import, and search-replace URLs automatically
- 📁 **Asset Synchronization** - Sync uploads directories via rsync with progress indicators
- 🌐 **Multi-Environment Support** - Development, staging, and production environments
- ⚙️ **WP-CLI Integration** - Automatic alias management and remote command execution
- 🔧 **Interactive Setup** - Easy configuration with `sync:init` command
- 📊 **Status Monitoring** - Check environment connectivity with `sync:status`
- 🎛️ **Configuration Management** - Edit settings with `sync:config`
- 🔔 **Slack Notifications** - Optional notifications for sync operations
- 🛡️ **Safety Features** - Confirmation prompts and backup creation

## Installation

Install via Composer:

```bash
composer require vdrnn/acorn-sync
```

Publish the configuration file:

```bash
wp acorn vendor:publish --tag="acorn-sync"
```

## Quick Start

1. **Initialize the package:**
   ```bash
   wp acorn sync:init
   ```
   This will guide you through setting up your environments interactively.

2. **Check environment connectivity:**
   ```bash
   wp acorn sync:status
   ```

3. **Sync from production to development:**
   ```bash
   wp acorn sync:env production development
   ```

## Commands

### `sync:init`
Initialize Acorn Sync configuration with interactive prompts.

```bash
wp acorn sync:init [--force]
```

**Options:**
- `--force` - Overwrite existing configuration

### `sync:env`
Sync data between WordPress environments.

```bash
wp acorn sync:env {from} {to} [options]
```

**Arguments:**
- `from` - Source environment (development, staging, production)
- `to` - Target environment (development, staging, production)

**Options:**
- `--skip-db` - Skip database synchronization
- `--skip-assets` - Skip assets synchronization
- `--local` - Use local WP-CLI for development environment
- `--no-slack` - Skip Slack notification
- `--no-permissions` - Skip setting upload permissions
- `--force` - Skip confirmation prompts

**Examples:**
```bash
# Sync everything from production to development
wp acorn sync:env production development

# Sync only database from staging to development
wp acorn sync:env staging development --skip-assets

# Sync only assets from development to staging
wp acorn sync:env development staging --skip-db

# Force sync without confirmation
wp acorn sync:env production development --force
```

### `sync:status`
Check environment connectivity and configuration status.

```bash
wp acorn sync:status [environment]
```

**Examples:**
```bash
# Check all environments
wp acorn sync:status

# Check specific environment
wp acorn sync:status production
```

### `sync:config`
Manage Acorn Sync configuration.

```bash
wp acorn sync:config [action] [--environment=]
```

**Actions:**
- `show` - Display current configuration (default)
- `edit` - Edit configuration interactively
- `reset` - Reset configuration to defaults

**Examples:**
```bash
# Show all configuration
wp acorn sync:config

# Show specific environment configuration
wp acorn sync:config show --environment=production

# Edit configuration interactively
wp acorn sync:config edit

# Reset configuration
wp acorn sync:config reset
```

## Configuration

The package uses a configuration file at `config/sync.php`. Here's an example:

```php
<?php

return [
    'environments' => [
        'development' => [
            'url' => 'https://example.test',
            'uploads_path' => 'web/app/uploads/',
            'wp_cli_alias' => null, // Local environment
        ],
        'staging' => [
            'url' => 'https://staging.example.com',
            'uploads_path' => 'web@staging.example.com:/srv/www/example.com/shared/uploads/',
            'wp_cli_alias' => '@staging',
            'ssh_host' => 'web@staging.example.com',
            'remote_path' => '/srv/www/example.com/current',
        ],
        'production' => [
            'url' => 'https://example.com',
            'uploads_path' => 'web@example.com:/srv/www/example.com/shared/uploads/',
            'wp_cli_alias' => '@production',
            'ssh_host' => 'web@example.com',
            'remote_path' => '/srv/www/example.com/current',
        ],
    ],

    'options' => [
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
    ],

    'wp_cli' => [
        'config_file' => 'wp-cli.yml',
        'auto_update_aliases' => true,
        'backup_config_before_update' => true,
    ],
];
```

## Environment Variables

Add these to your `.env` file:

```env
# Development
SYNC_DEVELOPMENT_URL="https://example.test"
SYNC_DEVELOPMENT_UPLOADS_PATH="web/app/uploads/"

# Staging
SYNC_STAGING_URL="https://staging.example.com"
SYNC_STAGING_UPLOADS_PATH="web@staging.example.com:/srv/www/example.com/shared/uploads/"

# Production
SYNC_PRODUCTION_URL="https://example.com"
SYNC_PRODUCTION_UPLOADS_PATH="web@example.com:/srv/www/example.com/shared/uploads/"

# Optional: Slack notifications
SYNC_SLACK_WEBHOOK="https://hooks.slack.com/services/..."
SYNC_SLACK_CHANNEL="#general"
```

## WP-CLI Configuration

The package can automatically update your `wp-cli.yml` file with remote aliases:

```yaml
@staging:
  ssh: web@staging.example.com
  path: /srv/www/example.com/current

@production:
  ssh: web@example.com
  path: /srv/www/example.com/current
```

## Sync Directions

The following sync directions are supported:

- **Down** ⬇️: `production` → `development`, `staging` → `development`
- **Up** ⬆️: `development` → `production`, `development` → `staging`
- **Horizontal** ↔️: `production` ↔ `staging`

## Requirements

- PHP 8.1+
- Roots Acorn 5.0+
- WP-CLI
- rsync (for asset synchronization)
- SSH access to remote servers

## Security Considerations

- Always test sync operations on non-production environments first
- Use SSH keys for passwordless authentication
- Consider using staging environments as intermediaries for production syncs
- Review the sync preview before confirming destructive operations

## Troubleshooting

### Common Issues

1. **WP-CLI connection errors**
   - Verify SSH keys are properly configured
   - Check WP-CLI aliases in `wp-cli.yml`
   - Test manual WP-CLI commands

2. **rsync permission errors**
   - Ensure proper SSH access to remote servers
   - Check file permissions on uploads directories
   - Verify rsync is installed on all servers

3. **Database sync failures**
   - Check database credentials and connectivity
   - Ensure sufficient disk space for database exports
   - Verify character set compatibility

### Debug Mode

Enable verbose output by adding the `-v` flag to any command:

```bash
wp acorn sync:env production development -v
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).

## Credits

Based on the original sync script by [Ben Word](https://github.com/retlehs) and adapted for Acorn by [Vedran](https://github.com/vdrnn).
