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
            $currentState = $adminState['state'];
            $stateData = $adminState['data'] ?? [];

            if (!isset($adminState['data'])) {
                error_log("Admin {$userId} is in state: {$currentState} with NULL data field from getAdminState.");
            } else {
                $loggableData = print_r($adminState['data'], true);
                if (strlen($loggableData) > 1000) {
                    $loggableData = substr($loggableData, 0, 1000) . "... (data truncated)";
                }
                error_log("Admin {$userId} is in state: {$currentState} with data type from getAdminState: " . gettype($adminState['data']) . ", content: " . $loggableData);
            }

            if (!is_array($stateData)) {
                 error_log("Warning: \$stateData was not an array after assignment for user {$userId}, state {$currentState}. Resetting to empty array. Original type from getAdminState['data']: " . gettype($adminState['data']));
                 $stateData = [];
            }

            // Handle channel caption input first if in that state
            if ($currentState === MusicController::STATE_WAITING_FOR_CHANNEL_CAPTION) {
                if ($text !== null) {
                    $this->musicController->handleChannelCaptionInput($chatId, $userId, $text, $stateData);
                } else {
                    $this->telegramService->sendMessage($chatId, "لطفاً کپشن مورد نظر یا دستور `/emptycaption` را ارسال کنید.");
                }
                return;
            }

            // Other state handlings
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
            // Note: STATE_CONFIRM_CHANNEL_POST is handled via callback queries, not direct messages.
            else {
                $this->telegramService->sendMessage($chatId, "دستور نامشخص است یا در وضعیت فعلی قابل پردازش نیست. لطفاً از دکمه‌ها استفاده کنید.");
                $this->clearAdminState($userId); // Clear unknown/stuck state
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
        $id1 = $parts[2] ?? null; // Usually musicId
        $id2 = $parts[3] ?? null; // Sometimes original_message_id or prompt_message_id

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
        // Channel Caption Flow
        elseif ($action === 'request' && $entity === 'chcaption' && $id1) { // from sendMusicPreviewToAdmin
            $this->musicController->requestChannelCaption($chatId, $userId, (int)$id1, $callbackQueryId, $messageId);
        } elseif ($action === 'cancel' && $entity === 'chcaption' && $id1 && $id2) { // from requestChannelCaption
            $this->musicController->cancelChannelCaptionProcess($chatId, $userId, (int)$id1, $messageId, (int)$id2, $callbackQueryId);
        } elseif ($action === 'retry' && $entity === 'chcaption' && $id1 && $id2) { // from handleChannelCaptionInput's preview
             // $id1 is musicId, $id2 is original_preview_message_id
             // $messageId is the ID of the caption preview message (the audio message with "Final Send" / "Edit Caption" buttons)
            $this->telegramService->editMessageCaption($chatId, $messageId, ['caption' => 'درخواست ویرایش مجدد کپشن...', 'reply_markup' => null]);
            // $this->telegramService->deleteMessage($chatId, $messageId); // Delete the caption preview message
            $this->musicController->requestChannelCaption($chatId, $userId, (int)$id1, $callbackQueryId, (int)$id2);
        } elseif ($action === 'finalsend' && $entity === 'tocanal' && $id1) { // from handleChannelCaptionInput's preview
            $stateData = $this->getAdminStateData($userId);
            if ($this->getAdminState($userId)['state'] === MusicController::STATE_CONFIRM_CHANNEL_POST && isset($stateData['channel_caption'])) {
                $this->musicController->executeFinalSendToChannel($chatId, $userId, (int)$id1, $stateData['channel_caption'], $callbackQueryId, $messageId);
            } else {
                $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'خطا: وضعیت نامعتبر یا کپشن یافت نشد.', 'show_alert' => true]);
                error_log("Error in finalsend_tocanal: Invalid state or missing caption for user {$userId}");
            }
        } elseif ($action === 'cancel' && $entity === 'sendprocess' && $id1 && $id2) { // from handleChannelCaptionInput's preview
            // $id1 = musicId, $id2 = original_preview_message_id
            // $messageId is the ID of the caption preview message (audio with "Final Send" buttons)
            $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'ارسال به کانال به طور کامل لغو شد.']);
            $this->clearAdminState($userId);
            $this->telegramService->editMessageCaption($chatId, $messageId, ['caption' => 'ارسال به کانال لغو شد.', 'reply_markup' => null]);
            // Restore original preview for further actions if needed
            // $this->musicController->sendMusicPreviewToAdmin($chatId, (int)$id1);
             $this->telegramService->sendMessage($chatId, "می‌توانید از پیش‌نمایش اولیه موزیک (پیام قبلی) برای سایر عملیات استفاده کنید.");
        }
        // End Channel Caption Flow
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
            $param1 = $parts[2] ?? null;
            $param2 = $parts[3] ?? null;
            if ($entity === 'page' && $param1 !== null && $param2 !== null) {
                $this->telegramService->answerCallbackQuery($callbackQueryId);
                $this->musicController->showMusicList($chatId, (int)$param1, (int)$param2, $messageId);
            } elseif ($entity === 'setcount' && $param1 !== null && $param2 !== null) {
                $newCount = (int)$param1;
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
