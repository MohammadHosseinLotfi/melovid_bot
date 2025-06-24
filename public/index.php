<?php

// Composer autoload
if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    error_log("FATAL: Composer autoload not found. Run 'composer install'.");
    http_response_code(500);
    echo "Internal server error: Autoloader missing.";
    exit;
}
require_once __DIR__ . '/../vendor/autoload.php';

// Configuration
if (!file_exists(__DIR__ . '/../config.php')) {
    error_log("FATAL: config.php not found. Please create it from config-example.php.");
    http_response_code(500);
    echo "Internal server error: Configuration missing.";
    exit;
}
require_once __DIR__ . '/../config.php';

use TelegramMusicBot\Core\Database;
use TelegramMusicBot\Core\RequestHandler;
use TelegramMusicBot\Services\TelegramService;
use TelegramMusicBot\Controllers\AuthController;
use TelegramMusicBot\Controllers\MusicController;
use Longman\TelegramBot\Exception\TelegramException;

// Error and Exception handling
ini_set('display_errors', 0); // Do not display errors to the user
ini_set('log_errors', 1);
if (defined('LOG_FILE') && !empty(LOG_FILE)) {
    ini_set('error_log', LOG_FILE);
} else {
    // Fallback if LOG_FILE is not defined, though it should be.
    ini_set('error_log', __DIR__ . '/../php_errors.log');
}
error_reporting(E_ALL);

set_exception_handler(function ($exception) {
    error_log("Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine() . "\nStack trace:\n" . $exception->getTraceAsString());
    http_response_code(500); // Internal Server Error
    // Optionally, send a generic error message to Telegram if possible and relevant (e.g., for critical user-facing errors)
    echo json_encode(['status' => 'error', 'message' => 'An unexpected internal error occurred.']);
});

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        // This error code is not included in error_reporting
        return false;
    }
    error_log("Error [$severity]: $message in $file:$line");
    // Do not call exit() here as it might stop script execution prematurely for non-fatal errors.
    // Let PHP decide if it's fatal. For notices/warnings, logging is often enough.
    return false; // Let PHP's internal error handler proceed
});


// Check essential configurations
$required_configs = ['BOT_TOKEN', 'BOT_USERNAME', 'WEBHOOK_URL', 'TARGET_CHANNEL_ID', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'ADMIN_USER_IDS'];
foreach ($required_configs as $config_key) {
    if (!defined($config_key) || (is_string(constant($config_key)) && empty(constant($config_key))) && constant($config_key) !== 0 && $config_key !== 'DB_PASSWORD' && $config_key !== 'ADMIN_USER_IDS') {
         if ($config_key === 'ADMIN_USER_IDS' && defined('ADMIN_USER_IDS') && is_array(ADMIN_USER_IDS) && empty(ADMIN_USER_IDS)) {
            // This is a valid state (no admins yet, but defined as empty array)
            // Potentially log a warning if this is not expected.
            error_log("Warning: ADMIN_USER_IDS is defined as an empty array. No users will be authorized as admin.");
        } else {
            error_log("FATAL: Essential configuration '{$config_key}' is not set or is empty in config.php.");
            http_response_code(500);
            echo "Internal server error: Essential configuration '{$config_key}' missing or empty.";
            exit;
        }
    }
}


// Initialize Database Connection (static init)
try {
    Database::init();
} catch (\Exception $e) {
    error_log("FATAL: Database initialization failed: " . $e->getMessage());
    http_response_code(500);
    echo "Internal server error: Database connection failed.";
    exit;
}

// Instantiate core components
try {
    $telegramService = new TelegramService(); // Handles actual API calls
    $authController = new AuthController();     // Handles admin authentication
    
    // MusicController needs TelegramService
    $musicController = new MusicController($telegramService); 

    // RequestHandler needs all of them (or access to them)
    $requestHandler = new RequestHandler($telegramService, $authController, $musicController);

} catch (TelegramException $e) {
    error_log("FATAL: Failed to initialize Telegram Service: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Telegram service initialization error.']);
    exit;
} catch (\Exception $e) {
    error_log("FATAL: Failed to initialize core components: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Core component initialization error.']);
    exit;
}


// Get the RAW post data from Telegram
$input = file_get_contents('php://input');

if (empty($input)) {
    // This can happen if the script is accessed directly via browser, not by Telegram webhook.
    error_log("Notice: Received empty input. Likely a direct access attempt or misconfigured webhook.");
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'No input received. This script should be called by Telegram webhook.']);
    exit;
}

// Decode the JSON input from Telegram
$update = json_decode($input, true);

if ($update === null || !is_array($update)) {
    error_log("Error: Failed to decode JSON input or input is not an array. Raw input: " . $input);
    http_response_code(400); // Bad Request for malformed JSON
    echo json_encode(['status' => 'error', 'message' => 'Malformed JSON input.']);
    exit;
}

// Log the received update (optional, good for debugging)
// Consider logging level or conditional logging for production to avoid excessive logs.
// error_log("Received update: " . json_encode($update, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));


// Process the update
try {
    $requestHandler->processUpdate($update);
    // Telegram typically expects a 200 OK response quickly, even if processing happens asynchronously.
    // If processUpdate handles long tasks, consider backgrounding them.
    // For now, assume processUpdate is reasonably fast or handles responses itself.
    // If RequestHandler doesn't send a response for some reason, send a default OK.
    if (!headers_sent()) {
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'message' => 'Update processed.']);
    }
} catch (TelegramException $e) {
    error_log("Telegram API Error during processing: " . $e->getMessage() . " - Input: " . $input . " - Trace: " . $e->getTraceAsString());
    // Don't try to send a message back via Telegram API if the API itself is failing.
    if (!headers_sent()) {
        http_response_code(500); // Internal Server Error
        echo json_encode(['status' => 'error', 'message' => 'Telegram API error during processing.']);
    }
} catch (\Exception $e) {
    error_log("General Error during processing: " . $e->getMessage() . " - Input: " . $input . " - Trace: " . $e->getTraceAsString());
    if (!headers_sent()) {
        http_response_code(500); // Internal Server Error
        echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred during processing.']);
    }
}

exit; // Ensure script termination

?>
