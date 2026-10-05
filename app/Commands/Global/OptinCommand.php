<?php

declare(strict_types=1);

namespace App\Commands\Global;

use App\CommandAbstract;
use App\Privacy\OptOuts;
use App\Voice\VoiceSession;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;
use Throwable;

final class OptinCommand extends CommandAbstract
{
    private const string OPTED_IN = 'You opted back in: I record, transcribe and answer you again, in every server.'
        . ' If I am recording a call you are in, I transcribe and answer you from now on, but only record you again once you rejoin it.'
        . ' Use /optout to undo this.';

    private const string NOT_OPTED_OUT = 'You hadn\'t opted out, so nothing changed: I record, transcribe and answer you. Use /optout to stop that.';

    private const string NOT_SAVED = 'I couldn\'t opt you back in right now, so you are still opted out. Try again later.';

    public string $description = 'Lets the bot record, transcribe and answer you again, after /optout.';

    public function handle(Interaction $interaction): void
    {
        $userId = (string) $interaction->user->id;

        try {
            $reply = (new OptOuts())->remove($userId) ? self::OPTED_IN : self::NOT_OPTED_OUT;
            // Only now that it is saved: calls in progress never record someone the list says opted out.
            VoiceSession::optIn($userId);
        } catch (Throwable $e) {
            $this->log->error('Could not remove an opt-out: ' . $e->getMessage(), ['user' => $userId]);
            $reply = self::NOT_SAVED;
        }

        $interaction->respondWithMessage(MessageBuilder::new()->setContent($reply), ephemeral: true);
    }
}
