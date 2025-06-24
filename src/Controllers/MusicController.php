<?php

namespace TelegramMusicBot\Controllers;

use TelegramMusicBot\Services\TelegramService;
use TelegramMusicBot\Core\Database; // To interact with the database
// use TelegramMusicBot\Entities\Music; // Assuming an entity class for Music later - Not used yet

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


    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }

    /**
     * Sends the main menu keyboard to the admin.
     * @param int $chatId
     */
    public function sendMainMenu(int $chatId): void
    {
        $keyboard = TelegramService::createReplyKeyboard([
            ['ارسال موزیک'],
        ], true, true);

        $this->telegramService->sendMessage($chatId, "چه کاری می‌خواهید انجام دهید؟ لطفاً یکی از گزینه‌های زیر را انتخاب کنید:", [
            'reply_markup' => $keyboard
        ]);
    }

    /**
     * Initiates the process of adding a new music by requesting the music file.
     * @param int $chatId
     * @param int $adminId
     */
    public function requestMusicFile(int $chatId, int $adminId): void
    {
        $this->setAdminState($adminId, self::STATE_WAITING_FOR_MUSIC_FILE);
        $this->telegramService->sendMessage($chatId, "لطفاً فایل موزیک را ارسال کنید:");
    }

    /**
     * Handles the received music file.
     * @param int $chatId
     * @param int $adminId
     * @param array $audioData Telegram audio entity
     */
    public function handleMusicFile(int $chatId, int $adminId, array $audioData): void
    {
        $fileId = $audioData['file_id'];
        $fileUniqueId = $audioData['file_unique_id'];
        $title = $audioData['title'] ?? ($audioData['file_name'] ?? 'بدون عنوان');
        // Ensure artist is not empty, default to 'ناشناس' if performer is missing or empty
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

    /**
     * Handles the received lyrics for a music.
     * @param int $chatId
     * @param int $adminId
     * @param string $lyricsText
     * @param int $musicId
     */
    public function handleLyrics(int $chatId, int $adminId, string $lyricsText, int $musicId): void
    {
        $updated = Database::executeQuery(
            "UPDATE musics SET lyrics = ? WHERE id = ?",
            [$lyricsText, $musicId]
        );

        if (!$updated || $updated->rowCount() === 0) {
            $this->telegramService->sendMessage($chatId, "خطا در ذخیره متن موزیک. لطفاً دوباره تلاش کنید یا موزیک را از ابتدا ارسال کنید.");
            $this->clearAdminState($adminId); // Clear state even on failure to avoid loop
            $this->sendMainMenu($chatId); // Send back to main menu
            return;
        }

        $this->clearAdminState($adminId);
        // $this->telegramService->sendMessage($chatId, "متن موزیک ذخیره شد."); // This message can be removed as preview follows
        $this->sendMusicPreviewToAdmin($chatId, $musicId);
    }


    /**
     * Sends a preview of the music with management buttons to the admin.
     * @param int $chatId Admin's chat ID.
     * @param int $musicId The ID of the music in the database.
     */
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

        // Final button layout as per user guidance
        $inlineKeyboard = TelegramService::createInlineKeyboard([
            [ // Row 1: Edit file, Edit lyrics
                ['text' => '🎼 ویرایش فایل', 'callback_data' => "edit_file_{$musicId}"],
                ['text' => '📝 ویرایش متن', 'callback_data' => "edit_lyrics_{$musicId}"],
            ],
            [ // Row 2: Delete music
                ['text' => '🗑️ حذف موزیک', 'callback_data' => "delete_music_{$musicId}"],
            ],
            [ // Row 3: Edit artist and title
                ['text' => '🎤 ویرایش خواننده', 'callback_data' => "edit_artist_{$musicId}"],
                ['text' => '🎶 ویرایش عنوان', 'callback_data' => "edit_title_{$musicId}"],
            ],
            [ // Row 4: Send to channel
                ['text' => '📲 ارسال به کانال', 'callback_data' => "send_tochannel_{$musicId}"],
            ]
        ]);

        $this->telegramService->sendAudio($chatId, $music['file_id'], [
            'caption' => $caption,
            'parse_mode' => 'Markdown',
            'reply_markup' => $inlineKeyboard
        ]);
    }

    /**
     * Sends music to the target channel.
     * @param int $adminChatId
     * @param int $adminId
     * @param int $musicId
     * @param string $callbackQueryId
     */
    public function sendToChannel(int $adminChatId, int $adminId, int $musicId, string $callbackQueryId): void
    {
        $music = Database::fetchOne("SELECT file_id, short_code, title, artist FROM musics WHERE id = ?", [$musicId]);

        if (!$music) {
            $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'موزیک یافت نشد!', 'show_alert' => true]);
            return;
        }

        if (!defined('TARGET_CHANNEL_ID') || empty(TARGET_CHANNEL_ID)) {
            $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'ID کانال هدف تنظیم نشده است!', 'show_alert' => true]);
            error_log("TARGET_CHANNEL_ID is not defined in config for admin " . $adminId);
            return;
        }
        $targetChannelId = TARGET_CHANNEL_ID;

        if (!defined('BOT_USERNAME') || empty(BOT_USERNAME)) {
             $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'نام کاربری ربات تنظیم نشده است!', 'show_alert' => true]);
             error_log("BOT_USERNAME is not defined in config for admin " . $adminId);
             return;
        }
        $botUsername = BOT_USERNAME;
        $deepLinkUrl = "https://t.me/{$botUsername}?start={$music['short_code']}";

        $channelCaption = "";
        $titleDisplay = $music['title'] ?? 'بدون عنوان';
        $artistDisplay = $music['artist'] ?? 'ناشناس';

        if ($titleDisplay !== 'بدون عنوان' && !empty($titleDisplay)) {
            $channelCaption .= "🎵 " . htmlspecialchars($titleDisplay);
        }
        if ($artistDisplay !== 'ناشناس' && !empty($artistDisplay)) {
            $channelCaption .= ($channelCaption ? " - " : "") . "👤 " . htmlspecialchars($artistDisplay);
        }

        $inlineKeyboard = TelegramService::createInlineKeyboard([
            [['text' => ' دریافت متن موزیک 📝', 'url' => $deepLinkUrl]]
        ]);

        $response = $this->telegramService->sendAudio($targetChannelId, $music['file_id'], [
            'caption' => $channelCaption,
            'parse_mode' => 'HTML',
            'reply_markup' => $inlineKeyboard
        ]);

        if ($response->isOk()) {
            $messageId = $response->getResult()->getMessageId();
            $numericChannelId = is_numeric($targetChannelId) ? (int)$targetChannelId : $response->getResult()->getChat()->getId();

            Database::executeQuery(
                "INSERT INTO channel_posts (music_id, channel_id, message_id) VALUES (?, ?, ?)",
                [$musicId, $numericChannelId, $messageId]
            );
            $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'موزیک با موفقیت به کانال ارسال شد.']);
        } else {
            $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'خطا در ارسال به کانال: ' . $response->getDescription(), 'show_alert' => true]);
            error_log("Failed to send to channel (music_id: {$musicId}): " . $response->getDescription() . " for admin " . $adminId);
        }
    }

    /**
     * Handles user request for lyrics via deep link.
     * @param int $userChatId
     * @param string $shortCode
     * @param bool $isAdminContext
     */
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
        $post = Database::fetchOne(
            "SELECT cp.channel_id, cp.message_id
             FROM channel_posts cp
             WHERE cp.music_id = ? ORDER BY cp.posted_at DESC LIMIT 1",
            [$music['id']]
        );

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
                 $inlineKeyboard = TelegramService::createInlineKeyboard([
                    [['text' => '👁️ مشاهده موزیک در کانال', 'url' => $channelLink]]
                ]);
            } else {
                error_log("DeepLink: Cannot create channel link for music ID {$music['id']}. Channel ID in DB: " . ($post['channel_id'] ?? 'N/A') . ". Post ID: " . ($post['message_id'] ?? 'N/A') . ". User: {$userChatId}");
            }
        } else {
            error_log("DeepLink: No channel post found for music_id: {$music['id']}. User: {$userChatId}");
        }

        error_log("DeepLink: Sending lyrics for music_id: {$music['id']} to user: {$userChatId}. Text length: " . mb_strlen($fullLyricsText));
        $this->sendLongMessage($userChatId, $fullLyricsText, ['parse_mode' => 'Markdown', 'reply_markup' => $inlineKeyboard]);
    }


    // --- Edit and Delete Logic ---
    public function confirmDeleteMusic(int $chatId, int $messageId, int $adminId, int $musicId, string $callbackQueryId): void {
        $keyboard = TelegramService::createInlineKeyboard([
            [
                ['text' => 'بله، مطمئنم', 'callback_data' => "confirmdelete_music_{$musicId}"],
                ['text' => 'خیر، لغو کن', 'callback_data' => "canceldelete_music_{$musicId}"],
            ]
        ]);
        $this->telegramService->editMessageText($chatId, $messageId, "آیا از حذف این موزیک مطمئن هستید؟ این عمل قابل بازگشت نیست.", [
            'reply_markup' => $keyboard
        ]);
        $this->telegramService->answerCallbackQuery($callbackQueryId);
    }

    public function executeDeleteMusic(int $chatId, int $messageId, int $adminId, int $musicId, string $callbackQueryId): void {
        $deleted = Database::executeQuery("DELETE FROM musics WHERE id = ?", [$musicId]);
        if ($deleted && $deleted->rowCount() > 0) {
            $this->telegramService->editMessageText($chatId, $messageId, "موزیک با موفقیت حذف شد.");
            $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'موزیک حذف شد.']);
        } else {
            $this->telegramService->editMessageText($chatId, $messageId, "خطا در حذف موزیک. ممکن است قبلاً حذف شده باشد.");
            $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'خطا در حذف!', 'show_alert' => true]);
        }
    }

    public function cancelDeleteMusic(int $chatId, int $messageId, int $musicId, string $callbackQueryId): void {
        $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'حذف لغو شد.']);
        // $this->telegramService->editMessageText($chatId, $messageId, "عملیات حذف موزیک لغو شد. نمایش مجدد پیش‌نمایش...");
        // Edit the message to remove confirmation buttons and state cancellation, then send new preview.
        $this->telegramService->editMessageReplyMarkup($chatId, $messageId, null); // Remove buttons
        $this->telegramService->editMessageText($chatId, $messageId, "عملیات حذف موزیک لغو شد.");
        $this->sendMusicPreviewToAdmin($chatId, $musicId);
    }


    public function requestNewLyrics(int $chatId, int $messageIdToAck, int $adminId, int $musicId, string $callbackQueryId): void {
        $this->telegramService->answerCallbackQuery($callbackQueryId);

        $keyboard = TelegramService::createInlineKeyboard([
            [['text' => '❌ لغو ویرایش متن', 'callback_data' => "canceledit_lyrics_{$musicId}"]]
        ]);
        $promptMessage = $this->telegramService->sendMessage($chatId, "لطفاً متن جدید موزیک را ارسال کنید:", ['reply_markup' => $keyboard]);

        if ($promptMessage->isOk()) {
            $this->setAdminState($adminId, self::STATE_WAITING_FOR_NEW_LYRICS, [
                'music_id' => $musicId,
                'prompt_message_id' => $promptMessage->getResult()->getMessageId()
            ]);
        } else {
            error_log("Failed to send prompt message for new lyrics: " . $promptMessage->getDescription());
            $this->telegramService->sendMessage($chatId, "خطایی در شروع عملیات ویرایش متن رخ داد.");
        }
    }

    public function handleNewLyrics(int $chatId, int $adminId, string $newLyrics, int $musicId): void {
        $stateData = $this->getAdminStateData($adminId);

        Database::executeQuery("UPDATE musics SET lyrics = ? WHERE id = ?", [$newLyrics, $musicId]);
        $this->clearAdminState($adminId);

        // $this->telegramService->sendMessage($chatId, "متن موزیک با موفقیت به‌روزرسانی شد."); // Removed, preview is enough

        if (isset($stateData['prompt_message_id'])) {
             $this->telegramService->editMessageText(
                 $chatId,
                 $stateData['prompt_message_id'],
                 "متن جدید دریافت شد. ✅"
             );
             $this->telegramService->editMessageReplyMarkup($chatId, $stateData['prompt_message_id'], null); // Remove "cancel" button
        }
        $this->sendMusicPreviewToAdmin($chatId, $musicId);
    }

    public function cancelEditLyrics(int $chatId, int $promptMessageIdToEdit, int $adminId, int $musicId, string $callbackQueryId): void {
        $this->clearAdminState($adminId);

        $this->telegramService->editMessageText(
            $chatId,
            $promptMessageIdToEdit,
            "ویرایش متن لغو شد."
        );
        $this->telegramService->editMessageReplyMarkup($chatId, $promptMessageIdToEdit, null); // Remove "cancel" button
        $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'ویرایش متن لغو شد.']);
    }


    public function requestNewMusicFile(int $chatId, int $messageIdToAck, int $adminId, int $musicId, string $callbackQueryId): void {
        $this->telegramService->answerCallbackQuery($callbackQueryId);

        $keyboard = TelegramService::createInlineKeyboard([
            [['text' => '❌ لغو ویرایش فایل', 'callback_data' => "canceledit_file_{$musicId}"]]
        ]);
        $promptMessage = $this->telegramService->sendMessage($chatId, "لطفاً فایل موزیک جدید را ارسال کنید:", ['reply_markup' => $keyboard]);

        if ($promptMessage->isOk()) {
            $this->setAdminState($adminId, self::STATE_WAITING_FOR_NEW_FILE, [
                'music_id' => $musicId,
                'prompt_message_id' => $promptMessage->getResult()->getMessageId()
            ]);
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

        if ($title !== null) {
            $updateQuery .= ", title = ?";
            $params[] = $title;
        }
        if ($artist !== null) {
            $updateQuery .= ", artist = ?";
            $params[] = $artist;
        }
        $updateQuery .= " WHERE id = ?";
        $params[] = $musicId;

        Database::executeQuery($updateQuery, $params);

        $this->clearAdminState($adminId);
        // $this->telegramService->sendMessage($chatId, "فایل موزیک با موفقیت به‌روزرسانی شد."); // Removed, preview is enough

        if (isset($stateData['prompt_message_id'])) {
             $this->telegramService->editMessageText(
                 $chatId,
                 $stateData['prompt_message_id'],
                 "فایل جدید دریافت شد. ✅"
             );
             $this->telegramService->editMessageReplyMarkup($chatId, $stateData['prompt_message_id'], null);
        }
        $this->sendMusicPreviewToAdmin($chatId, $musicId);
    }

    public function cancelEditFile(int $chatId, int $promptMessageIdToEdit, int $adminId, int $musicId, string $callbackQueryId): void {
        $this->clearAdminState($adminId);

        $this->telegramService->editMessageText(
            $chatId,
            $promptMessageIdToEdit,
            "ویرایش فایل موزیک لغو شد."
        );
        $this->telegramService->editMessageReplyMarkup($chatId, $promptMessageIdToEdit, null);
        $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'ویرایش فایل لغو شد.']);
    }

    // --- Edit Artist Name Logic ---
    public function requestNewArtistName(int $chatId, int $messageIdToAck, int $adminId, int $musicId, string $callbackQueryId): void {
        $this->telegramService->answerCallbackQuery($callbackQueryId);

        $keyboard = TelegramService::createInlineKeyboard([
            [['text' => '❌ لغو ویرایش خواننده', 'callback_data' => "canceledit_artist_{$musicId}"]]
        ]);
        $promptMessage = $this->telegramService->sendMessage($chatId, "لطفاً نام جدید خواننده را ارسال کنید:", ['reply_markup' => $keyboard]);

        if ($promptMessage->isOk()) {
            $this->setAdminState($adminId, self::STATE_WAITING_FOR_ARTIST_NAME, [
                'music_id' => $musicId,
                'prompt_message_id' => $promptMessage->getResult()->getMessageId()
            ]);
        } else {
            error_log("Failed to send prompt message for new artist name: " . $promptMessage->getDescription());
            $this->telegramService->sendMessage($chatId, "خطایی در شروع عملیات ویرایش نام خواننده رخ داد.");
        }
    }

    public function handleNewArtistName(int $chatId, int $adminId, string $newArtistName, int $musicId): void {
        $stateData = $this->getAdminStateData($adminId);

        Database::executeQuery("UPDATE musics SET artist = ? WHERE id = ?", [$newArtistName, $musicId]);
        $this->clearAdminState($adminId);

        // $this->telegramService->sendMessage($chatId, "نام خواننده با موفقیت به‌روزرسانی شد."); // Removed

        if (isset($stateData['prompt_message_id'])) {
             $this->telegramService->editMessageText(
                 $chatId,
                 $stateData['prompt_message_id'],
                 "نام خواننده دریافت شد. ✅"
             );
             $this->telegramService->editMessageReplyMarkup($chatId, $stateData['prompt_message_id'], null);
        }
        $this->sendMusicPreviewToAdmin($chatId, $musicId);
    }

    public function cancelEditArtistName(int $chatId, int $promptMessageIdToEdit, int $adminId, int $musicId, string $callbackQueryId): void {
        $this->clearAdminState($adminId);

        $this->telegramService->editMessageText(
            $chatId,
            $promptMessageIdToEdit,
            "ویرایش نام خواننده لغو شد."
        );
        $this->telegramService->editMessageReplyMarkup($chatId, $promptMessageIdToEdit, null);
        $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'ویرایش نام خواننده لغو شد.']);
    }

    // --- Edit Title Name Logic ---
    public function requestNewTitleName(int $chatId, int $messageIdToAck, int $adminId, int $musicId, string $callbackQueryId): void {
        $this->telegramService->answerCallbackQuery($callbackQueryId);

        $keyboard = TelegramService::createInlineKeyboard([
            [['text' => '❌ لغو ویرایش عنوان', 'callback_data' => "canceledit_title_{$musicId}"]]
        ]);
        $promptMessage = $this->telegramService->sendMessage($chatId, "لطفاً عنوان جدید موزیک را ارسال کنید:", ['reply_markup' => $keyboard]);

        if ($promptMessage->isOk()) {
            $this->setAdminState($adminId, self::STATE_WAITING_FOR_TITLE_NAME, [
                'music_id' => $musicId,
                'prompt_message_id' => $promptMessage->getResult()->getMessageId()
            ]);
        } else {
            error_log("Failed to send prompt message for new title name: " . $promptMessage->getDescription());
            $this->telegramService->sendMessage($chatId, "خطایی در شروع عملیات ویرایش عنوان موزیک رخ داد.");
        }
    }

    public function handleNewTitleName(int $chatId, int $adminId, string $newTitleName, int $musicId): void {
        $stateData = $this->getAdminStateData($adminId);

        Database::executeQuery("UPDATE musics SET title = ? WHERE id = ?", [$newTitleName, $musicId]);
        $this->clearAdminState($adminId);

        // $this->telegramService->sendMessage($chatId, "عنوان موزیک با موفقیت به‌روزرسانی شد."); // Removed

        if (isset($stateData['prompt_message_id'])) {
             $this->telegramService->editMessageText(
                 $chatId,
                 $stateData['prompt_message_id'],
                 "عنوان موزیک دریافت شد. ✅"
             );
             $this->telegramService->editMessageReplyMarkup($chatId, $stateData['prompt_message_id'], null);
        }
        $this->sendMusicPreviewToAdmin($chatId, $musicId);
    }

    public function cancelEditTitleName(int $chatId, int $promptMessageIdToEdit, int $adminId, int $musicId, string $callbackQueryId): void {
        $this->clearAdminState($adminId);

        $this->telegramService->editMessageText(
            $chatId,
            $promptMessageIdToEdit,
            "ویرایش عنوان موزیک لغو شد."
        );
        $this->telegramService->editMessageReplyMarkup($chatId, $promptMessageIdToEdit, null);
        $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'ویرایش عنوان موزیک لغو شد.']);
    }


    // --- Helper methods for state management ---
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
        if ($row) {
            return ['state' => $row['state'], 'data' => $row['data'] ? json_decode($row['data'], true) : []];
        }
        return null;
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

    /**
     * Generates a short unique code.
     * @param int $length
     * @return string
     */
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

    /**
     * Helper function to send long messages by splitting them.
     * The keyboard is attached to the last message part.
     */
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

    /**
     * Escapes Markdown special characters for Telegram.
     * This version is for 'Markdown' parse mode (not MarkdownV2).
     * @param string $text
     * @return string
     */
    private function escapeMarkdown(string $text): string
    {
        $escapedText = str_replace('\\', '\\\\', $text);
        $escapeChars = ['_', '*', '`', '['];

        foreach ($escapeChars as $char) {
            $escapedText = str_replace($char, '\\' . $char, $escapedText);
        }
        return $escapedText;
    }
}
