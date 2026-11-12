<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Graph\Inbound;

use Doctrine\DBAL\Schema\Schema;
use Nowo\MailKitBundle\Graph\Inbound\InboundAction;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Nowo\MailKitBundle\Tests\Support\InboundStoreTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function hash;

final class GraphInboundStoreTest extends TestCase
{
    use InboundStoreTrait;

    public function testSchema(): void
    {
        $store = $this->inboundStore('app_');
        self::assertSame('app_mail_kit_graph_subscription', $store->subscriptionTable());
        self::assertSame([], $store->missingTablesSql());
        $schema = new Schema();
        $store->defineSchema($schema);
        $store->defineSchema($schema);
        self::assertTrue($schema->hasTable('app_mail_kit_graph_delta'));
        self::assertTrue($schema->hasTable('app_mail_kit_graph_inbound'));
    }

    public function testSubscriptionsKeepOnlyAHashOfTheClientState(): void
    {
        $store  = $this->inboundStore();
        $secret = 'client-state-REDACTED-TEST';
        $store->saveSubscription('sub-1', 'acme', $secret, 1000);
        $store->saveSubscription('sub-2', 'acme', $secret, 2000);
        self::assertSame(['id' => 'sub-2', 'expires_at' => 2000], $store->subscription('acme'));
        self::assertNull($store->subscription('globex'));

        $stored = $this->inboundConnection?->fetchOne('SELECT client_state_hash FROM mail_kit_graph_subscription');
        self::assertSame(hash('sha256', $secret), $stored);

        self::assertSame('acme', $store->verifyNotification('sub-2', $secret));
        self::assertNull($store->verifyNotification('sub-2', 'wrong'));
        self::assertNull($store->verifyNotification('sub-1', $secret));
        self::assertNull($store->verifyNotification('unknown', $secret));

        $store->updateSubscriptionExpiry('sub-2', 3000);
        self::assertSame(3000, $store->subscription('acme')['expires_at'] ?? null);
        $store->deleteSubscription('sub-2');
        self::assertNull($store->subscription('acme'));
    }

    public function testDeltaLinks(): void
    {
        $store = $this->inboundStore();
        self::assertNull($store->deltaLink('acme'));
        $store->saveDeltaLink('acme', 'https://graph.microsoft.com/v1.0/a');
        $store->saveDeltaLink('acme', 'https://graph.microsoft.com/v1.0/b');
        self::assertSame('https://graph.microsoft.com/v1.0/b', $store->deltaLink('acme'));
        $store->deleteDeltaLink('acme');
        self::assertNull($store->deltaLink('acme'));
    }

    public function testClaimsAreIdempotentStaleClaimsAreTakenOverAndSavepointsProtectHostTransactions(): void
    {
        $clock = new MockClock('2026-11-12 10:00:00');
        $store = $this->inboundStore('', $clock);
        self::assertTrue($store->claim('acme', 'id@x', 900));
        self::assertFalse($store->claim('acme', 'id@x', 900));
        self::assertTrue($store->claim('globex', 'id@x', 900), 'Per profile.');
        self::assertTrue($store->claim('acme', 'ID@x', 900), 'Ids compare exactly.');

        $clock->modify('+901 seconds');
        self::assertTrue($store->claim('acme', 'id@x', 900), 'A stale processing claim is taken over.');
        $store->complete('acme', 'id@x', InboundAction::Route);
        $clock->modify('+2000 seconds');
        self::assertFalse($store->claim('acme', 'id@x', 900), 'A completed message is never processed again.');
        self::assertSame('route', $this->inboundConnection?->fetchOne('SELECT status FROM mail_kit_graph_inbound WHERE message_key = ?', [GraphInboundStore::key('acme', 'id@x')]));

        // Inside a host transaction: the duplicate rolls back to a savepoint, the host transaction goes on.
        $connection = $this->inboundConnection;
        $connection->beginTransaction();
        self::assertTrue($store->claim('acme', 'tx@x', 900));
        self::assertFalse($store->claim('acme', 'tx@x', 900));
        $connection->commit();
        self::assertFalse($store->claim('acme', 'tx@x', 900));

        $store->release('acme', 'tx@x');
        self::assertTrue($store->claim('acme', 'tx@x', 900));

        $clock->modify('+100 days');
        self::assertSame(0, $store->purge($clock->now()->getTimestamp() - 200 * 86_400));
        self::assertSame(4, $store->purge($clock->now()->getTimestamp()));

        // A failing release never throws (the stale-claim timeout releases it).
        $connection->executeStatement('DROP TABLE mail_kit_graph_inbound');
        $store->release('acme', 'tx@x');
        self::assertNotSame([], $store->missingTablesSql());
    }
}
