<?php

namespace TelegramMusicBot\Core;

use TelegramMusicBot\Services\TelegramService;
use TelegramMusicBot\Controllers\AuthController;
use TelegramMusicBot\Controllers\MusicController;
use TelegramMusicBot\Core\Database;

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

        if (isset($update['message']['text']) && str_starts_with($update['message']['text'], '/start')) {
            $this->handleStartCommand($update['message']);
            return;
        }

        if (!$this->authController->isAdmin($userId)) {
            error_log("Unauthorized access attempt by user ID: " . $userId . " for update: " . json_encode($update));
            if ($chatId && !$isCallbackQuery) {
                 $this->telegramService->sendMessage($chatId, "متاسفانه شما اجازه دسترسی به این ربات را ندارید.");
            } elseif ($isCallbackQuery && isset($update['callback_query']['id'])) {
                $this->telegramService->answerCallbackQuery($update['callback_query']['id'], ['text' => "شما مجاز نیستید.", 'show_alert' => true]);
            }
            return;
        }

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

        $adminFullState = $this->getAdminState($userId); // Get full state including 'state' and 'data' keys
        $currentState = $adminFullState['state'] ?? null;
        $stateData = $adminFullState['data'] ?? []; // data is already an array or null from getAdminState

        if ($text !== null && str_starts_with($text, '/music_')) {
            $shortCode = substr($text, strlen('/music_'));
            if (!empty($shortCode)) {
                $this->musicController->showMusicDetailsByShortCode($chatId, $shortCode);
            } else {
                $this->telegramService->sendMessage($chatId, "کد موزیک در کامند مشخص نشده است.");
            }
        } elseif ($text === 'ارسال موزیک' && !$currentState) { // Check !$currentState instead of !$adminState
            $this->musicController->requestMusicFile($chatId, $userId);
        } elseif ($text === 'لیست کل موزیک' && !$currentState) {
            $this->musicController->showMusicList($chatId, 1, 5);
        } elseif ($currentState) { // Check if $currentState is not null
            // Log state data (already an array or null)
            if ($stateData === []) { // Or check if $adminFullState['data'] was null
                error_log("Admin {$userId} is in state: {$currentState} with empty or NULL data.");
            } else {
                $loggableData = print_r($stateData, true);
                if (strlen($loggableData) > 1000) {
                    $loggableData = substr($loggableData, 0, 1000) . "... (data truncated)";
                }
                error_log("Admin {$userId} is in state: {$currentState} with data content: " . $loggableData);
            }

            if ($currentState === MusicController::STATE_WAITING_FOR_CHANNEL_CAPTION) {
                if ($text !== null) {
                    $this->musicController->handleChannelCaptionInput($chatId, $userId, $text, $stateData);
                } else {
                    $this->telegramService->sendMessage($chatId, "لطفاً کپشن مورد نظر یا دستور `/emptycaption` را ارسال کنید.");
                }
                return;
            }

            if ($currentState === MusicController::STATE_WAITING_FOR_MUSIC_FILE) {
                if ($audio) { $this->musicController->handleMusicFile($chatId, $userId, $audio); }
                else { $this->telegramService->sendMessage($chatId, "لطفاً یک فایل موزیک ارسال کنید یا عملیات را لغو نمایید."); }
            } elseif ($currentState === MusicController::STATE_WAITING_FOR_LYRICS) {
                 if ($text) {
                    $musicId = $stateData['music_id'] ?? null;
                    if (!$musicId) { $this->handleMissingMusicIdInState($chatId, $userId, "waiting_for_lyrics"); return; }
                    $this->musicController->handleLyrics($chatId, $userId, $text, $musicId);
                } else { $this->telegramService->sendMessage($chatId, "لطفاً متن موزیک را ارسال کنید یا عملیات را لغو نمایید."); }
            } elseif ($currentState === MusicController::STATE_WAITING_FOR_NEW_LYRICS) {
                if ($text) {
                    $musicId = $stateData['music_id'] ?? null;
                     if (!$musicId) { $this->handleMissingMusicIdInState($chatId, $userId, "STATE_WAITING_FOR_NEW_LYRICS"); return;}
                    $this->musicController->handleNewLyrics($chatId, $userId, $text, $musicId);
                } else { $this->telegramService->sendMessage($chatId, "لطفاً متن جدید را ارسال کنید یا عملیات را لغو نمایید.");}
            } elseif ($currentState === MusicController::STATE_WAITING_FOR_NEW_FILE) {
                 if ($audio) {
                    $musicId = $stateData['music_id'] ?? null;
                    if (!$musicId) { $this->handleMissingMusicIdInState($chatId, $userId, "STATE_WAITING_FOR_NEW_FILE"); return;}
                    $this->musicController->handleNewMusicFile($chatId, $userId, $audio, $musicId);
                } else { $this->telegramService->sendMessage($chatId, "لطفاً فایل موزیک جدید را ارسال کنید یا عملیات را لغو نمایید.");}
            } elseif ($currentState === MusicController::STATE_WAITING_FOR_ARTIST_NAME) {
                if ($text) {
                    $musicIdFromState = $stateData['music_id'] ?? null;
                    if (!$musicIdFromState) { $this->handleMissingMusicIdInState($chatId, $userId, "STATE_WAITING_FOR_ARTIST_NAME"); return;}
                    $this->musicController->handleNewArtistName($chatId, $userId, $text, $musicIdFromState);
                } else { $this->telegramService->sendMessage($chatId, "لطفاً نام جدید خواننده را ارسال کنید یا عملیات را لغو نمایید.");}
            } elseif ($currentState === MusicController::STATE_WAITING_FOR_TITLE_NAME) {
                if ($text) {
                    $musicIdFromState = $stateData['music_id'] ?? null;
                    if (!$musicIdFromState) { $this->handleMissingMusicIdInState($chatId, $userId, "STATE_WAITING_FOR_TITLE_NAME"); return;}
                    $this->musicController->handleNewTitleName($chatId, $userId, $text, $musicIdFromState);
                } else { $this->telegramService->sendMessage($chatId, "لطفاً عنوان جدید موزیک را ارسال کنید یا عملیات را لغو نمایید.");}
            }
            else {
                $this->telegramService->sendMessage($chatId, "وضعیت نامشخص ادمین: {$currentState}. لطفاً از دکمه‌ها استفاده کنید یا با /start مجدداً شروع کنید.");
                $this->clearAdminState($userId);
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
        $id1 = $parts[2] ?? null;
        $id2 = $parts[3] ?? null;
        $id3 = $parts[4] ?? null;

        if ($action === 'delete' && $entity === 'music' && $id1) {
            $this->musicController->confirmDeleteMusic($chatId, $messageId, $userId, (int)$id1, $callbackQueryId);
        } elseif ($action === 'confirmdelete' && $entity === 'music' && $id1) {
            $this->musicController->executeDeleteMusic($chatId, $messageId, $userId, (int)$id1, $callbackQueryId);
        } elseif ($action === 'canceldelete' && $entity === 'music' && $id1) {
             $this->musicController->cancelDeleteMusic($chatId, $messageId, (int)$id1, $callbackQueryId);
        } elseif ($action === 'edit' && $entity === 'lyrics' && $id1) {
            $this->musicController->requestNewLyrics($chatId, $messageId, $userId, (int)$id1, $callbackQueryId);
        } elseif ($action === 'canceledit' && $entity === 'lyrics' && $id1) {
            $this->musicController->cancelEditLyrics($chatId, $messageId, $userId, (int)$id1, $callbackQueryId);
        } elseif ($action === 'edit' && $entity === 'file' && $id1) {
            $this->musicController->requestNewMusicFile($chatId, $messageId, $userId, (int)$id1, $callbackQueryId);
        } elseif ($action === 'canceledit' && $entity === 'file' && $id1) {
             $this->musicController->cancelEditFile($chatId, $messageId, $userId, (int)$id1, $callbackQueryId);
        }
        elseif ($action === 'request' && $entity === 'chcaption' && $id1) {
            $this->musicController->requestChannelCaption($chatId, $userId, (int)$id1, $callbackQueryId, $messageId);
        } elseif ($action === 'cancel' && $entity === 'chcaption' && $id1 && $id2) {
            $this->musicController->cancelChannelCaptionProcess($chatId, $userId, (int)$id1, $messageId, (int)$id2, $callbackQueryId);
        } elseif ($action === 'retry' && $entity === 'chcaption' && $id1 && $id2) {
            $this->telegramService->answerCallbackQuery($callbackQueryId);
            $this->telegramService->editMessageCaption($chatId, $messageId, ['caption' => 'درخواست ویرایش مجدد کپشن...', 'reply_markup' => null]);
            $this->musicController->requestChannelCaption($chatId, $userId, (int)$id1, "manual_ack", (int)$id2);
        } elseif ($action === 'finalsend' && $entity === 'tocanal' && $id1) {
            $adminFullState = $this->getAdminState($userId);
            $stateData = $adminFullState['data'] ?? [];
            $currentState = $adminFullState['state'] ?? null;

            if ($currentState === MusicController::STATE_CONFIRM_CHANNEL_POST && isset($stateData['channel_caption'])) {
                $captionConfirmPreviewMessageId = $stateData['caption_confirm_preview_message_id'] ?? $messageId;
                $this->musicController->executeFinalSendToChannel($chatId, $userId, (int)$id1, $stateData['channel_caption'], $callbackQueryId, $captionConfirmPreviewMessageId);
            } else {
                $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'خطا: وضعیت نامعتبر یا کپشن یافت نشد.', 'show_alert' => true]);
                error_log("Error in finalsend_tocanal: Invalid state ('{$currentState}') or missing caption for user {$userId}");
            }
        } elseif ($action === 'cancel' && $entity === 'sendprocess' && $id1 && $id2) {
            $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'ارسال به کانال به طور کامل لغو شد.']);
            $this->clearAdminState($userId);
            $this->telegramService->editMessageCaption($chatId, $messageId, ['caption' => 'ارسال به کانال لغو شد.', 'reply_markup' => null]);
            $this->telegramService->sendMessage($chatId, "می‌توانید از پیش‌نمایش اولیه موزیک (پیام قبلی با شناسه {$id2}) برای سایر عملیات استفاده کنید.");
        }
        elseif ($action === 'edit' && $entity === 'artist' && $id1) {
            $this->musicController->requestNewArtistName($chatId, $messageId, $userId, (int)$id1, $callbackQueryId);
        } elseif ($action === 'canceledit' && $entity === 'artist' && $id1) {
             $this->musicController->cancelEditArtistName($chatId, $messageId, $userId, (int)$id1, $callbackQueryId);
        } elseif ($action === 'edit' && $entity === 'title' && $id1) {
            $this->musicController->requestNewTitleName($chatId, $messageId, $userId, (int)$id1, $callbackQueryId);
        } elseif ($action === 'canceledit' && $entity === 'title' && $id1) {
             $this->musicController->cancelEditTitleName($chatId, $messageId, $userId, (int)$id1, $callbackQueryId);
        }
        elseif ($action === 'listmusic') {
            $param1_page_newcount = $id1;
            $param2_items_currpage = $id2;

            if ($entity === 'page' && $param1_page_newcount !== null && $param2_items_currpage !== null) {
                $this->telegramService->answerCallbackQuery($callbackQueryId);
                $this->musicController->showMusicList($chatId, (int)$param1_page_newcount, (int)$param2_items_currpage, $messageId);
            } elseif ($entity === 'setcount' && $param1_page_newcount !== null && $param2_items_currpage !== null) {
                $newCount = (int)$param1_page_newcount;
                $this->telegramService->answerCallbackQuery($callbackQueryId);
                $this->musicController->showMusicList($chatId, 1, $newCount, $messageId);
            } else {
                 $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'خطا در پارامترهای لیست.', 'show_alert' => true]);
                 error_log("Invalid listmusic callback data: " . $data);
            }
        }
        else {
            $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'عملیات نامشخص: ' . $data, 'show_alert' => true]);
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

    private function handleMissingMusicIdInState(int $chatId, int $userId, string $stateName): void {
        error_log("Error: music_id not found in state for {$stateName}. User: " . $userId);
        $this->telegramService->sendMessage($chatId, "خطای داخلی: اطلاعات موزیک در وضعیت فعلی یافت نشد. لطفاً مجدداً تلاش کنید.");
        $this->clearAdminState($userId);
        $this->musicController->sendMainMenu($chatId);
    }
}
