<?php

namespace TelegramMusicBot\Core;

use TelegramMusicBot\Services\TelegramService;
use TelegramMusicBot\Controllers\AuthController;
use TelegramMusicBot\Controllers\MusicController;
use TelegramMusicBot\Core\Database; // For state management

class RequestHandler
{
    private TelegramService $telegramService;
    private AuthController $authController;
    private MusicController $musicController;
    // private Database $db; // Not directly used here, but controllers might need it.

    public function __construct(TelegramService $telegramService, AuthController $authController, MusicController $musicController)
    {
        $this->telegramService = $telegramService;
        $this->authController = $authController;
        $this->musicController = $musicController;
        // $this->db = $db; // Initialize DB if needed directly or ensure controllers can access it
        Database::init(); // Ensure Database is initialized
    }

    public function processUpdate(array $update): void
    {
        $userId = null;
        $chatId = null;
        $isCallbackQuery = false;

        if (isset($update['message']['from']['id'])) {
            $userId = $update['message']['from']['id'];
            $chatId = $update['message']['chat']['id'];
        } elseif (isset($update['callback_query']['from']['id'])) {
            $userId = $update['callback_query']['from']['id'];
            $chatId = $update['callback_query']['message']['chat']['id'];
            $isCallbackQuery = true;
        }

        if ($userId === null) {
            error_log("Could not extract user ID from update: " . json_encode($update));
            return;
        }

        // Handle /start command separately to allow non-admins for deep links
        if (isset($update['message']['text']) && str_starts_with($update['message']['text'], '/start')) {
            $this->handleStartCommand($update['message']);
            return;
        }

        // For all other messages and callbacks, authenticate admin
        if (!$this->authController->isAdmin($userId)) {
            error_log("Unauthorized access attempt by user ID: " . $userId . " for update: " . json_encode($update));
            if ($chatId && !$isCallbackQuery) {
                 $this->telegramService->sendMessage($chatId, "متاسفانه شما اجازه دسترسی به این ربات را ندارید.");
            } elseif ($isCallbackQuery && isset($update['callback_query']['id'])) {
                $this->telegramService->answerCallbackQuery($update['callback_query']['id'], [
                    'text' => "شما مجاز نیستید.",
                    'show_alert' => true,
                ]);
            }
            return;
        }

        // At this point, the user is a verified admin for non-/start commands or callbacks.
        if ($isCallbackQuery) {
            $this->handleCallbackQuery($update['callback_query']);
        } elseif (isset($update['message'])) {
            // We've already handled /start, so this is for other admin messages
            $this->handleAdminMessage($update['message']);
        } else {
            error_log("Unhandled update type for admin: " . json_encode($update));
        }
    }

    private function handleStartCommand(array $message): void
    {
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $text = $message['text']; // Already checked it's a /start message

        $parts = explode(' ', $text, 2);
        $shortCode = $parts[1] ?? null;

        if ($shortCode) {
            // Deep link for lyrics, accessible to all users
            error_log("DeepLink: Processing /start with shortCode '{$shortCode}' for user {$userId}");
            $this->musicController->handleDeepLinkLyricsRequest($chatId, $shortCode, $this->authController->isAdmin($userId));
        } else {
            // Regular /start command
            if ($this->authController->isAdmin($userId)) {
                $this->clearAdminState($userId);
                $this->musicController->sendMainMenu($chatId);
            } else {
                $this->telegramService->sendMessage($chatId, "سلام! برای استفاده از امکانات خاص ربات، لطفاً از طریق لینک‌های معتبر اقدام کنید یا با ادمین تماس بگیرید.");
            }
        }
    }

    /**
     * Handles messages from authenticated admins (excluding /start).
     */
    private function handleAdminMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id']; // Admin user ID
        $text = $message['text'] ?? null;
        $audio = $message['audio'] ?? null;

        $adminState = $this->getAdminState($userId);

