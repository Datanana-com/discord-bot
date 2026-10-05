<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Analytics\Usage;
use App\Commands\Global\PrivacyCommand;
use App\Settings\UserSettings;
use Illuminate\Database\Capsule\Manager as DB;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * /privacy: each person chooses when the bot may use their personal memory in a call with other people.
 * Alice is 555 and Bob 666.
 */
final class PrivacyCommandTest extends CommandTestCase
{
    private const string SHOWN_DEFAULT = "**Your privacy settings**\nPersonal memory in calls with other people: `when I ask` (default): I use it whenever you ask me something.";

    private const string SHOWN_AFTER_SHARE = "**Your privacy settings**\nPersonal memory in calls with other people: `only after /share`: I only use it after you use /share.";

    private const string ONLY_AFTER_SHARE = 'Your personal memory will only be used in calls with other people after you use /share.'
        . ' Calls where you are alone with me, group memories and direct messages work as before.';

    private UserSettings $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new UserSettings(new Logger('test'));
    }

    public function testShowsTheSettingAndWhichChoiceIsTheDefault(): void
    {
        $this->assertSame(self::SHOWN_DEFAULT, $this->privacy());

        $this->store->save('555', ['personal_memory_in_calls' => UserSettings::AFTER_SHARE]);

        $this->assertSame(self::SHOWN_AFTER_SHARE, $this->privacy());
        $this->assertSame([], $this->logged('/privacy changed'), 'Looking at the settings changes nothing.');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testChangesTheSettingAndSaysWhatItMeans(): void
    {
        $reply = $this->privacy(UserSettings::AFTER_SHARE);

        $this->assertSame(self::ONLY_AFTER_SHARE, $reply);
        $this->assertSame(['personal_memory_in_calls' => 'after_share'], $this->store->find('555'));
        $this->assertSame(self::SHOWN_AFTER_SHARE, $this->privacy());

        // Going back says what that means too.
        $reply = $this->privacy(UserSettings::WHEN_ASKED);

        $this->assertSame(
            'Your personal memory will be used in calls with other people whenever you ask me something. Use /privacy again to change that.',
            $reply,
        );
        $this->assertSame(UserSettings::DEFAULTS, $this->store->find('555'));
        $this->assertSame(self::SHOWN_DEFAULT, $this->privacy());
    }

    public function testLogsWhoChangedItAndToWhat(): void
    {
        $this->privacy(UserSettings::AFTER_SHARE);
        $this->privacy(UserSettings::WHEN_ASKED, userId: '666');

        // A choice isn't speech: the log says what it was changed to, never anything of the memory.
        $this->assertSame(
            [
                ['user' => '555', 'personal_memory_in_calls' => 'after_share'],
                ['user' => '666', 'personal_memory_in_calls' => 'when_asked'],
            ],
            $this->logged('/privacy changed'),
        );
        $this->assertSame([], $this->loggedProblems());
    }

    public function testEachPersonHasTheirOwn(): void
    {
        $this->privacy(UserSettings::AFTER_SHARE);

        $this->assertSame(self::SHOWN_DEFAULT, $this->privacy(userId: '666'));
        $this->assertSame(UserSettings::DEFAULTS, $this->store->find('666'));
    }

    #[DataProvider('places')]
    public function testWorksInServersAndInDirectMessages(?string $guildId): void
    {
        $reply = $this->privacy(UserSettings::AFTER_SHARE, guildId: $guildId);

        $this->assertSame(self::ONLY_AFTER_SHARE, $reply);
        $this->assertSame(self::SHOWN_AFTER_SHARE, $this->privacy(guildId: $guildId));
        // Nobody needs to be in a call to choose.
        $this->assertSame([], $this->loggedProblems());
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function places(): array
    {
        return ['in a server' => [self::GUILD_ID], 'in a direct message' => [null]];
    }

    public function testRefusesAChoiceItDoesNotKnow(): void
    {
        // Discord can still offer a choice of another version of the command.
        $reply = $this->privacy('whenever');

        $this->assertSame('Personal memory in calls can be `when I ask` or `only after /share`.', $reply);
        $this->assertSame(UserSettings::DEFAULTS, $this->store->find('555'));
        $this->assertSame([], $this->logged('/privacy changed'));
    }

    public function testSaysWhenTheSettingsCannotBeRead(): void
    {
        $this->breakStatsDatabase();

        $this->assertSame('Your privacy settings are not available right now. Check the bot logs.', $this->privacy());
        // A choice is saved without reading what is there, which also can't be done now.
        $this->assertSame('Your privacy settings could not be saved. Check the bot logs.', $this->privacy(UserSettings::AFTER_SHARE));

        $this->assertSame(
            [
                'Could not read the user settings: Database connection [stats] not configured.',
                'Could not read the user settings: Database connection [stats] not configured.',
                'Could not save the user settings: Database connection [stats] not configured.',
            ],
            $this->loggedProblems(),
        );
        $this->assertSame([], $this->logged('/privacy changed'));
    }

    public function testSaysWhenTheSettingsHoldSomethingThatIsNotAChoice(): void
    {
        $this->store->save('555', ['personal_memory_in_calls' => UserSettings::AFTER_SHARE]);
        DB::connection(Usage::CONNECTION)->table('user_settings')->update(['personal_memory_in_calls' => 'whenever']);

        $this->assertSame('Your privacy settings are not available right now. Check the bot logs.', $this->privacy());
        $this->assertSame(['The user settings hold a value that is not a choice.'], $this->loggedProblems());
    }

    public function testAChoiceRepairsSettingsThatCannotBeRead(): void
    {
        $this->store->save('555', ['personal_memory_in_calls' => UserSettings::WHEN_ASKED]);
        DB::connection(Usage::CONNECTION)->table('user_settings')->update(['personal_memory_in_calls' => 'whenever']);

        // Until then calls keep the memory out, so being able to choose again matters.
        $reply = $this->privacy(UserSettings::AFTER_SHARE);

        $this->assertSame(self::ONLY_AFTER_SHARE, $reply);
        $this->assertSame(['personal_memory_in_calls' => 'after_share'], $this->store->find('555'));
        $this->assertSame([['user' => '555', 'personal_memory_in_calls' => 'after_share']], $this->logged('/privacy changed'));
        $this->assertSame(self::SHOWN_AFTER_SHARE, $this->privacy());
    }

    public function testSaysWhenTheSettingCannotBeSaved(): void
    {
        // The database can be read, but not written to.
        $this->store->find('555');
        DB::connection(Usage::CONNECTION)->statement('PRAGMA query_only = ON');

        $reply = $this->privacy(UserSettings::AFTER_SHARE);

        $this->assertSame('Your privacy settings could not be saved. Check the bot logs.', $reply);
        $this->assertCount(1, $this->loggedProblems());
        $this->assertStringStartsWith('Could not save the user settings: ', $this->loggedProblems()[0]);
        $this->assertSame([], $this->logged('/privacy changed'));
    }

    /**
     * Uses /privacy and returns the reply, which only whoever used it sees.
     *
     * @param string|null $choice  The choice of personal_memory_in_calls, or null to only look.
     * @param string|null $guildId The server it was used in, or null for a direct message.
     */
    private function privacy(?string $choice = null, string $userId = '555', ?string $guildId = self::GUILD_ID): string
    {
        $this->responses = [];
        (new PrivacyCommand($this->discord))->handle(
            $this->interaction(null, guildId: $guildId, userId: $userId, choices: $choice === null ? [] : ['personal_memory_in_calls' => $choice]),
        );

        $this->assertCount(1, $this->responses);
        $this->assertTrue($this->responses[0]['ephemeral'], 'Only whoever used /privacy sees the reply.');

        return $this->responses[0]['content'];
    }
}
