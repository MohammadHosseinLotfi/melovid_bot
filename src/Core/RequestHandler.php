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

    public function __construct(TelegramService $telegramService, AuthController $authController, MusicController $musicController)
    {
        $this->telegramService = $telegramService;
        $this->authController = $authController;
        $this->musicController = $musicController;
        Database::init();
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
            $this->handleAdminMessage($update['message']);
        } else {
            error_log("Unhandled update type for admin: " . json_encode($update));
        }
    }

    private function handleStartCommand(array $message): void
    {
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $text = $message['text'];

        $parts = explode(' ', $text, 2);
        $shortCode = $parts[1] ?? null;

        if ($shortCode) {
            error_log("DeepLink: Processing /start with shortCode '{$shortCode}' for user {$userId}");
            $this->musicController->handleDeepLinkLyricsRequest($chatId, $shortCode, $this->authController->isAdmin($userId));
        } else {
            if ($this->authController->isAdmin($userId)) {
                $this->clearAdminState($userId);
                $this->musicController->sendMainMenu($chatId);
            } else {
                $this->telegramService->sendMessage($chatId, "سلام! برای استفاده از امکانات خاص ربات، لطفاً از طریق لینک‌های معتبر اقدام کنید یا با ادمین تماس بگیرید.");
            }
        }
    }

    private function handleAdminMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $text = $message['text'] ?? null;
        $audio = $message['audio'] ?? null;

        $adminState = $this->getAdminState($userId);

        if ($text !== null && str_starts_with($text, '/music_')) {
            $shortCode = substr($text, strlen('/music_'));
            if (!empty($shortCode)) {
                $this->musicController->showMusicDetailsByShortCode($chatId, $shortCode);
            } else {
                $this->telegramService->sendMessage($chatId, "کد موزیک در کامند مشخص نشده است.");
            }
        } elseif ($text === 'ارسال موزیک' && !$adminState) {
            $this->musicController->requestMusicFile($chatId, $userId);
        } elseif ($text === 'لیست کل موزیک' && !$adminState) {
            $this->musicController->showMusicList($chatId, 1, 5);
        } elseif ($adminState) {
            $stateParts = explode('_', $adminState['state'], 2); // Not really used now, but kept for structure
            $currentState = $adminState['state']; // Use full state name
            $stateData = $adminState['data'] ? json_decode($adminState['data'], true) : [];

            if ($currentState === MusicController::STATE_WAITING_FOR_MUSIC_FILE) {
                if ($audio) {
                    $this->musicController->handleMusicFile($chatId, $userId, $audio);
                } else {
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
                     if (!$musicId) {
                        error_log("Error: music_id not found in state for STATE_WAITING_FOR_NEW_LYRICS. User: " . $userId);
                        $this->telegramService->sendMessage($chatId, "خطایی در پردازش ویرایش متن رخ داد.");
                        $this->clearAdminState($userId); $this->musicController->sendMainMenu($chatId); return;
                     }
                    $this->musicController->handleNewLyrics($chatId, $userId, $text, $musicId);
                } else {
                    $this->telegramService->sendMessage($chatId, "لطفاً متن جدید را ارسال کنید یا عملیات را لغو نمایید.");
                }
            } elseif ($currentState === MusicController::STATE_WAITING_FOR_NEW_FILE) {
                 if ($audio) {
                    $musicId = $stateData['music_id'] ?? null;
                    if (!$musicId) {
                        error_log("Error: music_id not found in state for STATE_WAITING_FOR_NEW_FILE. User: " . $userId);
                        $this->telegramService->sendMessage($chatId, "خطایی در پردازش ویرایش فایل رخ داد.");
                        $this->clearAdminState($userId); $this->musicController->sendMainMenu($chatId); return;
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
                        $this->telegramService->sendMessage($chatId, "خطایی در پردازش ویرایش نام خواننده رخ داد.");
                        $this->clearAdminState($userId); $this->musicController->sendMainMenu($chatId); return;
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
                        $this->telegramService->sendMessage($chatId, "خطایی در پردازش ویرایش عنوان موزیک رخ داد.");
                        $this->clearAdminState($userId); $this->musicController->sendMainMenu($chatId); return;
                    }
                    $this->musicController->handleNewTitleName($chatId, $userId, $text, $musicIdFromState);
                } else {
                    $this->telegramService->sendMessage($chatId, "لطفاً عنوان جدید موزیک را ارسال کنید یا عملیات را لغو نمایید.");
                }
            }
            else {
                $this->telegramService->sendMessage($chatId, "دستور نامشخص است یا در وضعیت فعلی قابل پردازش نیست. لطفاً از دکمه‌ها استفاده کنید.");
                $this->musicController->sendMainMenu($chatId);
            }
        } else {
            $this->telegramService->sendMessage($chatId, "دستور شما را متوجه نشدم. لطفاً از کیبورد زیر استفاده کنید.");
            $this->musicController->sendMainMenu($chatId);
        }
    }

    private function handleCallbackQuery(array $callbackQuery): void
    {
        $callbackQueryId = $callbackQuery['id'];
        $userId = $callbackQuery['from']['id'];
        $chatId = $callbackQuery['message']['chat']['id'];
        $messageId = $callbackQuery['message']['message_id'];
        $data = $callbackQuery['data'];

        $parts = explode('_', $data);
        $action = $parts[0] ?? null;
        $entity = $parts[1] ?? null;
        $entityId = $parts[2] ?? null;

        if ($action === 'delete' && $entity === 'music' && $entityId) {
            $this->musicController->confirmDeleteMusic($chatId, $messageId, $userId, (int)$entityId, $callbackQueryId);
        } elseif ($action === 'confirmdelete' && $entity === 'music' && $entityId) {
            $this->musicController->executeDeleteMusic($chatId, $messageId, $userId, (int)$entityId, $callbackQueryId);
        } elseif ($action === 'canceldelete' && $entity === 'music' && $entityId) {
             $this->musicController->cancelDeleteMusic($chatId, $messageId, (int)$entityId, $callbackQueryId);
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
        elseif ($action === 'listmusic') {
            // callback_data format: listmusic_page_<page>_<itemsPerPage> OR listmusic_setcount_<count>_<currentPage>
            $param1 = $parts[2] ?? null; // page for page, new_count for setcount
            $param2 = $parts[3] ?? null; // itemsPerPage for page, current_page for setcount

            if ($entity === 'page' && $param1 !== null && $param2 !== null) {
                $this->telegramService->answerCallbackQuery($callbackQueryId);
                $this->musicController->showMusicList($chatId, (int)$param1, (int)$param2, $messageId);
            } elseif ($entity === 'setcount' && $param1 !== null && $param2 !== null) {
                $newCount = (int)$param1;
                // $currentPageForCountChange = (int)$param2; // Not strictly needed if we always go to page 1
                $this->telegramService->answerCallbackQuery($callbackQueryId);
                $this->musicController->showMusicList($chatId, 1, $newCount, $messageId);
            } else {
                 $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'خطا در پارامترهای لیست.', 'show_alert' => true]);
                 error_log("Invalid listmusic callback data: " . $data);
            }
        }
        else {
            $this->telegramService->answerCallbackQuery($callbackQueryId, [
                'text' => 'عملیات نامشخص: ' . $data,
                'show_alert' => true
            ]);
            error_log("Unhandled callback query data: " . $data . " by user ID: " . $userId);
        }
    }

    private function getAdminState(int $adminId): ?array
    {
        $row = Database::fetchOne("SELECT state, data FROM admin_states WHERE admin_id = ?", [$adminId]);
        return $row ? ['state' => $row['state'], 'data' => $row['data'] ? json_decode($row['data'], true) : []] : null;
    }

    private function clearAdminState(int $adminId): void
    {
        Database::executeQuery("DELETE FROM admin_states WHERE admin_id = ?", [$adminId]);
    }
}
