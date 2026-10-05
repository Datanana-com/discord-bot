<?php

declare(strict_types=1);

namespace App\Commands\Global;

use App\CommandAbstract;
use App\Privacy\OptOuts;
use App\Voice\VoiceSession;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;
use Throwable;

final class OptoutCommand extends CommandAbstract
{
    private const string OPTED_OUT = 'You opted out: I no longer record, transcribe or answer you, in any server.'
        . ' If I am recording a call you are in, what you say from now on is dropped and your recording of it is deleted; what was already transcribed stays.'
        . ' Use /optin to undo this.';

    private const string ALREADY_OPTED_OUT = 'You had already opted out: I don\'t record, transcribe or answer you. Use /optin to undo this.';

    private const string NOT_SAVED = 'I couldn\'t save that you opted out, so it only counts for the calls I am recording right now. Try again later.';

    public string $description = 'Stops the bot from recording, transcribing or answering you, in every server.';

    public function handle(Interaction $interaction): void
    {
        $userId = (string) $interaction->user->id;

        // Calls in progress come first, so they stop recording them even when the opt-out can't be saved.
        VoiceSession::optOut($userId);

        try {
            $reply = (new OptOuts())->add($userId) ? self::OPTED_OUT : self::ALREADY_OPTED_OUT;
        } catch (Throwable $e) {
            $this->log->error('Could not save an opt-out: ' . $e->getMessage(), ['user' => $userId]);
            $reply = self::NOT_SAVED;
        }

        $interaction->respondWithMessage(MessageBuilder::new()->setContent($reply), ephemeral: true);
    }
}
