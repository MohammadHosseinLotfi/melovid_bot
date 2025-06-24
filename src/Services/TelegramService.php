<?php

namespace TelegramMusicBot\Services;

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Exception\TelegramException;

class TelegramService
{
    protected Telegram $telegram;

    public function __construct()
    {
        if (!defined('BOT_TOKEN') || !defined('BOT_USERNAME')) {
            throw new \Exception("Bot token or username is not defined in config.");
        }
        try {
            $this->telegram = new Telegram(BOT_TOKEN, BOT_USERNAME);
        } catch (TelegramException $e) {
            error_log("Error creating Telegram object: " . $e->getMessage());
            throw $e; // Re-throw to be handled by caller
        }
    }

    /**
     * Get the Telegram API instance.
     * @return Telegram
     */
    public function getTelegram(): Telegram
    {
        return $this->telegram;
    }

    /**
     * Send a text message.
     *
     * @param int|string $chat_id
     * @param string $text
     * @param array $data Additional parameters like parse_mode, reply_markup, etc.
     * @return ServerResponse
     */
    public function sendMessage(int|string $chat_id, string $text, array $data = []): ServerResponse
    {
        $payload = array_merge(['chat_id' => $chat_id, 'text' => $text], $data);
        return Request::sendMessage($payload);
    }

    /**
     * Send an audio file.
     *
     * @param int|string $chat_id
     * @param string $audioFileIdOrUrl File ID or URL of the audio.
     * @param array $data Additional parameters like caption, parse_mode, reply_markup, etc.
     * @return ServerResponse
     */
    public function sendAudio(int|string $chat_id, string $audioFileIdOrUrl, array $data = []): ServerResponse
    {
        $payload = array_merge(['chat_id' => $chat_id, 'audio' => $audioFileIdOrUrl], $data);
        return Request::sendAudio($payload);
    }

    /**
     * Edit message text.
     *
     * @param int|string $chat_id
     * @param int $message_id
     * @param string $text
     * @param array $data Additional parameters like parse_mode, reply_markup, etc.
     * @return ServerResponse
     */
    public function editMessageText(int|string $chat_id, int $message_id, string $text, array $data = []): ServerResponse
    {
        $payload = array_merge([
            'chat_id'    => $chat_id,
            'message_id' => $message_id,
            'text'       => $text,
        ], $data);
        return Request::editMessageText($payload);
    }
    
    /**
     * Edit message reply markup.
     *
     * @param int|string $chat_id
     * @param int $message_id
     * @param InlineKeyboard|Keyboard|null $reply_markup
     * @return ServerResponse
     */
    public function editMessageReplyMarkup(int|string $chat_id, int $message_id, $reply_markup = null): ServerResponse
    {
        return Request::editMessageReplyMarkup([
            'chat_id'      => $chat_id,
            'message_id'   => $message_id,
            'reply_markup' => $reply_markup,
        ]);
    }


    /**
     * Answer a callback query.
     *
     * @param string $callback_query_id
     * @param array $data Additional parameters like text, show_alert, etc.
     * @return ServerResponse
     */
    public function answerCallbackQuery(string $callback_query_id, array $data = []): ServerResponse
    {
        $payload = array_merge(['callback_query_id' => $callback_query_id], $data);
        return Request::answerCallbackQuery($payload);
    }

    /**
     * Set the webhook for the bot.
     *
     * @param string $webhookUrl
     * @param array $params Additional parameters for setWebhook.
     * @return ServerResponse
     * @throws TelegramException
     */
    public function setWebhook(string $webhookUrl, array $params = []): ServerResponse
    {
        if (empty($webhookUrl)) {
            throw new \InvalidArgumentException("Webhook URL cannot be empty.");
        }
        return $this->telegram->setWebhook($webhookUrl, $params);
    }

    /**
     * Delete the webhook for the bot.
     * @return ServerResponse
     * @throws TelegramException
     */
    public function deleteWebhook(): ServerResponse
    {
        return $this->telegram->deleteWebhook();
    }

    /**
     * Get Webhook Info.
     * @return ServerResponse
     */
    public function getWebhookInfo(): ServerResponse
    {
        return Request::getWebhookInfo();
    }

    /**
     * A simple helper to create a reply keyboard.
     *
     * @param array $buttons Array of button texts or button arrays.
     * @param bool $oneTime
     * @param bool $resize
     * @param bool $selective
     * @return Keyboard
     */
    public static function createReplyKeyboard(array $buttons, bool $oneTime = false, bool $resize = true, bool $selective = false): Keyboard
    {
        $keyboard = new Keyboard(...$buttons); // Spread operator for rows of buttons
        $keyboard->setResizeKeyboard($resize)
                 ->setOneTimeKeyboard($oneTime)
                 ->setSelective($selective);
        return $keyboard;
    }

    /**
     * A simple helper to create an inline keyboard.
     *
     * @param array $buttons Array of inline button arrays (each an array of button configurations).
     * @return InlineKeyboard
     */
    public static function createInlineKeyboard(array $buttons): InlineKeyboard
    {
        // The InlineKeyboard constructor expects rows of buttons.
        // Each button should be an array like ['text' => 'Button Text', 'callback_data' => 'data']
        return new InlineKeyboard(...$buttons);
    }
}
