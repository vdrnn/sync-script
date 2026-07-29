# Changelog

## [Unreleased]

### Added
- `--dry-run` option for `sync:env`: previews a sync without changing anything. Database commands are listed exactly as they would run (built by the same code path as the real sync), the assets step executes a read-only `rsync --dry-run` with a transfer report, and the preview names the database that a real run would reset. Exits non-zero when the preview itself fails.

### Changed
- The package's default `config/sync.php` now reads the same `SYNC_{ENVIRONMENT}_{KEY}` env names that `sync:init` generates and the README documents (`SYNC_DEVELOPMENT_URL`/`SYNC_PRODUCTION_URL` instead of the outlier `SYNC_DEV_URL`/`SYNC_PROD_URL`). Projects with a published config are unaffected.
- **Breaking:** `--no-slack` and `--no-permissions` are renamed to `--skip-slack` and `--skip-permissions`. The old names never worked under `wp acorn` — WP-CLI consumes the `no-` prefix before the command sees the flag, so both aborted the command.
- The pre-sync backup of the target database now streams to a local file (`backups/{env}-db-backup-{timestamp}.sql` in the project root) instead of being written to the remote host's working directory, where a deploy rotation could discard it. The `backup_before_sync` option is now actually honored.
- Upload permissions are now set once per sync (previously twice), only when the local uploads tree is the sync source, and as directories `755` / files `644` instead of a blanket recursive `755` that made every uploaded file executable. `setUploadsPermissions()` was replaced by `getPermissionsCommand()` + a gated run inside `syncAssets()`.

### Fixed
- The URL rewrite now runs additional passes beyond the exact URL: JSON-escaped URLs (Gutenberg block attributes), the alternate-scheme absolute form, and protocol-relative `//host` occurrences. Scheme-ful passes run first so the protocol-relative pass only sees what they left behind; identical search/replace pairs are dropped.
- `db import` now receives the same `--default-character-set` as the export — the two ends of the pipeline can no longer disagree on charset.
- The sync aborts with a clear message when source and target table prefixes differ (`wp db prefix` probe) — previously `search-replace --all-tables-with-prefix` would have silently rewritten nothing after the import.
- `confirm_destructive_operations` is now honored: setting it to `false` skips the confirmation prompt (the preview still prints). Previously the key was documented but never read.
- Environment connectivity check no longer fails on fresh environments **used as the sync target**: when `option get home` reports WordPress as not installed (empty database), validation falls back to `db check`. A sync *source* must be a working install — previously the fallback also let an empty source pass, which would have synced an empty database over the target. Genuinely unreachable hosts still fail.
- The database import pipeline now runs under `bash -o pipefail`: a failed source export aborts the sync instead of "succeeding" into an empty import right after the target was reset.
- Database sync commands no longer inherit Symfony Process's 60-second timeout, which could fire mid-import after the target had already been reset (same fix rsync received earlier).
- `search-replace` now passes `--skip-columns=guid`, per WP-CLI's own URL-migration guidance.
- Connectivity probes now trust WP-CLI's exit code instead of scanning output for the substring "error", which produced false failures when a table name contained "error".
- `sync:status` prints "Connectivity:" and its result on one line, and its `--debug` hint notes that local commands run through the tool's clean-environment wrapper.

## [2.2.0] - 2025-11-28

### Fixed
- **Critical**: Fixed Radicle path duplication bug - remote paths now correctly point to project root instead of WP core directory
- **Critical**: Improved wp-cli.yml parsing to handle unquoted @ symbols (common in Roots documentation)
- Fixed rsync-style path parsing (user@host:/path) vs port notation (user@host:22)
- Enhanced project root detection with dynamic upward search algorithm
- wp-cli.yml generation now writes correct format that WP-CLI can parse
- Removed redundant shell commands for better performance and reliability

### Added
- **Laravel-style configuration** - Config now uses `env()` helpers with fallback defaults
- **Automatic sync.sh migration** - Intelligently migrates from bash script to Laravel configuration
  - Recursively searches project for sync.sh file
  - Parses URLs, uploads paths, and Slack webhooks from bash variables
  - Merges with wp-cli.yml SSH configuration for best of both
  - Safely backs up original sync.sh to sync.sh.backup
