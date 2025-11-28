<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Environment Configurations
    |--------------------------------------------------------------------------
    |
    | Define your WordPress environments here. Each environment should have
    | a URL and uploads path. Remote environments should include SSH details.
    |
    */
    'environments' => [
        'development' => [
            'url' => env('SYNC_DEV_URL', 'https://example.test'),
            'uploads_path' => env('SYNC_DEV_UPLOADS_PATH', 'web/app/uploads/'),
            'wp_cli_alias' => null, // Local environment
        ],
        'staging' => [
            'url' => env('SYNC_STAGING_URL'),
            'uploads_path' => env('SYNC_STAGING_UPLOADS_PATH'),
            'wp_cli_alias' => '@staging',
            'ssh_host' => null, // Will be extracted from uploads_path
            'ssh_port' => env('SYNC_STAGING_SSH_PORT', '22'),
            'remote_path' => null, // Will be extracted from uploads_path
        ],
        'production' => [
            'url' => env('SYNC_PROD_URL'),
            'uploads_path' => env('SYNC_PROD_UPLOADS_PATH'),
            'wp_cli_alias' => '@production',
            'ssh_host' => null, // Will be extracted from uploads_path
            'ssh_port' => env('SYNC_PROD_SSH_PORT', '22'),
            'remote_path' => null, // Will be extracted from uploads_path
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Options
    |--------------------------------------------------------------------------
    |
    | Configure various sync behavior options.
    |
    */
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

    /*
    |--------------------------------------------------------------------------
    | WP-CLI Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for WP-CLI integration and alias management.
    |
    */
    'wp_cli' => [
        'config_file' => 'wp-cli.yml',
        'auto_update_aliases' => true,
        'backup_config_before_update' => true,
    ],
];
