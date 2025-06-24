<?php

namespace TelegramMusicBot\Controllers;

use TelegramMusicBot\Services\TelegramService;
use TelegramMusicBot\Core\Database; // To interact with the database
use TelegramMusicBot\Entities\Music; // Assuming an entity class for Music later

class MusicController
{
    private TelegramService $telegramService;
    // private Database $db; // Or use static Database methods

    // Define states for multi-step operations
    public const STATE_WAITING_FOR_MUSIC_FILE = 'waitingForMusicFile';
    public const STATE_WAITING_FOR_LYRICS = 'waitingForLyrics';
    public const STATE_WAITING_FOR_TITLE = 'waitingForTitle'; // Optional
    public const STATE_WAITING_FOR_ARTIST = 'waitingForArtist'; // Optional
    public const STATE_WAITING_FOR_NEW_LYRICS = 'waitingForNewLyrics';
    public const STATE_WAITING_FOR_NEW_FILE = 'waitingForNewFile';


    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
        // $this->db = new Database(); // Or pass PDO, or use static methods
    }

    /**
     * Sends the main menu keyboard to the admin.
     * @param int $chatId
     */
    public function sendMainMenu(int $chatId): void
    {
        $keyboard = TelegramService::createReplyKeyboard([
            ['ارسال موزیک'],
            // ['لیست موزیک‌ها'], // Future feature
            // ['تنظیمات']         // Future feature
        ], true, true);

        $this->telegramService->sendMessage($chatId, "چه کاری می‌خواهید انجام دهید؟", [
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
        // Optionally, add a cancel button to the message or as a reply keyboard option
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
        $artist = $audioData['performer'] ?? 'ناشناس';

        // Store file_id and file_unique_id temporarily in admin state or directly create a preliminary record
        // For now, let's assume we create a record and get an ID, then ask for lyrics.
        // This simplifies state management if lyrics step is interrupted.

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
        $this->telegramService->sendMessage($chatId, "موزیک دریافت شد. اکنون لطفاً متن موزیک را ارسال کنید:");
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
            // Potentially delete the music record if lyrics are critical for it to be valid
            // Database::executeQuery("DELETE FROM musics WHERE id = ?", [$musicId]);
            $this->clearAdminState($adminId);
            $this->sendMainMenu($chatId);
            return;
        }

        $this->clearAdminState($adminId);
        $this->telegramService->sendMessage($chatId, "متن موزیک ذخیره شد.");
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

        $caption = "🎵 *{$music['title']}*";
        if ($music['artist'] && $music['artist'] !== 'ناشناس') {
            $caption .= "\n👤 خواننده: {$music['artist']}";
        }
        if ($music['lyrics']) {
            $summary = mb_substr(str_replace(["\r\n", "\r", "\n"], ' ', $music['lyrics']), 0, 150);
            $caption .= "\n\n📜 خلاصه متن:\n" . $summary . (mb_strlen($music['lyrics']) > 150 ? '...' : '');
        } else {
            $caption .= "\n\n(متن ترانه وارد نشده است)";
        }


        $inlineKeyboard = TelegramService::createInlineKeyboard([
            [
                ['text' => ' ویرایش متن', 'callback_data' => "edit_lyrics_{$musicId}"],
                ['text' => ' ویرایش فایل', 'callback_data' => "edit_file_{$musicId}"],
            ],
            [
                ['text' => ' حذف موزیک', 'callback_data' => "delete_music_{$musicId}"],
                ['text' => ' ارسال به کانال', 'callback_data' => "send_tochannel_{$musicId}"],
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
            error_log("TARGET_CHANNEL_ID is not defined in config.");
            return;
        }
        
        if (!defined('BOT_USERNAME') || empty(BOT_USERNAME)) {
             $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'نام کاربری ربات تنظیم نشده است!', 'show_alert' => true]);
             error_log("BOT_USERNAME is not defined in config.");
             return;
        }

        $botUsername = BOT_USERNAME;
        $deepLinkUrl = "https://t.me/{$botUsername}?start={$music['short_code']}";

        $channelCaption = "";
        if ($music['title'] && $music['title'] !== 'بدون عنوان') {
            $channelCaption .= "🎵 {$music['title']}";
        }
        if ($music['artist'] && $music['artist'] !== 'ناشناس') {
            $channelCaption .= ($channelCaption ? " - " : "") . "👤 {$music['artist']}";
        }
        // Add channel username/link if desired
        // $channelCaption .= "\n\n@" . YOUR_CHANNEL_USERNAME_FOR_DISPLAY;


        $inlineKeyboard = TelegramService::createInlineKeyboard([
            [['text' => ' دریافت متن موزیک', 'url' => $deepLinkUrl]]
        ]);

        $response = $this->telegramService->sendAudio(TARGET_CHANNEL_ID, $music['file_id'], [
            'caption' => $channelCaption,
            'parse_mode' => 'HTML', // Or Markdown, adjust caption accordingly
            'reply_markup' => $inlineKeyboard
        ]);

        if ($response->isOk()) {
            $messageId = $response->getResult()->getMessageId();
            Database::executeQuery(
                "INSERT INTO channel_posts (music_id, channel_id, message_id) VALUES (?, ?, ?)",
                [$musicId, TARGET_CHANNEL_ID, $messageId] // Assuming TARGET_CHANNEL_ID is numeric for DB. If it's @username, resolve it first or store as string.
            );
            $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'موزیک با موفقیت به کانال ارسال شد.']);
            // Optionally, send a confirmation back to admin's chat
            // $this->telegramService->sendMessage($adminChatId, "موزیک '{$music['title']}' به کانال ارسال شد.");
        } else {
            $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'خطا در ارسال به کانال: ' . $response->getDescription(), 'show_alert' => true]);
            error_log("Failed to send to channel: " . $response->getDescription());
        }
    }

    /**
     * Handles user request for lyrics via deep link.
     * @param int $userChatId
     * @param string $shortCode
     */
    public function handleDeepLinkLyricsRequest(int $userChatId, string $shortCode): void
    {
        $music = Database::fetchOne("SELECT id, title, artist, lyrics FROM musics WHERE short_code = ?", [$shortCode]);

        if (!$music || empty($music['lyrics'])) {
            $this->telegramService->sendMessage($userChatId, "متن موزیک درخواستی یافت نشد یا برای این موزیک متنی ثبت نشده است.");
            return;
        }

        // Find the latest post of this music in the channel to link back to it
        $post = Database::fetchOne(
            "SELECT channel_id, message_id FROM channel_posts WHERE music_id = ? ORDER BY posted_at DESC LIMIT 1",
            [$music['id']]
        );
        
        $lyricsText = "🎵 *{$music['title']}*\n";
        if ($music['artist'] && $music['artist'] !== 'ناشناس') {
             $lyricsText .= "👤 خواننده: {$music['artist']}\n";
        }
        $lyricsText .= "\n📜 متن کامل موزیک:\n" . $music['lyrics'];

        $inlineKeyboard = null;
        if ($post) {
            // Need channel username to build the link. This should be in config or fetched.
            // For now, let's assume TARGET_CHANNEL_ID might be @username or we have a separate config for username.
            $channelLinkPart = defined('TARGET_CHANNEL_PUBLIC_USERNAME') && TARGET_CHANNEL_PUBLIC_USERNAME ? TARGET_CHANNEL_PUBLIC_USERNAME : (is_string(TARGET_CHANNEL_ID) && str_starts_with(TARGET_CHANNEL_ID, '@') ? substr(TARGET_CHANNEL_ID, 1) : null);
            
            if ($channelLinkPart) {
                 $messageLink = "https://t.me/{$channelLinkPart}/{$post['message_id']}";
                 $inlineKeyboard = TelegramService::createInlineKeyboard([
                    [['text' => ' مشاهده موزیک در کانال', 'url' => $messageLink]]
                ]);
            } else {
                // If channel is private or username not available, can't make a direct link easily
                // You might need to store channel username in config or fetch it if it's a public channel by ID
                error_log("Cannot create channel link for music ID {$music['id']} as channel username is not available.");
            }
        }
        
        // Split long lyrics if necessary, Telegram has message length limits (4096 chars)
        $maxLength = 4000; // A bit less than actual limit to be safe
        if (mb_strlen($lyricsText) > $maxLength) {
            // Simple split, can be improved
            $parts = str_split($lyricsText, $maxLength);
            foreach ($parts as $index => $part) {
                if ($index === count($parts) - 1 && $inlineKeyboard) { // Add keyboard to the last part
                    $this->telegramService->sendMessage($userChatId, $part, ['parse_mode' => 'Markdown', 'reply_markup' => $inlineKeyboard]);
                } else {
                    $this->telegramService->sendMessage($userChatId, $part, ['parse_mode' => 'Markdown']);
                }
                 usleep(300000); // Small delay between messages
            }
        } else {
            $this->telegramService->sendMessage($userChatId, $lyricsText, ['parse_mode' => 'Markdown', 'reply_markup' => $inlineKeyboard]);
        }
    }


    // --- Edit and Delete Logic Placeholder Methods ---
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
        // CASCADE DELETE should handle channel_posts. If not, delete them manually.
        $deleted = Database::executeQuery("DELETE FROM musics WHERE id = ?", [$musicId]);
        if ($deleted && $deleted->rowCount() > 0) {
            $this->telegramService->editMessageText($chatId, $messageId, "موزیک با موفقیت حذف شد.");
            $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'موزیک حذف شد.']);
        } else {
            $this->telegramService->editMessageText($chatId, $messageId, "خطا در حذف موزیک. ممکن است قبلاً حذف شده باشد.");
            $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'خطا در حذف!', 'show_alert' => true]);
        }
    }
    
    public function cancelDeleteMusic(int $chatId, int $messageId, string $callbackQueryId): void {
        // Restore the original preview or simply state cancellation.
        // For simplicity, we'll just state cancellation.
        // To restore original, we'd need to fetch musicId from callback_data (if passed) or message context.
        // Let's assume the callback data for cancel was "canceldelete_music_MUSICID"
        // For now, just edit the confirmation message.
        $this->telegramService->editMessageText($chatId, $messageId, "عملیات حذف موزیک لغو شد.");
        $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'حذف لغو شد.']);
        // Ideally, re-send the music preview for the specific music ID if possible.
        // This requires the music_id to be part of the cancel callback or fetched differently.
        // If the original message was the music preview itself, we might need to re-fetch and re-render it.
    }


    public function requestNewLyrics(int $chatId, int $messageId, int $adminId, int $musicId, string $callbackQueryId): void {
        $this->setAdminState($adminId, self::STATE_WAITING_FOR_NEW_LYRICS, ['music_id' => $musicId, 'original_message_id' => $messageId]);
        $this->telegramService->answerCallbackQuery($callbackQueryId); // Acknowledge
        
        $keyboard = TelegramService::createInlineKeyboard([
            [['text' => ' لغو ویرایش متن', 'callback_data' => "canceledit_lyrics_{$musicId}"]]
        ]);
        $this->telegramService->sendMessage($chatId, "لطفاً متن جدید موزیک را ارسال کنید:", ['reply_markup' => $keyboard]);
        // It's better to edit the existing message if possible, or send a new one and guide the user.
        // For now, sending a new message. We'll need to handle the original message_id for cleanup or update.
    }
    
    public function handleNewLyrics(int $chatId, int $adminId, string $newLyrics, int $musicId): void {
        $stateData = $this->getAdminStateData($adminId);
        Database::executeQuery("UPDATE musics SET lyrics = ? WHERE id = ?", [$newLyrics, $musicId]);
        $this->clearAdminState($adminId);
        $this->telegramService->sendMessage($chatId, "متن موزیک با موفقیت به‌روزرسانی شد.");
        
        // If original message_id was stored, delete or edit it.
        if (isset($stateData['original_message_id'])) {
             // $this->telegramService->deleteMessage($chatId, $stateData['original_message_id']);
             // Or edit it to say "updated"
        }
        $this->sendMusicPreviewToAdmin($chatId, $musicId); // Send updated preview
    }

    public function cancelEditLyrics(int $chatId, int $messageId, int $adminId, int $musicId, string $callbackQueryId): void {
        $this->clearAdminState($adminId); // Clear waiting_for_new_lyrics state
        // $this->telegramService->editMessageText($chatId, $messageId, "ویرایش متن لغو شد."); // If this was the prompt message
        $this->telegramService->sendMessage($chatId, "ویرایش متن لغو شد."); // Send as new message
        $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'ویرایش متن لغو شد.']);
        $this->sendMusicPreviewToAdmin($chatId, $musicId); // Show original preview again
    }


    public function requestNewMusicFile(int $chatId, int $messageId, int $adminId, int $musicId, string $callbackQueryId): void {
        $this->setAdminState($adminId, self::STATE_WAITING_FOR_NEW_FILE, ['music_id' => $musicId, 'original_message_id' => $messageId]);
        $this->telegramService->answerCallbackQuery($callbackQueryId);

        $keyboard = TelegramService::createInlineKeyboard([
            [['text' => ' لغو ویرایش فایل', 'callback_data' => "canceledit_file_{$musicId}"]]
        ]);
        $this->telegramService->sendMessage($chatId, "لطفاً فایل موزیک جدید را ارسال کنید:", ['reply_markup' => $keyboard]);
    }

    public function handleNewMusicFile(int $chatId, int $adminId, array $audioData, int $musicId): void {
        $stateData = $this->getAdminStateData($adminId);
        $fileId = $audioData['file_id'];
        $fileUniqueId = $audioData['file_unique_id'];
        // Optionally update title/artist if they changed with the new file
        // $title = $audioData['title'] ?? 'بدون عنوان';
        // $artist = $audioData['performer'] ?? 'ناشناس';
        // Database::executeQuery("UPDATE musics SET file_id = ?, file_unique_id = ?, title = ?, artist = ? WHERE id = ?", [$fileId, $fileUniqueId, $title, $artist, $musicId]);
        Database::executeQuery("UPDATE musics SET file_id = ?, file_unique_id = ? WHERE id = ?", [$fileId, $fileUniqueId, $musicId]);
        
        $this->clearAdminState($adminId);
        $this->telegramService->sendMessage($chatId, "فایل موزیک با موفقیت به‌روزرسانی شد.");
        $this->sendMusicPreviewToAdmin($chatId, $musicId);
    }
    
    public function cancelEditFile(int $chatId, int $messageId, int $adminId, int $musicId, string $callbackQueryId): void {
        $this->clearAdminState($adminId);
        $this->telegramService->sendMessage($chatId, "ویرایش فایل موزیک لغو شد.");
        $this->telegramService->answerCallbackQuery($callbackQueryId, ['text' => 'ویرایش فایل لغو شد.']);
        $this->sendMusicPreviewToAdmin($chatId, $musicId);
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
        // Basic unique code generator. For production, ensure it's truly unique by checking DB.
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
}
