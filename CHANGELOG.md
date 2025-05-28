# Changelog

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