- WordPress native function integration - Uses `wp_upload_dir()` for better compatibility
- SSH connection details (host, port, remote_path) now written to `.env` file
- `--debug` flag for `sync:status` command with detailed error reporting
- Better auto-detection from existing wp-cli.yml (now detects all environments, not just @development)
- Configuration flexibility documentation explaining .env vs config/sync.php usage

### Changed
- Config structure now reads from `.env` with `env()` helpers (auto-migrated by sync:init)
- Improved project root detection with smarter fallback logic
- Enhanced environment detection during `sync:init` command
- Better error messages showing exact WP-CLI commands for manual testing
- Updated documentation with complete environment variable list

### Improved
- Configuration flexibility - Users can choose between .env or config/sync.php
- Cross-platform uploads path detection respects WordPress filters
- More robust YAML parsing handles edge cases
- Better separation of concerns in configuration generation
- Enhanced debugging capabilities with --debug flag
- Code quality and maintainability - Refactored internal structure detection for better consistency

## [2.1.0] - 2025-11-28

### Fixed
- **Critical**: Fixed working directory bug causing "empty string returned" error for local development environments
- All WP-CLI commands now correctly execute from the project root directory
- Laravel Valet, DDEV, and other local environments now work correctly

### Added
- **Auto-detection** - Automatically detects and imports existing `wp-cli.yml` configuration
- **SSH Port Support** - Full support for custom SSH ports (Kinsta, managed hosting, etc.)
- `--auto` flag for `sync:init` command to automatically configure from wp-cli.yml
- SSH port configuration in environment settings
- Radicle compatibility verified and documented
- Comprehensive troubleshooting section in README
- Better error messages and configuration validation

### Changed
- Enhanced `sync:init` command with better prompts and defaults
- Improved configuration file creation with directory auto-creation
- Updated config structure to include `ssh_port` field
- All Process commands now use proper working directory
- Better detection of development environment URL from `.env`
- Marked legacy bash scripts as deprecated with migration guidance

### Improved
- Documentation updated with compatibility matrix
- Added examples for custom SSH ports
- Better explanation of configuration options
- More helpful error messages

## [2.0.0] - 2025-05-28

### Added
- **Complete rewrite as Acorn package** - Transformed from bash script to Laravel/Acorn commands
- `sync:init` command for interactive configuration setup
- `sync:env` command for environment synchronization
- `sync:status` command for connectivity checking
- `sync:config` command for configuration management
- Configuration-driven setup with `config/sync.php`
- Automatic WP-CLI alias management
- Environment variable support
- Interactive prompts and confirmations
- Progress bars for sync operations
- Comprehensive error handling and validation
- Slack notification integration
- Publishable configuration files
- Auto-discovery service provider registration

### Changed
- **Breaking**: Migrated from bash script to PHP/Laravel commands
- **Breaking**: New command syntax: `wp acorn sync:env {from} {to}` instead of `./sync.sh {from} {to}`
- Improved user experience with rich CLI interface
- Better error messages and validation
- Enhanced configuration management

### Removed
- **Breaking**: Original bash script functionality (preserved in legacy files)
- **Breaking**: Direct script execution

---

## Legacy Bash Script Versions

### 1.3.0: August 1st, 2022
* Change `—no-db`, `—no-assets` to `—skip-db`, `—skip-assets`, props @joshuafredrickson
* Remove redundant `db export`/`import`, props @joshuafredrickson

### 1.2.0: March 16th, 2022
* Add support for optionally skipping the database or assets with new flags: `--no-db` and `--no-assets`
* Pass `--default-character-set=utf8mb4` to `wp db export`
* Pass `--all-tables-with-prefix` to `wp search-replace`, props @joshuafredrickson

### 1.1.0: February 21st, 2019
* Support for local development without a VM (Valet, etc). by passing `--local` at the end of the arguments

### 1.0.0: February 21st, 2019
* Initial release
