<?php

declare(strict_types=1);

namespace Tests\Unit\Privacy;

use App\Analytics\Usage;
use App\Privacy\OptOuts;
use Illuminate\Database\Capsule\Manager as DB;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\UsesStatsDatabase;

final class OptOutsTest extends TestCase
{
    use UsesStatsDatabase;

    private OptOuts $optOuts;

    protected function setUp(): void
    {
        $this->useStatsDatabase();
        $this->optOuts = new OptOuts();
    }

    public function testNobodyHasOptedOutAtFirst(): void
    {
        $this->assertSame([], $this->optOuts->all());
    }

    public function testRemembersWhoOptedOut(): void
    {
        $this->assertTrue($this->optOuts->add('555'));
        $this->assertTrue($this->optOuts->add('666'));

        // Another instance reads the same list: it is in the database, like after a restart.
        $this->assertSame(['555', '666'], (new OptOuts())->all());
    }

    public function testOptingOutTwiceChangesNothing(): void
    {
        $this->optOuts->add('555');

        $this->assertFalse($this->optOuts->add('555'));
        $this->assertSame(['555'], $this->optOuts->all());
    }

    public function testOptingBackInOnlyRemovesThatPerson(): void
    {
        $this->optOuts->add('555');
        $this->optOuts->add('666');

        $this->assertTrue($this->optOuts->remove('555'));
        $this->assertSame(['666'], $this->optOuts->all());

        // They can opt out again later.
        $this->assertTrue($this->optOuts->add('555'));
    }

    public function testOptingBackInWithoutHavingOptedOutChangesNothing(): void
    {
        $this->optOuts->add('666');

        $this->assertFalse($this->optOuts->remove('555'));
        $this->assertSame(['666'], $this->optOuts->all());
    }

    public function testKeepsOnlyWhoOptedOutAndWhen(): void
    {
        $this->optOuts->add('555');

        // Next to the usage statistics, with the person as the primary key.
        $connection = DB::connection(Usage::CONNECTION);
        $this->assertSame(['user_id', 'created_at'], $connection->getSchemaBuilder()->getColumnListing('opt_outs'));
        $this->assertSame(
            [['columns' => ['user_id'], 'primary' => true]],
            array_map(fn (array $index) => array_intersect_key($index, ['columns' => 0, 'primary' => 0]), array_values($connection->getSchemaBuilder()->getIndexes('opt_outs'))),
        );

        $row = $connection->table('opt_outs')->first();
        $this->assertSame('555', $row->user_id);
        $this->assertEqualsWithDelta(time(), strtotime("{$row->created_at} UTC"), 60, 'When they opted out, in UTC.');
    }

    public function testNeverHidesThatTheListIsUnavailable(): void
    {
        $this->breakStatsDatabase();

        foreach (['all', 'add', 'remove'] as $method) {
            try {
                $this->optOuts->{$method}('555');
                $this->fail("{$method}() should have failed.");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Database connection [stats] not configured.', $e->getMessage());
            }
        }
    }
}
