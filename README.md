# Acorn Sync

WordPress environment synchronization for Bedrock and Radicle projects. Sync databases and uploads between development, staging, and production environments via WP-CLI.

> **Note**: This package is heavily inspired by and based on the original [roots/sync-script](https://github.com/roots/sync-script/) bash script, reimplemented as an Acorn package with Laravel-style configuration and improved cross-platform support.

## Features

- 🔄 **Database sync** - Export, import, and search-replace URLs between environments
- 📁 **Uploads sync** - Transfer media files via rsync with progress indicators
- 🌐 **Multi-environment** - Manage development, staging, and production setups
- ⚙️ **WP-CLI integration** - Auto-manages aliases and remote command execution
- 🤖 **Auto-detection** - Reads existing wp-cli.yml configuration
- 🔌 **SSH port support** - Works with custom ports (Kinsta, managed hosting)
- 📊 **Status monitoring** - Check environment connectivity before syncing
- 🎛️ **Configuration CLI** - View and edit settings via command line
- 🔔 **Slack notifications** - Optional sync completion alerts
- 🛡️ **Safety first** - Confirmation prompts and automatic backups
- 🌊 **Local dev friendly** - Compatible with Valet, DDEV, and similar tools

## Installation

Install via Composer:

```bash
composer require vdrnn/acorn-sync
```

Clear Acorn cache to register the commands:

```bash
wp acorn optimize:clear
```

## Quick Start

1. **Initialize the package:**
   ```bash
   wp acorn sync:init --auto
   ```
   This will automatically detect your wp-cli.yml and set up environments.

2. **Review and adjust paths (if needed):**
   Edit `.env` to fix uploads paths for your specific deployment (see [Setup Workflow](#setup-workflow)).

3. **Check environment connectivity:**
   ```bash
   wp acorn sync:status
   ```

4. **Sync from production to development:**
   ```bash
   wp acorn sync:env production development
   ```

## Setup Workflow

### Recommended Process

1. **Ensure `wp-cli.yml` is configured** (for WP-CLI remote access):
   ```yaml
   @production:
     ssh: 'user@host:/path/to/project'
     path: web/wp
   ```

2. **Run auto-detection**:
   ```bash
   wp acorn sync:init --force --auto
   ```
   This will:
   - ✅ Read your `wp-cli.yml` and detect all environments
   - ✅ Generate `config/sync.php` with `env()` helpers
   - ✅ Populate `.env` with auto-detected paths
   - ✅ Update `wp-cli.yml` with aliases (if needed)

3. **Review generated `.env` file**:
   ```bash
   # Check the generated paths
   grep "SYNC_" .env
   ```

4. **Adjust uploads paths for your deployment**:

   **Standard structure** (auto-detected):
   ```env
   SYNC_PRODUCTION_UPLOADS_PATH="user@host:/path/to/project/web/app/uploads/"
   ```

   **Ploi/Trellis deployments** (need manual update):
   ```env
   # Update to shared directory
   SYNC_PRODUCTION_UPLOADS_PATH="user@host:/path/to/project-shared/uploads/"
   ```

5. **Verify connectivity**:
   ```bash
   wp acorn sync:status
   ```

### Key Points

- **wp-cli.yml** = WP-CLI configuration (SSH access to WordPress)
- **.env** = Sync package configuration (uploads paths, URLs, etc.)
- **Auto-detection gives a good starting point** - fine-tune in `.env` for your deployment
- **No need to manually edit wp-cli.yml** - `sync:init` handles it
- **Different deployments use different paths**:
  - Standard: `/project/web/app/uploads/`
  - Ploi: `/project-shared/uploads/`
  - Trellis: `/project/shared/uploads/`
  - Custom hosting: Adjust based on your structure

### Why Edit .env?

Auto-detection assumes standard Bedrock structure, but many hosting providers (Ploi, Trellis) use shared directories for uploads that persist across deployments. The `.env` file lets you override these paths without modifying committed config files.

## Commands

### `sync:init`
Initialize Acorn Sync configuration with interactive prompts.

```bash
wp acorn sync:init [--force] [--auto]
```

**Options:**
- `--force` - Overwrite existing configuration
- `--auto` - Auto-detect configuration from existing wp-cli.yml

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
            'url' => env('SYNC_DEVELOPMENT_URL', 'https://example.test'),
            'uploads_path' => env('SYNC_DEVELOPMENT_UPLOADS_PATH', 'web/app/uploads/'),
            'wp_cli_alias' => null, // Local environment
        ],
        'staging' => [
            'url' => env('SYNC_STAGING_URL', 'https://staging.example.com'),
            'uploads_path' => env('SYNC_STAGING_UPLOADS_PATH', 'web@staging.example.com:/srv/www/example.com/shared/uploads/'),
            'wp_cli_alias' => '@staging',
            'ssh_host' => env('SYNC_STAGING_SSH_HOST', 'web@staging.example.com'),
            'ssh_port' => env('SYNC_STAGING_SSH_PORT', '22'),
            'remote_path' => env('SYNC_STAGING_REMOTE_PATH', '/srv/www/example.com/current'),
        ],
        'production' => [
            'url' => env('SYNC_PRODUCTION_URL', 'https://example.com'),
            'uploads_path' => env('SYNC_PRODUCTION_UPLOADS_PATH', 'web@example.com:/srv/www/example.com/shared/uploads/'),
            'wp_cli_alias' => '@production',
            'ssh_host' => env('SYNC_PRODUCTION_SSH_HOST', 'web@example.com'),
            'ssh_port' => env('SYNC_PRODUCTION_SSH_PORT', '22'),
            'remote_path' => env('SYNC_PRODUCTION_REMOTE_PATH', '/srv/www/example.com/current'),
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
        'enable_slack_notifications' => env('SYNC_SLACK_NOTIFICATIONS', false),
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

### Configuration Flexibility

The package supports two complementary approaches for configuration:

1. **Environment Variables (.env)** - Values in `.env` override config defaults
2. **Config File (config/sync.php)** - Provides fallback defaults using `env()` helpers

This gives you flexibility to:
- Store sensitive data (SSH hosts, ports, paths) in `.env` (gitignored)
- Keep sensible defaults in `config/sync.php` (committed to repo)
- Share configuration across teams while allowing local overrides

The `sync:init` command automatically populates both files for you.

## Environment Variables

Add these to your `.env` file:

```env
# Development
SYNC_DEVELOPMENT_URL="https://example.test"
SYNC_DEVELOPMENT_UPLOADS_PATH="web/app/uploads/"

# Staging
SYNC_STAGING_URL="https://staging.example.com"
SYNC_STAGING_UPLOADS_PATH="web@staging.example.com:/srv/www/example.com/shared/uploads/"
SYNC_STAGING_SSH_HOST="web@staging.example.com"
SYNC_STAGING_SSH_PORT="22"
SYNC_STAGING_REMOTE_PATH="/srv/www/example.com/current"

# Production
SYNC_PRODUCTION_URL="https://example.com"
SYNC_PRODUCTION_UPLOADS_PATH="web@example.com:/srv/www/example.com/shared/uploads/"
SYNC_PRODUCTION_SSH_HOST="web@example.com"
SYNC_PRODUCTION_SSH_PORT="22"
SYNC_PRODUCTION_REMOTE_PATH="/srv/www/example.com/current"

# Optional: Slack notifications
SYNC_SLACK_NOTIFICATIONS=false
SYNC_SLACK_WEBHOOK="https://hooks.slack.com/services/..."
SYNC_SLACK_CHANNEL="#general"
```

> **Note**: The `sync:init` command automatically populates these variables for you based on your input or detected wp-cli.yml configuration.

## WP-CLI Configuration

The package can automatically update your `wp-cli.yml` file with remote aliases:

```yaml
@staging:
  ssh: web@staging.example.com:/srv/www/example.com/current
  path: web/wp

@production:
  ssh: web@example.com:/srv/www/example.com/current
  path: web/wp
```

> **Note**: The package automatically handles various wp-cli.yml formats, including unquoted @ symbols (common in Roots examples) and both absolute and rsync-style SSH paths. Your existing wp-cli.yml will work as-is and the package will detect all configured environments.

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

1. **"Empty string returned" or connectivity issues with development environment**
   - **Fixed in latest version!** This was caused by WP-CLI commands not running in the correct directory
   - If you're experiencing this, update to the latest version
   - Works with Laravel Valet, DDEV, and other local development environments

2. **WP-CLI connection errors**
   - Verify SSH keys are properly configured
   - Check WP-CLI aliases in `wp-cli.yml`
   - Test manual WP-CLI commands: `wp @staging option get home`

3. **rsync permission errors**
   - Ensure proper SSH access to remote servers
   - Check file permissions on uploads directories
   - Verify rsync is installed on all servers

4. **Custom SSH ports (Kinsta, managed hosting)**
   - The package now supports custom SSH ports natively
   - During `sync:init`, you'll be prompted for the SSH port
   - Default is 22, but you can specify any port (e.g., 12345 for Kinsta)

5. **Database sync failures**
   - Check database credentials and connectivity
   - Ensure sufficient disk space for database exports
   - Verify character set compatibility

### Debug Mode

For detailed debugging information during status checks:

```bash
# Use the --debug flag for detailed connectivity diagnostics
wp acorn sync:status --debug

# Use -v flag for verbose sync output
wp acorn sync:env production development -v
```

The `--debug` flag shows:
- Detailed error messages
- WP-CLI command outputs
- Manual commands to try for troubleshooting
- Stack traces for exceptions

### Compatibility

**Tested & Confirmed:**
- ✅ **Bedrock** - Fully tested
- ✅ **Radicle** - Fully tested
- ✅ **Laravel Valet** - Fully tested
- ✅ **Ploi** - Fully tested (shared uploads deployments)

**Should Work (untested):**
- ⚠️ **DDEV** - Standard structure, should work
- ⚠️ **Trellis** - Similar to Ploi (shared uploads pattern)
- ⚠️ **Kinsta** - Has SSH port support, needs validation
- ⚠️ **WP Engine** - Standard structure, should work
- ⚠️ **Flywheel** - Standard structure, should work

## Roadmap

Planned features for future releases:

- Kinsta hosting validation & testing
- Multisite support
- Selective table sync
- Dry-run mode
- Sync progress notifications
- Pre/post sync hooks

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).

## Credits

Based on the original sync script by [Ben Word](https://github.com/retlehs) and adapted for Acorn by [Vedran](https://github.com/vdrnn).
