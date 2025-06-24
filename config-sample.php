<?php

// Telegram Bot Token
define('BOT_TOKEN', 'YOUR_BOT_TOKEN_HERE');
// Telegram Bot Username
define('BOT_USERNAME', 'YOUR_BOT_USERNAME_HERE'); // Without @

// Database Credentials
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'telegram_music_bot');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_CHARSET', 'utf8mb4');

// Admin User IDs (array of integers)
// Example: define('ADMIN_USER_IDS', [123456789, 987654321]);
define('ADMIN_USER_IDS', []);

// Channel Username or ID (where the music will be posted)
// For public channels: '@channelusername'
// For private channels: channel_id (integer, usually starts with -100)
define('TARGET_CHANNEL_ID', ''); // Example: '@MyMusicChannel' or -1001234567890

// Optional: If your TARGET_CHANNEL_ID is numeric but the channel is public,
// specify its username here (without @) for cleaner links.
// This is used for the "View music in channel" button when users request lyrics.
define('TARGET_CHANNEL_PUBLIC_USERNAME', ''); // Example: 'MyMusicChannel'


// Webhook settings
// Define the URL to your webhook script (public/index.php)
// Example: define('WEBHOOK_URL', 'https://yourdomain.com/path/to/public/index.php');
define('WEBHOOK_URL', '');

// Path to the log file
define('LOG_FILE', __DIR__ . '/../bot.log');

// Temporary directory for uploads or state management if needed
define('TEMP_DIR', __DIR__ . '/../tmp');

// Ensure errors are logged and not displayed
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', LOG_FILE);

// Function to get a configuration value
function getConfig(string $key, $default = null) {
    if (defined($key)) {
        return constant($key);
    }
    return $default;
}
