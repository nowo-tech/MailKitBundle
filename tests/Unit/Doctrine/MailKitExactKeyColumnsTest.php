<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Doctrine;

use Nowo\MailKitBundle\Doctrine\MailKitExactKeyColumns;
use Nowo\MigrationsKitContracts\Collation\ExactKeyColumn;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SEC-006: the declaration names the key columns of the bundle's four tables on the *configured* table names, and
 * declares the columns of a feature that is off inactive instead of omitting them.
 */
final class MailKitExactKeyColumnsTest extends TestCase
{
    public function testItDeclaresTheKeyColumnsOfTheFourTables(): void
    {
        $columns = (new MailKitExactKeyColumns('', true, true))->exactKeyColumns();

        self::assertSame([
            'mail_kit_tracking_send.send_id',
            'mail_kit_tracking_send.recipient_id',
            'mail_kit_tracking_send.tenant',
            'mail_kit_graph_subscription.subscription_id',
            'mail_kit_graph_subscription.profile',
            'mail_kit_graph_delta.profile',
            'mail_kit_graph_inbound.message_key',
            'mail_kit_graph_inbound.profile',
        ], array_map(static fn (ExactKeyColumn $column): string => $column->describe(), $columns));

        foreach ($columns as $column) {
            self::assertTrue($column->active, $column->describe());
            self::assertNull($column->condition);
            self::assertSame(MailKitExactKeyColumns::OWNER, $column->owner);
            self::assertNotSame('', $column->purpose, $column->describe());
        }
    }

    public function testItResolvesTheConfiguredTablePrefix(): void
    {
        $columns = (new MailKitExactKeyColumns('app_', true, true))->exactKeyColumns();

        foreach ($columns as $column) {
            self::assertStringStartsWith('app_mail_kit_', $column->table);
        }
    }

    public function testTheColumnsOfAFeatureThatIsOffAreDeclaredInactive(): void
    {
        // Both off is the default configuration: nothing is registered, and there is no table either.
        $columns = (new MailKitExactKeyColumns())->exactKeyColumns();

        self::assertCount(8, $columns);
        foreach ($columns as $column) {
            self::assertFalse($column->active, $column->describe());
            self::assertSame(
                str_starts_with($column->table, 'mail_kit_tracking')
                    ? MailKitExactKeyColumns::TRACKING_CONDITION
                    : MailKitExactKeyColumns::GRAPH_INBOUND_CONDITION,
                $column->condition,
                $column->describe(),
            );
        }
    }

    public function testEachFeatureIsGatedOnItsOwnSetting(): void
    {
        $tracking = (new MailKitExactKeyColumns('', true, false))->exactKeyColumns();

        foreach ($tracking as $column) {
            self::assertSame(str_starts_with($column->table, 'mail_kit_tracking'), $column->active, $column->describe());
        }

        $inbound = (new MailKitExactKeyColumns('', false, true))->exactKeyColumns();

        foreach ($inbound as $column) {
            self::assertSame(str_starts_with($column->table, 'mail_kit_graph'), $column->active, $column->describe());
        }
    }

    public function testItIsSafeToCallMoreThanOnce(): void
    {
        // REQ-RUNTIME-002: nothing is memoised, so a worker that keeps the service gets the same answer every time.
        $declaration = new MailKitExactKeyColumns('app_', true, true);

        self::assertEquals($declaration->exactKeyColumns(), $declaration->exactKeyColumns());
    }
}
