<?php

declare(strict_types=1);

namespace App\Events;

use App\Assistant\DirectChat;
use App\Assistant\VoiceMessage;
use App\EventAbstract;
use Discord\Discord;
use Discord\Parts\Channel\Message;

class MessageCreate extends EventAbstract
{
    /**
     * Has Claude answer direct messages, including voice messages. Nothing happens for messages in servers.
     *
     * @param Message $message Message event object
     * @param Discord $discord Discord class
     *
     * @return void
     */
    public function answerDirectMessage(Message $message, Discord $discord)
    {
        // Bots are never answered, which includes this bot's own messages. Neither are messages
        // without text, such as a lone attachment, unless it is a voice message.
        if ($message->guild_id !== null || $message->author === null || $message->author->bot || ($message->content === '' && ! VoiceMessage::isOne($message))) {
            return;
        }

        DirectChat::receive($message, $discord);
    }
}
