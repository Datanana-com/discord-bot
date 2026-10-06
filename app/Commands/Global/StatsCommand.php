<?php

declare(strict_types=1);

namespace App\Commands\Global;

use App\Analytics\Usage;
use App\CommandAbstract;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Interaction;

final class StatsCommand extends CommandAbstract
{
    public string $description = 'Shows how this server has used the bot.';

    public function handle(Interaction $interaction): void
    {
        $interaction->respondWithMessage(MessageBuilder::new()->setContent($this->report($interaction->guild_id)), ephemeral: true);
    }

    private function report(?string $guildId): string
    {
        if ($guildId === null) {
            return 'Use /stats in a server.';
        }

        $usage = (new Usage($this->log))->summary($guildId);

        if ($usage === null) {
            return 'The statistics are not available right now. Check the bot logs.';
        }

        if ($usage['since'] === null) {
            return 'Nothing has been recorded in this server yet.';
        }

        return implode("\n", [
            // Discord shows the date in each reader's own time zone.
            sprintf('**Usage in this server** since <t:%d:D>', strtotime("{$usage['since']} UTC")),
            sprintf('Calls recorded: %d (%s)', $usage['calls'], self::duration($usage['call_ms'])),
            sprintf('Speech: %d utterances from %d people (%s)', $usage['utterances'], $usage['speakers'], self::duration($usage['speech_ms'])),
            sprintf('Questions answered: %d', $usage['answers'])
                . ($usage['answer_ms'] === null ? '' : sprintf(', in %.1f s on average', $usage['answer_ms'] / 1000)),
            sprintf('Looked up: %d', $usage['lookups']),
            sprintf('Failures: %d', $usage['failures']),
        ]);
    }

    private static function duration(int $ms): string
    {
        $seconds = (int) round($ms / 1000);

        return match (true) {
            $seconds < 60 => "{$seconds} s",
            $seconds < 3600 => intdiv($seconds, 60) . ' min',
            default => intdiv($seconds, 3600) . ' h ' . intdiv($seconds % 3600, 60) . ' min',
        };
    }
}
