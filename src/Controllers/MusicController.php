<?php

namespace TelegramMusicBot\Controllers;

use TelegramMusicBot\Services\TelegramService;
use TelegramMusicBot\Core\Database; // To interact with the database

class MusicController
{
    private TelegramService $telegramService;

    // Define states for multi-step operations
    public const STATE_WAITING_FOR_MUSIC_FILE = 'waitingForMusicFile';
    public const STATE_WAITING_FOR_LYRICS = 'waitingForLyrics';
    public const STATE_WAITING_FOR_TITLE_NAME = 'waitingForTitleName';
    public const STATE_WAITING_FOR_ARTIST_NAME = 'waitingForArtistName';
    public const STATE_WAITING_FOR_NEW_LYRICS = 'waitingForNewLyrics';
    public const STATE_WAITING_FOR_NEW_FILE = 'waitingForNewFile';
    public const STATE_WAITING_FOR_CHANNEL_CAPTION = 'waitingForChannelCaption';
    public const STATE_CONFIRM_CHANNEL_POST = 'confirmChannelPost';


    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }

    public function sendMainMenu(int $chatId): void
    {
        $keyboard = TelegramService::createReplyKeyboard([
            ['ارسال موزیک', 'لیست کل موزیک'],
        ], true, true);

        $this->telegramService->sendMessage($chatId, "چه کاری می‌خواهید انجام دهید؟ لطفاً یکی از گزینه‌های زیر را انتخاب کنید:", [
            'reply_markup' => $keyboard
        ]);
    }

    public function requestMusicFile(int $chatId, int $adminId): void
    {
        $this->setAdminState($adminId, self::STATE_WAITING_FOR_MUSIC_FILE);
        $this->telegramService->sendMessage($chatId, "لطفاً فایل موزیک را ارسال کنید:");
    }

    public function handleMusicFile(int $chatId, int $adminId, array $audioData): void
    {
        $fileId = $audioData['file_id'];
        $fileUniqueId = $audioData['file_unique_id'];
        $title = $audioData['title'] ?? ($audioData['file_name'] ?? 'بدون عنوان');
        $artist = (!empty($audioData['performer']) ? $audioData['performer'] : 'ناشناس');

        $shortCode = $this->generateShortCode();
        Database::executeQuery(
            "INSERT INTO musics (file_id, file_unique_id, title, artist, short_code) VALUES (?, ?, ?, ?, ?)",
            [$fileId, $fileUniqueId, $title, $artist, $shortCode]
        );
        $musicId = Database::lastInsertId();

        if (!$musicId) {
            $this->telegramService->sendMessage($chatId, "خطا در ذخیره اولیه فایل موزیک. لطفاً دوباره تلاش کنید.");
            $this->clearAdminState($adminId);
            $this->sendMainMenu($chatId);
            return;
        }

        $this->setAdminState($adminId, self::STATE_WAITING_FOR_LYRICS, ['music_id' => $musicId]);
        $this->telegramService->sendMessage($chatId, "موزیک دریافت شد. عنوان اولیه: '{$title}', خواننده اولیه: '{$artist}'.\nاکنون لطفاً متن موزیک را ارسال کنید (می‌توانید بعداً عنوان و خواننده را ویرایش کنید):");
    }

    public function handleLyrics(int $chatId, int $adminId, string $lyricsText, int $musicId): void
    {
        $updated = Database::executeQuery("UPDATE musics SET lyrics = ? WHERE id = ?", [$lyricsText, $musicId]);
        if (!$updated || $updated->rowCount() === 0) {
            $this->telegramService->sendMessage($chatId, "خطا در ذخیره متن موزیک. لطفاً دوباره تلاش کنید یا موزیک را از ابتدا ارسال کنید.");
            $this->clearAdminState($adminId);
            $this->sendMainMenu($chatId);
            return;
        }
        $this->clearAdminState($adminId);
        $this->sendMusicPreviewToAdmin($chatId, $musicId);
    }

    public function sendMusicPreviewToAdmin(int $chatId, int $musicId): void
    {
        $music = Database::fetchOne("SELECT file_id, title, artist, lyrics FROM musics WHERE id = ?", [$musicId]);
        if (!$music) {
            $this->telegramService->sendMessage($chatId, "موزیک مورد نظر یافت نشد.");
            return;
        }
        $titleDisplay = $music['title'] ?? 'بدون عنوان';
        $artistDisplay = $music['artist'] ?? 'ناشناس';
        $caption = "🎵 *" . $this->escapeMarkdown($titleDisplay) . "*";
        if ($artistDisplay !== 'ناشناس' && !empty($artistDisplay)) {
            $caption .= "\n👤 خواننده: *" . $this->escapeMarkdown($artistDisplay) . "*";
        }
        if ($music['lyrics']) {
            $normalized_lyrics = str_replace(["\r\n", "\r", "\n"], ' ', $music['lyrics']);
            $summary = mb_substr($normalized_lyrics, 0, 150);
            $caption .= "\n\n📜 خلاصه متن:\n" . trim($this->escapeMarkdown($summary)) . (mb_strlen($normalized_lyrics) > 150 ? '...' : '');
        } else {
            $caption .= "\n\n(متن ترانه وارد نشده است)";
        }
        $inlineKeyboard = TelegramService::createInlineKeyboard([
            [['text' => '🎼 ویرایش فایل', 'callback_data' => "edit_file_{$musicId}"], ['text' => '📝 ویرایش متن', 'callback_data' => "edit_lyrics_{$musicId}"]],
            [['text' => '🗑️ حذف موزیک', 'callback_data' => "delete_music_{$musicId}"]],
            [['text' => '🎤 ویرایش خواننده', 'callback_data' => "edit_artist_{$musicId}"], ['text' => '🎶 ویرایش عنوان', 'callback_data' => "edit_title_{$musicId}"]],
            [['text' => '📲 ارسال به کانال', 'callback_data' => "request_chcaption_{$musicId}"],]
        ]);
        $this->telegramService->sendAudio($chatId, $music['file_id'], ['caption' => $caption, 'parse_mode' => 'Markdown', 'reply_markup' => $inlineKeyboard]);
    }

    public function requestChannelCaption(int $adminChatId, int $adminId, int $musicId, string $callbackQueryId, int $originalMessageId): void
    {
        $this->telegramService->answerCallbackQuery($callbackQueryId);
        $music = Database::fetchOne("SELECT title FROM musics WHERE id = ?", [$musicId]);
        if (!$music) {
            $this->telegramService->sendMessage($adminChatId, "خطا: موزیک مورد نظر برای ارسال به کانال یافت نشد.");
            error_log("requestChannelCaption: Music not found for music_id {$musicId}, admin {$adminId}");
            return;
        }
        $musicTitle = $this->escapeMarkdown($music['title'] ?? 'موزیک');
        $messageText = "شما می‌خواهید موزیک \"{$musicTitle}\" را به کانال ارسال کنید.\n\n";
        $messageText .= "لطفاً کپشن مورد نظر خود را برای این موزیک در کانال ارسال کنید.\n";
        $messageText .= "می‌توانید از فرمت HTML برای استایل‌دهی متن (مانند `<b>متن بولد</b>`, `<i>متن ایتالیک</i>`, `<a href='URL'>لینک</a>`) استفاده کنید.\n";
        $messageText .= "برای ارسال موزیک بدون کپشن، دستور `/emptycaption` را ارسال کنید.\n\n";
        $messageText .= "برای لغو این عملیات، روی دکمه زیر کلیک کنید.";
        $keyboard = TelegramService::createInlineKeyboard([
            [['text' => '❌ لغو ارسال به کانال', 'callback_data' => "cancel_chcaption_{$musicId}_{$originalMessageId}"]]
        ]);
        $promptMessage = $this->telegramService->sendMessage($adminChatId, $messageText, ['parse_mode' => 'Markdown', 'reply_markup' => $keyboard]);
        if ($promptMessage->isOk()) {
            $this->setAdminState($adminId, self::STATE_WAITING_FOR_CHANNEL_CAPTION, [
                'music_id' => $musicId,
                'original_preview_message_id' => $originalMessageId,
                'caption_prompt_message_id' => $promptMessage->getResult()->getMessageId()
            ]);
        } else {
            error_log("Failed to send channel caption prompt for music_id {$musicId}, admin {$adminId}: " . $promptMessage->getDescription());
            $this->telegramService->sendMessage($adminChatId, "خطایی در شروع فرآیند ارسال به کانال رخ داد. لطفاً دوباره تلاش کنید.");
        }
    }

    public function handleChannelCaptionInput(int $chatId, int $adminId, string $textInput, array $stateData): void
    {
        $musicId = $stateData['music_id'] ?? null;
        $promptMessageId = $stateData['caption_prompt_message_id'] ?? null;
        $originalPreviewMessageId = $stateData['original_preview_message_id'] ?? null;

        if (!$musicId || !$promptMessageId || !$originalPreviewMessageId) {
            error_log("handleChannelCaptionInput: Missing data in stateData for admin {$adminId}. Data: " . print_r($stateData, true));
            $this->telegramService->sendMessage($chatId, "خطای داخلی: اطلاعات لازم برای پردازش کپشن یافت نشد. لطفاً دوباره تلاش کنید.");
            $this->clearAdminState($adminId);
            return;
        }

        $captionForChannel = (trim(strtolower($textInput)) === '/emptycaption') ? '' : $textInput;

        $this->telegramService->editMessageText($chatId, $promptMessageId, "کپشن دریافت شد. لطفاً پیش‌نمایش زیر را بررسی و تأیید کنید:", ['reply_markup' => null]);

        $music = Database::fetchOne("SELECT file_id, title FROM musics WHERE id = ?", [$musicId]);
        if (!$music) {
            $this->telegramService->sendMessage($chatId, "خطا: موزیک برای پیش‌نمایش کپشن یافت نشد.");
            $this->clearAdminState($adminId);
            return;
        }

        // Send the audio file again with the proposed channel caption for admin to preview
        // The actual caption sent to channel will use HTML parse_mode.
        $previewData = [
            'caption' => $captionForChannel, // Send raw caption, will be parsed as HTML by Telegram
            'parse_mode' => 'HTML',
        ];
        if ($captionForChannel === '') {
            // If caption is empty, don't send 'caption' or 'parse_mode' at all
            // or ensure API handles empty caption with parse_mode correctly.
            // For sendAudio, an empty caption is fine.
            unset($previewData['caption']);
            unset($previewData['parse_mode']); // No parse_mode needed if no caption
        }

        $previewKeyboard = TelegramService::createInlineKeyboard([
            [['text' => '✅ ارسال نهایی به کانال', 'callback_data' => "finalsend_tocanal_{$musicId}"], ['text' => '✏️ ویرایش کپشن', 'callback_data' => "retry_chcaption_{$musicId}_{$originalPreviewMessageId}"]],
            [['text' => '❌ لغو کامل عملیات', 'callback_data' => "cancel_sendprocess_{$musicId}_{$originalPreviewMessageId}_{$promptMessageId}"]] // Add promptMessageId for cleanup
        ]);
        $previewData['reply_markup'] = $previewKeyboard;

        $captionPreviewMessage = $this->telegramService->sendAudio($chatId, $music['file_id'], $previewData);

        if ($captionPreviewMessage->isOk()) {
            $this->setAdminState($adminId, self::STATE_CONFIRM_CHANNEL_POST, [
                'music_id' => $musicId,
                'channel_caption' => $captionForChannel,
                'original_preview_message_id' => $originalPreviewMessageId, // To potentially restore it later
                'caption_request_message_id' => $promptMessageId, // ID of "Please enter caption" message
                'caption_confirm_preview_message_id' => $captionPreviewMessage->getResult()->getMessageId() // ID of this confirmation message (audio with buttons)
            ]);
        } else {
            error_log("Failed to send caption preview message for music_id {$musicId}, admin {$adminId}: " . $captionPreviewMessage->getDescription());
            $this->telegramService->sendMessage($chatId, "خطایی در نمایش پیش‌نمایش کپشن رخ داد.");
            $this->clearAdminState($adminId);
            // $this->sendMusicPreviewToAdmin($chatId, $musicId); // Resend original preview if needed
        }
    }

    public function executeFinalSendToChannel(int $adminChatId, int $adminId, int $musicId, string $captionToSend, ?string $callbackQueryId = null, ?int $captionConfirmPreviewMessageId = null): void
    {
        error_log("Executing final send to channel for music_id: {$musicId} by admin: {$adminId}. Caption: " . ($captionToSend === '' ? '[EMPTY]' : substr($captionToSend, 0, 200)));
        $music = Database::fetchOne("SELECT file_id, short_code FROM musics WHERE id = ?", [$musicId]);

        if (!$music) {
            if($callbackQueryId) $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'موزیک یافت نشد!', 'show_alert' => true]);
            else $this->telegramService->sendMessage($adminChatId, 'موزیک یافت نشد!');
            return;
        }
        if (!defined('TARGET_CHANNEL_ID') || empty(TARGET_CHANNEL_ID)) {
            if($callbackQueryId) $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'ID کانال هدف تنظیم نشده است!', 'show_alert' => true]);
            else $this->telegramService->sendMessage($adminChatId, 'ID کانال هدف تنظیم نشده است!');
            error_log("TARGET_CHANNEL_ID is not defined in config for admin " . $adminId);
            return;
        }
        $targetChannelId = TARGET_CHANNEL_ID;
        if (!defined('BOT_USERNAME') || empty(BOT_USERNAME)) {
             if($callbackQueryId) $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'نام کاربری ربات تنظیم نشده است!', 'show_alert' => true]);
             else $this->telegramService->sendMessage($adminChatId, 'نام کاربری ربات تنظیم نشده است!');
             error_log("BOT_USERNAME is not defined in config for admin " . $adminId);
             return;
        }
        $botUsername = BOT_USERNAME;
        $deepLinkUrl = "https://t.me/{$botUsername}?start={$music['short_code']}";
        $channelInlineKeyboard = TelegramService::createInlineKeyboard([[['text' => ' دریافت متن موزیک 📝', 'url' => $deepLinkUrl]]]);

        $sendData = ['parse_mode' => 'HTML', 'reply_markup' => $channelInlineKeyboard];
        if ($captionToSend !== '') { // Only add caption if it's not empty
            $sendData['caption'] = $captionToSend;
        } else {
            unset($sendData['parse_mode']); // No parse_mode if no caption
        }

        $response = $this->telegramService->sendAudio($targetChannelId, $music['file_id'], $sendData);

        if ($response->isOk()) {
            $messageIdInChannel = $response->getResult()->getMessageId();
            $numericChannelId = is_numeric($targetChannelId) ? (int)$targetChannelId : $response->getResult()->getChat()->getId();
            Database::executeQuery("INSERT INTO channel_posts (music_id, channel_id, message_id) VALUES (?, ?, ?)", [$musicId, $numericChannelId, $messageIdInChannel]);

            if($callbackQueryId) $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'موزیک با موفقیت به کانال ارسال شد.']);

            if($captionConfirmPreviewMessageId) {
                // Edit the caption preview message (which is an audio message)
                $this->telegramService->editMessageCaption($adminChatId, $captionConfirmPreviewMessageId, ['caption' => "✅ موزیک با موفقیت به کانال ارسال شد.", 'reply_markup' => null]);
            } else {
                 $this->telegramService->sendMessage($adminChatId, "✅ موزیک با موفقیت به کانال ارسال شد.");
            }
            $this->clearAdminState($adminId);
        } else {
            $errorText = 'خطا در ارسال به کانال: ' . $response->getDescription();
            if($callbackQueryId) $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => $errorText, 'show_alert' => true]);
            else $this->telegramService->sendMessage($adminChatId, $errorText);
            error_log("Failed to send to channel (music_id: {$musicId}): " . $response->getDescription() . " for admin " . $adminId);
        }
    }

    public function cancelChannelCaptionProcess(int $adminChatId, int $adminId, int $musicId, int $promptMessageId, int $originalPreviewMessageId, string $callbackQueryId): void
    {
        $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'ارسال به کانال لغو شد.']);
        $this->clearAdminState($adminId);

        $this->telegramService->editMessageText($adminChatId, $promptMessageId, "ارسال موزیک به کانال لغو شد.", ['reply_markup' => null]);

        // Restore the original music preview by re-sending it.
        // We use originalPreviewMessageId to know which music it was, but we send a new preview.
        // If we wanted to edit the originalPreviewMessageId's buttons (if they were changed), that's more complex.
        // For now, just confirming cancellation and allowing admin to restart from original preview is enough.
        // $this->sendMusicPreviewToAdmin($adminChatId, $musicId); // This sends a new message.
        // Or simply tell them they can use the previous message:
        $this->telegramService->sendMessage($adminChatId, "می‌توانید از پیش‌نمایش اولیه موزیک (پیام قبلی) برای سایر عملیات استفاده کنید یا فرآیند ارسال به کانال را مجدداً آغاز نمایید.");
    }

    public function handleDeepLinkLyricsRequest(int $userChatId, string $shortCode, bool $isAdminContext = false): void
    {
        error_log("DeepLink: handleDeepLinkLyricsRequest called for user {$userChatId} with short_code: {$shortCode}");
        $music = Database::fetchOne("SELECT id, title, artist, lyrics FROM musics WHERE short_code = ?", [$shortCode]);
        if (!$music) {
            error_log("DeepLink: Music not found for short_code: {$shortCode}. User: {$userChatId}");
            $this->telegramService->sendMessage($userChatId, "متاسفانه موزیک درخواستی با این کد یافت نشد.");
            return;
        }
        if (empty($music['lyrics'])) {
            error_log("DeepLink: Lyrics are empty for music_id: {$music['id']} (short_code: {$shortCode}). User: {$userChatId}");
            $this->telegramService->sendMessage($userChatId, "متاسفانه برای این موزیک متنی ثبت نشده است.");
            return;
        }
        $fullLyricsText = "";
        $titleDisplay = $music['title'] ?? 'بدون عنوان';
        $artistDisplay = $music['artist'] ?? 'ناشناس';
        if ($titleDisplay !== 'بدون عنوان' && !empty($titleDisplay)) {
             $fullLyricsText .= "🎵 *" . $this->escapeMarkdown($titleDisplay) . "*\n";
        }
        if ($artistDisplay !== 'ناشناس' && !empty($artistDisplay)) {
             $fullLyricsText .= "👤 خواننده: *" . $this->escapeMarkdown($artistDisplay) . "*\n";
        }
        $fullLyricsText .= "\n📜 متن کامل موزیک:\n" . $this->escapeMarkdown($music['lyrics']);
        $inlineKeyboard = null;
        $post = Database::fetchOne("SELECT cp.channel_id, cp.message_id FROM channel_posts cp WHERE cp.music_id = ? ORDER BY cp.posted_at DESC LIMIT 1", [$music['id']]);
        $channelLink = null;
        if ($post) {
            $channelIdentifier = null;
            if (defined('TARGET_CHANNEL_PUBLIC_USERNAME') && !empty(TARGET_CHANNEL_PUBLIC_USERNAME)) {
                $channelIdentifier = TARGET_CHANNEL_PUBLIC_USERNAME;
            }
            elseif (is_string(TARGET_CHANNEL_ID) && str_starts_with(TARGET_CHANNEL_ID, '@')) {
                $channelIdentifier = substr(TARGET_CHANNEL_ID, 1);
            }
            if ($channelIdentifier) {
                 $channelLink = "https://t.me/{$channelIdentifier}/{$post['message_id']}";
            } elseif (is_numeric($post['channel_id']) && $post['channel_id'] < -1000000000000) {
                 $chatIdWithoutPrefix = substr((string)$post['channel_id'], 4);
                 $channelLink = "https://t.me/c/{$chatIdWithoutPrefix}/{$post['message_id']}";
            }
            if ($channelLink) {
                 $inlineKeyboard = TelegramService::createInlineKeyboard([[['text' => '👁️ مشاهده موزیک در کانال', 'url' => $channelLink]]]);
            } else {
                error_log("DeepLink: Cannot create channel link for music ID {$music['id']}. Channel ID in DB: " . ($post['channel_id'] ?? 'N/A') . ". Post ID: " . ($post['message_id'] ?? 'N/A') . ". User: {$userChatId}");
            }
        } else {
            error_log("DeepLink: No channel post found for music_id: {$music['id']}. User: {$userChatId}");
        }
        error_log("DeepLink: Sending lyrics for music_id: {$music['id']} to user: {$userChatId}. Text length: " . mb_strlen($fullLyricsText));
        $this->sendLongMessage($userChatId, $fullLyricsText, ['parse_mode' => 'Markdown', 'reply_markup' => $inlineKeyboard]);
    }

    public function confirmDeleteMusic(int $chatId, int $messageId, int $adminId, int $musicId, string $callbackQueryId): void {
        error_log("Confirming delete for music_id: {$musicId}, msg_id: {$messageId}, chat_id: {$chatId}");
        $keyboard = TelegramService::createInlineKeyboard([[['text' => 'بله، مطمئنم', 'callback_data' => "confirmdelete_music_{$musicId}"], ['text' => 'خیر، لغو کن', 'callback_data' => "canceldelete_music_{$musicId}"],]]);
        $response = $this->telegramService->editMessageReplyMarkup($chatId, $messageId, $keyboard);
        if (!$response->isOk()) {
            error_log("Failed to edit message reply markup for delete confirmation: " . $response->getDescription() . " ChatID: {$chatId}, MsgID: {$messageId}");
            $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'خطا در نمایش تاییدیه حذف.', 'show_alert' => true]);
            return;
        }
        $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => "آیا از حذف این موزیک مطمئن هستید؟ این عمل قابل بازگشت نیست.", 'show_alert' => true]);
    }

    public function executeDeleteMusic(int $chatId, int $messageId, int $adminId, int $musicId, string $callbackQueryId): void {
        error_log("Executing delete for music_id: {$musicId}, msg_id: {$messageId}, chat_id: {$chatId}");
        $music = Database::fetchOne("SELECT title FROM musics WHERE id = ?", [$musicId]);
        $musicTitle = $music ? $this->escapeMarkdown($music['title']) : "این موزیک";
        $deletedStmt = Database::executeQuery("DELETE FROM musics WHERE id = ?", [$musicId]);
        if ($deletedStmt && $deletedStmt->rowCount() > 0) {
            error_log("Successfully deleted music_id: {$musicId} from database.");
            $captionEditResponse = $this->telegramService->editMessageCaption($chatId, $messageId, ['caption' => "موزیک '{$musicTitle}' با موفقیت حذف شد. ✅", 'reply_markup' => null]);
            if (!$captionEditResponse->isOk()){
                error_log("Failed to edit caption for deleted music {$musicId}: " . $captionEditResponse->getDescription() . ". ChatID: {$chatId}, MsgID: {$messageId}");
                $this->telegramService->sendMessage($chatId, "موزیک '{$musicTitle}' با موفقیت از پایگاه داده حذف شد (خطا در به‌روزرسانی پیام اصلی).");
            }
            $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => "موزیک '{$musicTitle}' حذف شد."]);
        } else {
            error_log("Failed to delete music_id: {$musicId} from database or already deleted. RowCount: " . ($deletedStmt ? $deletedStmt->rowCount() : 'N/A'));
            $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => "خطا در حذف {$musicTitle} از پایگاه داده!", 'show_alert' => true]);
            $this->restorePreviewButtons($chatId, $messageId, $musicId);
        }
    }

    private function restorePreviewButtons(int $chatId, int $messageId, int $musicId): void
    {
        error_log("Restoring preview buttons for music_id: {$musicId} on msg_id: {$messageId}");
        $music = Database::fetchOne("SELECT id FROM musics WHERE id = ?", [$musicId]);
        if ($music) {
            $originalKeyboard = TelegramService::createInlineKeyboard([
                [['text' => '🎼 ویرایش فایل', 'callback_data' => "edit_file_{$musicId}"], ['text' => '📝 ویرایش متن', 'callback_data' => "edit_lyrics_{$musicId}"]],
                [['text' => '🗑️ حذف موزیک', 'callback_data' => "delete_music_{$musicId}"]],
                [['text' => '🎤 ویرایش خواننده', 'callback_data' => "edit_artist_{$musicId}"], ['text' => '🎶 ویرایش عنوان', 'callback_data' => "edit_title_{$musicId}"]],
                [['text' => '📲 ارسال به کانال', 'callback_data' => "request_chcaption_{$musicId}"]]
            ]);
            $response = $this->telegramService->editMessageReplyMarkup($chatId, $messageId, $originalKeyboard);
            if (!$response->isOk()) {
                error_log("Failed to restore reply markup for music_id {$musicId} on msg_id {$messageId}: " . $response->getDescription());
                $this->sendMusicPreviewToAdmin($chatId, $musicId);
            }
        } else {
            $this->telegramService->editMessageReplyMarkup($chatId, $messageId, null);
            $this->telegramService->editMessageCaption($chatId, $messageId, ['caption' => "خطا: موزیک یافت نشد، امکان بازگردانی دکمه‌های پیش‌نمایش وجود ندارد."]);
        }
    }

    public function cancelDeleteMusic(int $chatId, int $messageId, int $musicId, string $callbackQueryId): void {
        error_log("Cancelling delete for music_id: {$musicId}, msg_id: {$messageId}, chat_id: {$chatId}");
        $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'حذف لغو شد.']);
        $music = Database::fetchOne("SELECT file_id, title, artist, lyrics FROM musics WHERE id = ?", [$musicId]);
        if ($music) {
            $titleDisplay = $music['title'] ?? 'بدون عنوان';
            $artistDisplay = $music['artist'] ?? 'ناشناس';
            $caption = "🎵 *" . $this->escapeMarkdown($titleDisplay) . "*";
            if ($artistDisplay !== 'ناشناس' && !empty($artistDisplay)) {
                $caption .= "\n👤 خواننده: *" . $this->escapeMarkdown($artistDisplay) . "*";
            }
            if ($music['lyrics']) {
                $normalized_lyrics = str_replace(["\r\n", "\r", "\n"], ' ', $music['lyrics']);
                $summary = mb_substr($normalized_lyrics, 0, 150);
                $caption .= "\n\n📜 خلاصه متن:\n" . trim($this->escapeMarkdown($summary)) . (mb_strlen($normalized_lyrics) > 150 ? '...' : '');
            } else {
                $caption .= "\n\n(متن ترانه وارد نشده است)";
            }
            $originalKeyboard = TelegramService::createInlineKeyboard([
                [['text' => '🎼 ویرایش فایل', 'callback_data' => "edit_file_{$musicId}"], ['text' => '📝 ویرایش متن', 'callback_data' => "edit_lyrics_{$musicId}"]],
                [['text' => '🗑️ حذف موزیک', 'callback_data' => "delete_music_{$musicId}"]],
                [['text' => '🎤 ویرایش خواننده', 'callback_data' => "edit_artist_{$musicId}"], ['text' => '🎶 ویرایش عنوان', 'callback_data' => "edit_title_{$musicId}"]],
                [['text' => '📲 ارسال به کانال', 'callback_data' => "request_chcaption_{$musicId}"]]
            ]);
            $response = $this->telegramService->editMessageCaption($chatId, $messageId, ['caption' => $caption, 'reply_markup' => $originalKeyboard]);
            if (!$response->isOk()){
                 error_log("Failed to restore caption and reply markup for music_id {$musicId} on msg_id {$messageId} during cancel delete: " . $response->getDescription());
                 $this->telegramService->sendMessage($chatId, "عملیات حذف لغو شد. نمایش مجدد پیش‌نمایش...");
                 $this->sendMusicPreviewToAdmin($chatId, $musicId);
            }
        } else {
            error_log("Music not found for music_id {$musicId} during cancel delete. Cannot restore preview for msg_id {$messageId}.");
            $this->telegramService->editMessageReplyMarkup($chatId, $messageId, null);
            $captionEditResponse = $this->telegramService->editMessageCaption($chatId, $messageId, ['caption' => "عملیات حذف لغو شد. موزیک اصلی برای بازگردانی پیش‌نمایش یافت نشد."]);
            if (!$captionEditResponse->isOk()) {
                $this->telegramService->sendMessage($chatId, "عملیات حذف لغو شد. موزیک اصلی برای بازگردانی پیش‌نمایش یافت نشد.");
            }
        }
    }

    public function requestNewLyrics(int $chatId, int $messageIdToAck, int $adminId, int $musicId, string $callbackQueryId): void {
        $this->telegramService->answerCallbackQuery($callbackQueryId);
        $keyboard = TelegramService::createInlineKeyboard([[['text' => '❌ لغو ویرایش متن', 'callback_data' => "canceledit_lyrics_{$musicId}"]]]);
        $promptMessage = $this->telegramService->sendMessage($chatId, "لطفاً متن جدید موزیک را ارسال کنید:", ['reply_markup' => $keyboard]);
        if ($promptMessage->isOk()) {
            $this->setAdminState($adminId, self::STATE_WAITING_FOR_NEW_LYRICS, ['music_id' => $musicId, 'prompt_message_id' => $promptMessage->getResult()->getMessageId()]);
        } else {
            error_log("Failed to send prompt message for new lyrics: " . $promptMessage->getDescription());
            $this->telegramService->sendMessage($chatId, "خطایی در شروع عملیات ویرایش متن رخ داد.");
        }
    }

    public function handleNewLyrics(int $chatId, int $adminId, string $newLyrics, int $musicId): void {
        $stateData = $this->getAdminStateData($adminId);
        Database::executeQuery("UPDATE musics SET lyrics = ? WHERE id = ?", [$newLyrics, $musicId]);
        $this->clearAdminState($adminId);
        if (isset($stateData['prompt_message_id'])) {
             $this->telegramService->editMessageText($chatId, $stateData['prompt_message_id'], "متن جدید دریافت شد. ✅");
             $this->telegramService->editMessageReplyMarkup($chatId, $stateData['prompt_message_id'], null);
        }
        $this->sendMusicPreviewToAdmin($chatId, $musicId);
    }

    public function cancelEditLyrics(int $chatId, int $promptMessageIdToEdit, int $adminId, int $musicId, string $callbackQueryId): void {
        $this->clearAdminState($adminId);
        $this->telegramService->editMessageText($chatId, $promptMessageIdToEdit, "ویرایش متن لغو شد.");
        $this->telegramService->editMessageReplyMarkup($chatId, $promptMessageIdToEdit, null);
        $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'ویرایش متن لغو شد.']);
    }

    public function requestNewMusicFile(int $chatId, int $messageIdToAck, int $adminId, int $musicId, string $callbackQueryId): void {
        $this->telegramService->answerCallbackQuery($callbackQueryId);
        $keyboard = TelegramService::createInlineKeyboard([[['text' => '❌ لغو ویرایش فایل', 'callback_data' => "canceledit_file_{$musicId}"]]]);
        $promptMessage = $this->telegramService->sendMessage($chatId, "لطفاً فایل موزیک جدید را ارسال کنید:", ['reply_markup' => $keyboard]);
        if ($promptMessage->isOk()) {
            $this->setAdminState($adminId, self::STATE_WAITING_FOR_NEW_FILE, ['music_id' => $musicId, 'prompt_message_id' => $promptMessage->getResult()->getMessageId()]);
        } else {
            error_log("Failed to send prompt message for new music file: " . $promptMessage->getDescription());
            $this->telegramService->sendMessage($chatId, "خطایی در شروع عملیات ویرایش فایل موزیک رخ داد.");
        }
    }

    public function handleNewMusicFile(int $chatId, int $adminId, array $audioData, int $musicId): void {
        $stateData = $this->getAdminStateData($adminId);
        $fileId = $audioData['file_id'];
        $fileUniqueId = $audioData['file_unique_id'];
        $title = $audioData['title'] ?? null;
        $artist = $audioData['performer'] ?? null;
        $updateQuery = "UPDATE musics SET file_id = ?, file_unique_id = ?";
        $params = [$fileId, $fileUniqueId];
        if ($title !== null) { $updateQuery .= ", title = ?"; $params[] = $title; }
        if ($artist !== null) { $updateQuery .= ", artist = ?"; $params[] = $artist; }
        $updateQuery .= " WHERE id = ?"; $params[] = $musicId;
        Database::executeQuery($updateQuery, $params);
        $this->clearAdminState($adminId);
        if (isset($stateData['prompt_message_id'])) {
             $this->telegramService->editMessageText($chatId, $stateData['prompt_message_id'], "فایل جدید دریافت شد. ✅");
             $this->telegramService->editMessageReplyMarkup($chatId, $stateData['prompt_message_id'], null);
        }
        $this->sendMusicPreviewToAdmin($chatId, $musicId);
    }

    public function cancelEditFile(int $chatId, int $promptMessageIdToEdit, int $adminId, int $musicId, string $callbackQueryId): void {
        $this->clearAdminState($adminId);
        $this->telegramService->editMessageText($chatId, $promptMessageIdToEdit, "ویرایش فایل موزیک لغو شد.");
        $this->telegramService->editMessageReplyMarkup($chatId, $promptMessageIdToEdit, null);
        $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'ویرایش فایل لغو شد.']);
    }

    public function requestNewArtistName(int $chatId, int $messageIdToAck, int $adminId, int $musicId, string $callbackQueryId): void {
        $this->telegramService->answerCallbackQuery($callbackQueryId);
        $keyboard = TelegramService::createInlineKeyboard([[['text' => '❌ لغو ویرایش خواننده', 'callback_data' => "canceledit_artist_{$musicId}"]]]);
        $promptMessage = $this->telegramService->sendMessage($chatId, "لطفاً نام جدید خواننده را ارسال کنید:", ['reply_markup' => $keyboard]);
        if ($promptMessage->isOk()) {
            $this->setAdminState($adminId, self::STATE_WAITING_FOR_ARTIST_NAME, ['music_id' => $musicId, 'prompt_message_id' => $promptMessage->getResult()->getMessageId()]);
        } else {
            error_log("Failed to send prompt message for new artist name: " . $promptMessage->getDescription());
            $this->telegramService->sendMessage($chatId, "خطایی در شروع عملیات ویرایش نام خواننده رخ داد.");
        }
    }

    public function handleNewArtistName(int $chatId, int $adminId, string $newArtistName, int $musicId): void {
        $stateData = $this->getAdminStateData($adminId);
        Database::executeQuery("UPDATE musics SET artist = ? WHERE id = ?", [$newArtistName, $musicId]);
        $this->clearAdminState($adminId);
        if (isset($stateData['prompt_message_id'])) {
             $this->telegramService->editMessageText($chatId, $stateData['prompt_message_id'], "نام خواننده دریافت شد. ✅");
             $this->telegramService->editMessageReplyMarkup($chatId, $stateData['prompt_message_id'], null);
        }
        $this->sendMusicPreviewToAdmin($chatId, $musicId);
    }

    public function cancelEditArtistName(int $chatId, int $promptMessageIdToEdit, int $adminId, int $musicId, string $callbackQueryId): void {
        $this->clearAdminState($adminId);
        $this->telegramService->editMessageText($chatId, $promptMessageIdToEdit, "ویرایش نام خواننده لغو شد.");
        $this->telegramService->editMessageReplyMarkup($chatId, $promptMessageIdToEdit, null);
        $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'ویرایش نام خواننده لغو شد.']);
    }

    public function requestNewTitleName(int $chatId, int $messageIdToAck, int $adminId, int $musicId, string $callbackQueryId): void {
        $this->telegramService->answerCallbackQuery($callbackQueryId);
        $keyboard = TelegramService::createInlineKeyboard([[['text' => '❌ لغو ویرایش عنوان', 'callback_data' => "canceledit_title_{$musicId}"]]]);
        $promptMessage = $this->telegramService->sendMessage($chatId, "لطفاً عنوان جدید موزیک را ارسال کنید:", ['reply_markup' => $keyboard]);
        if ($promptMessage->isOk()) {
            $this->setAdminState($adminId, self::STATE_WAITING_FOR_TITLE_NAME, ['music_id' => $musicId, 'prompt_message_id' => $promptMessage->getResult()->getMessageId()]);
        } else {
            error_log("Failed to send prompt message for new title name: " . $promptMessage->getDescription());
            $this->telegramService->sendMessage($chatId, "خطایی در شروع عملیات ویرایش عنوان موزیک رخ داد.");
        }
    }

    public function handleNewTitleName(int $chatId, int $adminId, string $newTitleName, int $musicId): void {
        $stateData = $this->getAdminStateData($adminId);
        Database::executeQuery("UPDATE musics SET title = ? WHERE id = ?", [$newTitleName, $musicId]);
        $this->clearAdminState($adminId);
        if (isset($stateData['prompt_message_id'])) {
             $this->telegramService->editMessageText($chatId, $stateData['prompt_message_id'], "عنوان موزیک دریافت شد. ✅");
             $this->telegramService->editMessageReplyMarkup($chatId, $stateData['prompt_message_id'], null);
        }
        $this->sendMusicPreviewToAdmin($chatId, $musicId);
    }

    public function cancelEditTitleName(int $chatId, int $promptMessageIdToEdit, int $adminId, int $musicId, string $callbackQueryId): void {
        $this->clearAdminState($adminId);
        $this->telegramService->editMessageText($chatId, $promptMessageIdToEdit, "ویرایش عنوان موزیک لغو شد.");
        $this->telegramService->editMessageReplyMarkup($chatId, $promptMessageIdToEdit, null);
        $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'ویرایش عنوان موزیک لغو شد.']);
    }

    private function setAdminState(int $adminId, string $state, array $data = []): void
    {
        $jsonData = !empty($data) ? json_encode($data) : null;
        Database::executeQuery(
            "INSERT INTO admin_states (admin_id, state, data) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE state = VALUES(state), data = VALUES(data)",
            [$adminId, $state, $jsonData]
        );
    }

    private function getAdminState(int $adminId): ?array
    {
        $row = Database::fetchOne("SELECT state, data FROM admin_states WHERE admin_id = ?", [$adminId]);
        return $row ? ['state' => $row['state'], 'data' => $row['data'] ? json_decode($row['data'], true) : []] : null;
    }

    private function getAdminStateData(int $adminId): ?array
    {
        $state = $this->getAdminState($adminId);
        return $state['data'] ?? null;
    }

    private function clearAdminState(int $adminId): void
    {
        Database::executeQuery("DELETE FROM admin_states WHERE admin_id = ?", [$adminId]);
    }

    private function generateShortCode(int $length = 6): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $code = '';
        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $characters[rand(0, strlen($characters) - 1)];
            }
            $exists = Database::fetchOne("SELECT id FROM musics WHERE short_code = ?", [$code]);
        } while ($exists);
        return $code;
    }

    private function sendLongMessage(int $chatId, string $text, array $options = []): void
    {
        $maxLength = 4096;
        if (mb_strlen($text) <= $maxLength) {
            $this->telegramService->sendMessage($chatId, $text, $options);
            return;
        }

        $parts = [];
        $currentPart = '';
        if (strpos($text, "\n\n") !== false) {
            $paragraphs = explode("\n\n", $text);
            foreach ($paragraphs as $paragraph) {
                 if (mb_strlen($currentPart) + mb_strlen($paragraph) + 2 > $maxLength) {
                    if (!empty($currentPart)) $parts[] = $currentPart;
                    $currentPart = $paragraph;
                } else {
                    $currentPart .= (empty($currentPart) ? '' : "\n\n") . $paragraph;
                }
            }
        } else {
            $sentences = preg_split('/(?<=[.!?\n])\s*/', $text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
            foreach ($sentences as $sentence) {
                if (mb_strlen($currentPart) + mb_strlen($sentence) + 1 > $maxLength) {
                    if (!empty($currentPart)) $parts[] = $currentPart;
                    $currentPart = $sentence;
                } else {
                    $currentPart .= (str_ends_with($currentPart, "\n") || empty($currentPart) ? '' : ' ') . $sentence;
                }
            }
        }
        if (!empty($currentPart)) {
            $parts[] = $currentPart;
        }

        if (empty($parts) || max(array_map('mb_strlen', $parts)) > $maxLength) {
            error_log("sendLongMessage: Fallback to hard character split for chatID {$chatId}, text length " . mb_strlen($text));
            $parts = [];
            for ($i = 0; $i < mb_strlen($text); $i += $maxLength) {
                $parts[] = mb_substr($text, $i, $maxLength);
            }
        }

        $totalParts = count($parts);
        error_log("sendLongMessage: Splitting message into {$totalParts} parts for chatID {$chatId}.");
        foreach ($parts as $index => $part) {
            if ($index === $totalParts - 1) {
                $this->telegramService->sendMessage($chatId, $part, $options);
            } else {
                $intermediateOptions = $options;
                unset($intermediateOptions['reply_markup']);
                $this->telegramService->sendMessage($chatId, $part, $intermediateOptions);
            }
            if ($totalParts > 1 && $index < $totalParts - 1) {
                usleep(500000);
            }
        }
    }

    private function escapeMarkdown(string $text): string
    {
        $escapedText = str_replace('\\', '\\\\', $text);
        $escapeChars = ['_', '*', '`', '['];

        foreach ($escapeChars as $char) {
            $escapedText = str_replace($char, '\\' . $char, $escapedText);
        }
        return $escapedText;
    }

    public function showMusicList(int $chatId, int $page = 1, int $itemsPerPage = 5, ?int $messageIdToEdit = null): void
    {
        if ($page < 1) $page = 1;
        if (!in_array($itemsPerPage, [5, 10, 15, 20])) $itemsPerPage = 5;

        $offset = ($page - 1) * $itemsPerPage;

        $totalMusicsResult = Database::fetchOne("SELECT COUNT(*) as count FROM musics");
        $totalMusics = $totalMusicsResult ? (int)$totalMusicsResult['count'] : 0;
        $totalPages = $totalMusics > 0 ? ceil($totalMusics / $itemsPerPage) : 1;
        if ($page > $totalPages) $page = $totalPages;

        $musics = Database::fetchAll(
            "SELECT title, artist, short_code FROM musics ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$itemsPerPage, $offset]
        );

        $messageText = "🎧 *لیست موزیک‌ها* (صفحه {$page} از {$totalPages})\n\n";

        if (empty($musics)) {
            $messageText .= "موزیکی برای نمایش یافت نشد.";
        } else {
            foreach ($musics as $index => $music) {
                $titleDisplay = $this->escapeMarkdown($music['title'] ?? 'بدون عنوان');
                $artistDisplay = $this->escapeMarkdown($music['artist'] ?? 'ناشناس');
                $messageText .= ($offset + $index + 1) . ". {$titleDisplay} - {$artistDisplay}\n";
                $messageText .= "   کامند: `/music_{$music['short_code']}`\n\n";
            }
        }

        $inlineKeyboardRows = [];

        $paginationButtons = [];
        if ($page > 1) {
            $paginationButtons[] = ['text' => "⬅️ قبلی", 'callback_data' => "listmusic_page_" . ($page - 1) . "_{$itemsPerPage}"];
        }
        if ($page < $totalPages) {
            $paginationButtons[] = ['text' => "➡️ بعدی", 'callback_data' => "listmusic_page_" . ($page + 1) . "_{$itemsPerPage}"];
        }
        if (!empty($paginationButtons)) {
            $inlineKeyboardRows[] = $paginationButtons;
        }

        $itemsPerPageButtons = [];
        $counts = [5, 10, 15, 20];
        foreach ($counts as $count) {
            $itemsPerPageButtons[] = [
                'text' => ($count == $itemsPerPage ? "🔸" : "") . $count . " تایی",
                'callback_data' => "listmusic_setcount_{$count}_{$page}"
            ];
        }
        if (!empty($itemsPerPageButtons)) {
            $inlineKeyboardRows[] = $itemsPerPageButtons;
        }

        $replyMarkup = null;
        if (!empty($inlineKeyboardRows)) {
            $replyMarkup = TelegramService::createInlineKeyboard($inlineKeyboardRows);
        }

        if ($messageIdToEdit) {
            $response = $this->telegramService->editMessageText($chatId, $messageIdToEdit, $messageText, [
                'parse_mode' => 'Markdown',
                'reply_markup' => $replyMarkup
            ]);
            if (!$response->isOk()) {
                error_log("Failed to edit music list message: " . $response->getDescription());
                $this->telegramService->sendMessage($chatId, $messageText, [
                    'parse_mode' => 'Markdown',
                    'reply_markup' => $replyMarkup
                ]);
            }
        } else {
            $this->telegramService->sendMessage($chatId, $messageText, [
                'parse_mode' => 'Markdown',
                'reply_markup' => $replyMarkup
            ]);
        }
    }

    public function showMusicDetailsByShortCode(int $chatId, string $shortCode): void
    {
        error_log("Attempting to show music details for short_code: {$shortCode} in chat {$chatId}");
        $music = Database::fetchOne("SELECT id FROM musics WHERE short_code = ?", [$shortCode]);

        if ($music && isset($music['id'])) {
            $this->sendMusicPreviewToAdmin($chatId, (int)$music['id']);
        } else {
            error_log("Music with short_code {$shortCode} not found for chat {$chatId}");
            $this->telegramService->sendMessage($chatId, "موزیکی با کد `{$this->escapeMarkdown($shortCode)}` یافت نشد.", ['parse_mode' => 'Markdown']);
        }
    }
}
