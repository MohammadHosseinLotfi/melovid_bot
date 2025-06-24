<?php

// Telegram Bot Token
define('BOT_TOKEN', '8189622183:AAG_9aQTt6rxbK73C5P7QDwfvoVqriLnPzI');
// Telegram Bot Username
define('BOT_USERNAME', 'NaghmehAdhanBot'); // Without @

// Database Credentials
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'mhlotfii_music');
define('DB_USER', 'mhlotfii_music');
define('DB_PASSWORD', 'mhlotfii_music');
define('DB_CHARSET', 'utf8mb4');

// Admin User IDs (array of integers)
// Example: define('ADMIN_USER_IDS', [123456789, 987654321]);
define('ADMIN_USER_IDS', [5022592341]);

// Channel Username or ID (where the music will be posted)
// For public channels: '@channelusername'
// For private channels: channel_id (integer, usually starts with -100)
define('TARGET_CHANNEL_ID', '-1002516375516'); // Example: '@MyMusicChannel' or -1001234567890

// Optional: If your TARGET_CHANNEL_ID is numeric but the channel is public,
// specify its username here (without @) for cleaner links.
// This is used for the "View music in channel" button when users request lyrics.
define('TARGET_CHANNEL_PUBLIC_USERNAME', 'sdvnfujvsolv'); // Example: 'MyMusicChannel'


// Webhook settings
// Define the URL to your webhook script (public/index.php)
// Example: define('WEBHOOK_URL', 'https://yourdomain.com/path/to/public/index.php');
define('WEBHOOK_URL', 'https://bot.mhlotfi.ir/music/public/index.php');

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