        // Note: /start is handled by handleStartCommand now
        if ($text === 'ارسال موزیک' && !$adminState) {
            $this->musicController->requestMusicFile($chatId, $userId);
        } elseif ($adminState) {
            // If admin is in a specific state, pass the message to the appropriate handler
            $stateParts = explode('_', $adminState['state'], 2);
            $currentState = $stateParts[0]; // e.g., waitingForMusicFile, waitingForLyrics
            $stateData = $adminState['data'] ? json_decode($adminState['data'], true) : [];

            if ($currentState === MusicController::STATE_WAITING_FOR_MUSIC_FILE) {
                if ($audio) {
                    $this->musicController->handleMusicFile($chatId, $userId, $audio);
                } else {
                    // Potentially a cancel command or unexpected input
                    $this->telegramService->sendMessage($chatId, "لطفاً یک فایل موزیک ارسال کنید یا عملیات را لغو نمایید.");
                }
            } elseif ($currentState === MusicController::STATE_WAITING_FOR_LYRICS) {
                 if ($text) {
                    $musicId = $stateData['music_id'] ?? null;
                    if (!$musicId) {
                        error_log("Error: music_id not found in state for waiting_for_lyrics. User: " . $userId);
                        $this->telegramService->sendMessage($chatId, "خطایی رخ داده است. لطفاً مجدداً تلاش کنید.");
                        $this->clearAdminState($userId);
                        $this->musicController->sendMainMenu($chatId);
                        return;
                    }
                    $this->musicController->handleLyrics($chatId, $userId, $text, $musicId);
                } else {
                    $this->telegramService->sendMessage($chatId, "لطفاً متن موزیک را ارسال کنید یا عملیات را لغو نمایید.");
                }
            } elseif ($currentState === MusicController::STATE_WAITING_FOR_NEW_LYRICS) {
                if ($text) {
                    $musicId = $stateData['music_id'] ?? null;
                     if (!$musicId) { /* ... error handling ... */ }
                    $this->musicController->handleNewLyrics($chatId, $userId, $text, $musicId);
                } else { /* ... */ }
            } elseif ($currentState === MusicController::STATE_WAITING_FOR_NEW_FILE) {
                 if ($audio) {
                    $musicId = $stateData['music_id'] ?? null;
                    if (!$musicId) {
                        error_log("Error: music_id not found in state for STATE_WAITING_FOR_NEW_FILE. User: " . $userId);
                        $this->telegramService->sendMessage($chatId, "خطایی در پردازش ویرایش فایل رخ داد. لطفاً مجدداً تلاش کنید.");
                        $this->clearAdminState($userId);
                        $this->musicController->sendMainMenu($chatId);
                        return;
                    }
                    $this->musicController->handleNewMusicFile($chatId, $userId, $audio, $musicId);
                } else {
                     $this->telegramService->sendMessage($chatId, "لطفاً فایل موزیک جدید را ارسال کنید یا عملیات را لغو نمایید.");
                }
            } elseif ($currentState === MusicController::STATE_WAITING_FOR_ARTIST_NAME) {
                if ($text) {
                    $musicIdFromState = $stateData['music_id'] ?? null;
                    if (!$musicIdFromState) {
                        error_log("Error: music_id not found in state for STATE_WAITING_FOR_ARTIST_NAME. User: " . $userId);
                        $this->telegramService->sendMessage($chatId, "خطایی در پردازش ویرایش نام خواننده رخ داد. لطفاً مجدداً تلاش کنید.");
                        $this->clearAdminState($userId);
                        $this->musicController->sendMainMenu($chatId);
                        return;
                    }
                    $this->musicController->handleNewArtistName($chatId, $userId, $text, $musicIdFromState);
                } else {
                    $this->telegramService->sendMessage($chatId, "لطفاً نام جدید خواننده را ارسال کنید یا عملیات را لغو نمایید.");
                }
            } elseif ($currentState === MusicController::STATE_WAITING_FOR_TITLE_NAME) {
                if ($text) {
                    $musicIdFromState = $stateData['music_id'] ?? null;
                    if (!$musicIdFromState) {
                        error_log("Error: music_id not found in state for STATE_WAITING_FOR_TITLE_NAME. User: " . $userId);
                        $this->telegramService->sendMessage($chatId, "خطایی در پردازش ویرایش عنوان موزیک رخ داد. لطفاً مجدداً تلاش کنید.");
                        $this->clearAdminState($userId);
                        $this->musicController->sendMainMenu($chatId);
                        return;
                    }
                    $this->musicController->handleNewTitleName($chatId, $userId, $text, $musicIdFromState);
                } else {
                    $this->telegramService->sendMessage($chatId, "لطفاً عنوان جدید موزیک را ارسال کنید یا عملیات را لغو نمایید.");
                }
            }
            // Add more state handlers as needed
            else {
                // Unknown state or general message - perhaps show main menu or a help message
                $this->telegramService->sendMessage($chatId, "دستور نامشخص است یا در وضعیت فعلی قابل پردازش نیست. لطفاً از دکمه‌ها استفاده کنید.");
                $this->musicController->sendMainMenu($chatId); // Or specific instructions
            }
        } else {
            // No specific state, and not a known command like /start or "ارسال موزیک"
            // Could be a general message, or a mis-click.
            $this->telegramService->sendMessage($chatId, "دستور شما را متوجه نشدم. لطفاً از کیبورد زیر استفاده کنید.");
            $this->musicController->sendMainMenu($chatId);
        }
    }

    private function handleCallbackQuery(array $callbackQuery): void
    {
        $callbackQueryId = $callbackQuery['id'];
        $userId = $callbackQuery['from']['id']; // Admin user ID
        $chatId = $callbackQuery['message']['chat']['id'];
        $messageId = $callbackQuery['message']['message_id'];
        $data = $callbackQuery['data']; // e.g., "delete_music_123"

        // Acknowledge callback query immediately to remove "loading" state on client
        // $this->telegramService->answerCallbackQuery($callbackQueryId); // Default ack

        $parts = explode('_', $data);
        $action = $parts[0] ?? null;
        $entity = $parts[1] ?? null; // e.g., music, lyrics, file
        $entityId = $parts[2] ?? null; // e.g., the music_id

        // Note: This routing can become more sophisticated.
        // For now, a simple switch or if-else based on $action.

        // Example: route to MusicController based on action
        if ($action === 'delete' && $entity === 'music' && $entityId) {
            $this->musicController->confirmDeleteMusic($chatId, $messageId, $userId, (int)$entityId, $callbackQueryId);
        } elseif ($action === 'confirmdelete' && $entity === 'music' && $entityId) {
            $this->musicController->executeDeleteMusic($chatId, $messageId, $userId, (int)$entityId, $callbackQueryId);
        } elseif ($action === 'canceldelete' && $entity === 'music' && $entityId) {
             $this->musicController->cancelDeleteMusic($chatId, $messageId, $callbackQueryId);
        } elseif ($action === 'edit' && $entity === 'lyrics' && $entityId) {
            $this->musicController->requestNewLyrics($chatId, $messageId, $userId, (int)$entityId, $callbackQueryId);
        } elseif ($action === 'canceledit' && $entity === 'lyrics' && $entityId) {
            $this->musicController->cancelEditLyrics($chatId, $messageId, $userId, (int)$entityId, $callbackQueryId);
        } elseif ($action === 'edit' && $entity === 'file' && $entityId) {
            $this->musicController->requestNewMusicFile($chatId, $messageId, $userId, (int)$entityId, $callbackQueryId);
        } elseif ($action === 'canceledit' && $entity === 'file' && $entityId) {
             $this->musicController->cancelEditFile($chatId, $messageId, $userId, (int)$entityId, $callbackQueryId);
        } elseif ($action === 'send' && $entity === 'tochannel' && $entityId) {
            $this->musicController->sendToChannel($chatId, $userId, (int)$entityId, $callbackQueryId);
        } elseif ($action === 'edit' && $entity === 'artist' && $entityId) {
            $this->musicController->requestNewArtistName($chatId, $messageId, $userId, (int)$entityId, $callbackQueryId);
        } elseif ($action === 'canceledit' && $entity === 'artist' && $entityId) {
             $this->musicController->cancelEditArtistName($chatId, $messageId, $userId, (int)$entityId, $callbackQueryId);
        } elseif ($action === 'edit' && $entity === 'title' && $entityId) {
            $this->musicController->requestNewTitleName($chatId, $messageId, $userId, (int)$entityId, $callbackQueryId);
        } elseif ($action === 'canceledit' && $entity === 'title' && $entityId) {
             $this->musicController->cancelEditTitleName($chatId, $messageId, $userId, (int)$entityId, $callbackQueryId);
        }
        // Add more callback routes as needed
        else {
            $this->telegramService->answerCallbackQuery($callbackQueryId, [
                'text' => 'عملیات نامشخص: ' . $data,
                'show_alert' => true
            ]);
            error_log("Unhandled callback query data: " . $data . " by user ID: " . $userId);
        }
    }

    /**
     * Retrieves the current state of an admin.
     * @param int $adminId
     * @return array|null ['state' => 'state_name', 'data' => 'json_data'] or null if no state
     */
    private function getAdminState(int $adminId): ?array
    {
        $row = Database::fetchOne("SELECT state, data FROM admin_states WHERE admin_id = ?", [$adminId]);
        return $row ?: null;
    }

    /**
     * Clears the state of an admin.
     * @param int $adminId
     */
    private function clearAdminState(int $adminId): void
    {
        Database::executeQuery("DELETE FROM admin_states WHERE admin_id = ?", [$adminId]);
    }
}
