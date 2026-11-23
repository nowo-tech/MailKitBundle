<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Mailbox;

use InvalidArgumentException;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSyncOptions;
use Nowo\MailKitBundle\Mailbox\Event\MailboxGrantFailedEvent;
use Nowo\MailKitBundle\Mailbox\Event\MailboxResyncedEvent;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxTokenUnavailable;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\FetchMailboxMessage;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\RenewMailboxSubscriptions;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\SyncMailboxFolder;
use Nowo\MailKitBundle\Mailbox\Value\ExclusionDecision;
use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\FolderSyncState;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\MailboxSettings;
use Nowo\MailKitBundle\Mailbox\Value\MailboxStatus;
use Nowo\MailKitBundle\Mailbox\Value\MessageEnvelope;
use Nowo\MailKitBundle\Mailbox\Value\MessageOrigin;
use Nowo\MailKitBundle\Mailbox\Value\ProviderCall;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Nowo\MailKitBundle\Mailbox\Value\StoreOutcome;
use Nowo\MailKitBundle\Tests\Support\MailboxGraphFake;
use Nowo\MailKitBundle\Tests\Support\MailboxHarness;
use Nowo\MailKitBundle\Tests\Support\MemoryMailboxPorts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

use function array_keys;
use function array_map;
use function count;
use function str_contains;

/**
 * 2.5.0 delegated-mailbox engine end to end on SQLite with a fake Graph and in-memory host ports: start (account
 * check, subscriptions, first sweep), sweep pages with exclusion before the fetch, removals, idempotent delivery,
 * leases and reruns, cursor loss and resync, lost leases, grant-failure pause with one event, host pause / resume,
 * renewals and lifecycle events, disconnect.
 */
final class MailboxSyncEngineTest extends TestCase
{
    use MailboxHarness;

    private MailboxId $ana;

    protected function setUp(): void
    {
        $this->harness();
        $this->ana = new MailboxId('mbx-ana');
        $this->ports->add(MemoryMailboxPorts::settings('mbx-ana', 'ana@example.com', ['inbox', 'sentitems']));
    }

    public function testStartSyncsTheFollowedFoldersIntoTheSinkOnce(): void
    {
        $this->graph->delta['inbox'] = [
            [['id' => 'm1', 'from' => ['emailAddress' => ['address' => 'pedro@example.org']]], ['id' => 'm2', 'from' => ['emailAddress' => ['address' => 'news@spam.example']]]],
            [['id' => 'm3'], ['id' => 'm0', '@removed' => ['reason' => 'deleted']]],
        ];
        foreach (['m1', 'm2', 'm3'] as $id) {
            $this->graph->messages[$id] = MailboxGraphFake::message($id);
        }
        $this->ports->policy = static fn (MessageEnvelope $e): ExclusionDecision => $e->from?->email === 'news@spam.example' ? ExclusionDecision::exclude('spam-domain') : ExclusionDecision::keep();

        $status = $this->engine->start($this->ana);
        self::assertSame(MailboxStatus::Active, $status->status);
        self::assertSame(['inbox', 'sentitems'], array_map(static fn (FolderSyncState $f): string => $f->folder->key, $status->folders));
        self::assertNotNull($status->folders[0]->subscriptionExpiresAt);
        self::assertCount(2, $this->graph->subscribed);
        self::assertSame("me/mailFolders('sentitems')/messages", $this->graph->subscribed[1]['resource']);
        self::assertCount(2, $this->bus->messages);

        self::assertSame([], $this->drain());
        self::assertSame(['mbx-ana' . "\n" . 'm1', 'mbx-ana' . "\n" . 'm3'], array_keys($this->ports->stored));
        self::assertSame(['mbx-ana' . "\n" . 'm0' . "\n" . 'inbox'], $this->ports->removedMessages);
        self::assertCount(0, $this->graph->matching('/me/messages/m2'), 'excluded before the fetch');
        $m1 = $this->ports->stored['mbx-ana' . "\n" . 'm1'];
        self::assertSame(MessageOrigin::Sync, $m1->origin);
        self::assertSame('inbox', $m1->folder->key);
        self::assertNotNull($this->engine->status($this->ana)->folders[0]->lastCompleteSweep);

        // A notification plus the next sweep deliver m1 again: the sink answers Duplicate.
        $this->graph->after['inbox'] = [['id' => 'm1']];
        $this->engine->sweep($this->ana, FolderRef::inbox());
        $this->bus->dispatch(new FetchMailboxMessage('mbx-ana', 'inbox', 'm1'));
        self::assertSame([], $this->drain());
        self::assertSame(['mbx-ana' . "\n" . 'm1', 'mbx-ana' . "\n" . 'm1'], $this->ports->duplicates);

        // Starting again is harmless: no new subscription, a sweep per folder.
        $this->engine->start($this->ana);
        self::assertCount(2, $this->graph->subscribed);
        $this->drain();
    }

    public function testStartGuards(): void
    {
        self::assertSame(MailboxStatus::Unknown, $this->engine->start(new MailboxId('nobody'))->status);

        $this->ports->add(MemoryMailboxPorts::settings('mbx-paused', 'ana@example.com', ['inbox'], true));
        self::assertSame(MailboxStatus::PausedByHost, $this->engine->start(new MailboxId('mbx-paused'))->status);
        self::assertSame([], $this->graph->requests);

        $this->ports->add(new MailboxSettings(new MailboxId('mbx-gmail'), MailboxProvider::Gmail, 'ana@example.com', [FolderRef::inbox()]));

        try {
            $this->engine->start(new MailboxId('mbx-gmail'));
            self::fail('Gmail is not enabled.');
        } catch (MailboxProviderFailure $failure) {
            self::assertSame(MailboxProviderFailure::REFUSED, $failure->reason);
        }

        $this->ports->add(MemoryMailboxPorts::settings('mbx-wide', 'ana@example.com', ['inbox'], false, 'P120D'));
        $this->expectException(InvalidArgumentException::class);
        $this->engine->start(new MailboxId('mbx-wide'));
    }

    public function testTooManyFoldersIsAHostBug(): void
    {
        $this->harness(new MailboxSyncOptions(maxFolders: 1));
        $this->ports->add(MemoryMailboxPorts::settings('mbx-ana', 'ana@example.com', ['inbox', 'archive']));
        $this->expectException(InvalidArgumentException::class);
        $this->engine->start($this->ana);
    }

    public function testAnotherAccountBehindTheTokenPausesOnce(): void
    {
        $this->graph->account = ['id' => 'pedro-id', 'mail' => 'pedro@example.org', 'userPrincipalName' => 'pedro@example.org'];
        $status               = $this->engine->start($this->ana);
        self::assertSame([MailboxStatus::PausedGrant, GrantFailureReason::AccountMismatch], [$status->status, $status->reason]);
        self::assertCount(1, $this->events->of(MailboxGrantFailedEvent::class));
        self::assertSame([], $this->graph->subscribed);

        // The right address but another account than the one first bound.
        $this->graph->account = ['id' => MailboxGraphFake::ACCOUNT_ID, 'mail' => 'ana@example.com'];
        self::assertSame(MailboxStatus::Active, $this->engine->resume($this->ana)->status);
        $this->graph->account = ['id' => 'someone-else', 'mail' => 'ana@example.com'];
        self::assertSame(MailboxStatus::PausedGrant, $this->engine->resume($this->ana)->status);
        self::assertCount(2, $this->events->of(MailboxGrantFailedEvent::class));
    }

    public function testARevokedGrantPausesWithOneEventAndNothingRetries(): void
    {
        $this->engine->start($this->ana);
        $this->bus->messages             = [];
        $this->ports->revoked['mbx-ana'] = GrantFailureReason::Revoked;
        $this->bus->dispatch(new SyncMailboxFolder('mbx-ana', 'inbox'));
        $this->bus->dispatch(new SyncMailboxFolder('mbx-ana', 'sentitems'));
        $this->bus->dispatch(new FetchMailboxMessage('mbx-ana', 'inbox', 'm1'));
        $failures = $this->drain();

        self::assertCount(1, $failures, 'the first message fails unrecoverably, the others are dropped on the status check');
        self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $failures[0]);
        self::assertCount(1, $this->events->of(MailboxGrantFailedEvent::class));
        self::assertSame(GrantFailureReason::Revoked, $this->events->of(MailboxGrantFailedEvent::class)[0]->reason);
        self::assertSame(MailboxStatus::PausedGrant, $this->engine->status($this->ana)->status);
        self::assertTrue($this->log->has('Mailbox paused: the grant failed'));

        // Start on a paused mailbox does nothing; resume after the reconnect continues.
        self::assertSame(MailboxStatus::PausedGrant, $this->engine->start($this->ana)->status);
        unset($this->ports->revoked['mbx-ana']);
        self::assertSame(MailboxStatus::Active, $this->engine->resume($this->ana)->status);
        self::assertSame([], $this->drain());

        // A grant failure while starting is reported in the status.
        $this->ports->revoked['mbx-ana'] = GrantFailureReason::ConsentMissing;
        $this->engine->pause($this->ana);
        $status = $this->engine->resume($this->ana);
        self::assertSame([MailboxStatus::PausedGrant, GrantFailureReason::ConsentMissing], [$status->status, $status->reason]);
    }

    public function testHostPauseResumeAndDirectoryChanges(): void
    {
        $this->engine->start($this->ana);
        $this->drain();
        self::assertSame(MailboxStatus::PausedByHost, $this->engine->pause($this->ana)->status);
        self::assertTrue($this->log->has('Mailbox paused by the host.'));
        self::assertSame(MailboxStatus::PausedByHost, $this->engine->pause($this->ana)->status);
        $this->engine->sweep($this->ana);
        self::assertSame([], $this->bus->messages, 'no sweep for a paused mailbox');
        $this->bus->dispatch(new SyncMailboxFolder('mbx-ana', 'inbox'));
        $before = count($this->graph->requests);
        $this->drain();
        self::assertCount($before, $this->graph->requests);

        self::assertSame(MailboxStatus::Active, $this->engine->resume($this->ana)->status);
        $this->drain();

        // The directory pauses it: the next message pauses it here.
        $this->ports->add(MemoryMailboxPorts::settings('mbx-ana', 'ana@example.com', ['inbox'], true));
        $this->bus->dispatch(new SyncMailboxFolder('mbx-ana', 'inbox'));
        $this->drain();
        self::assertSame(MailboxStatus::PausedByHost, $this->engine->status($this->ana)->status);
        self::assertSame(MailboxStatus::PausedByHost, $this->engine->resume($this->ana)->status);

        // Resume of a mailbox MailKit never saw starts it; the directory forgot it: its rows go.
        $this->ports->add(MemoryMailboxPorts::settings('mbx-new', 'ana@example.com'));
        self::assertSame(MailboxStatus::Active, $this->engine->resume(new MailboxId('mbx-new'))->status);
        unset($this->ports->mailboxes['mbx-new']);
        self::assertSame(MailboxStatus::Unknown, $this->engine->resume(new MailboxId('mbx-new'))->status);
        $this->engine->start(new MailboxId('mbx-new'));
        self::assertSame('unknown', $this->engine->status(new MailboxId('mbx-new'))->status->value);
    }

    public function testSweepEnqueuesOnlyFollowedFolders(): void
    {
        $this->engine->start($this->ana);
        $this->bus->messages = [];
        $this->engine->sweep($this->ana, new FolderRef('archive'));
        self::assertSame([], $this->bus->messages);
        $this->engine->sweep($this->ana);
        self::assertCount(2, $this->bus->messages);
        self::assertSame(2, $this->engine->sweepAll());
        $this->ports->add(MemoryMailboxPorts::settings('mbx-other', 'other@example.com'));
        self::assertSame(2, $this->engine->sweepAll(), 'a mailbox never started is not swept');
    }

    public function testABusyFolderAsksItsHolderForOneMoreSweep(): void
    {
        $this->engine->start($this->ana);
        $this->bus->messages = [];
        $this->mailboxStore->acquireLease($this->ana, 'inbox', 'other-worker', 120);
        $this->engine->sweepFolder($this->ana, 'inbox');
        self::assertSame([], $this->graph->matching('/delta'));
        self::assertSame('1', (string) $this->mailboxConnection->fetchOne("SELECT rerun FROM mail_kit_mailbox_cursor WHERE folder_key = 'inbox'"));

        // The holder finishes and sees the rerun: one more sweep is enqueued.
        $this->mailboxConnection->executeStatement("UPDATE mail_kit_mailbox_cursor SET lease_until = 0 WHERE folder_key = 'inbox'");
        $this->engine->sweepFolder($this->ana, 'inbox');
        self::assertCount(0, $this->bus->messages, 'the lease clears rerun when taken');
        $this->mailboxStore->requestRerun($this->ana, 'inbox');
        $this->mailboxConnection->executeStatement("UPDATE mail_kit_mailbox_cursor SET lease_until = 0 WHERE folder_key = 'inbox'");
        $this->graph->override = function (string $method, string $path): ?MockResponse {
            if (str_contains($path, '/delta')) {
                $this->mailboxStore->requestRerun($this->ana, 'inbox');
            }

            return null;
        };
        $this->engine->sweepFolder($this->ana, 'inbox');
        self::assertEquals([new SyncMailboxFolder('mbx-ana', 'inbox')], $this->bus->messages);
    }

    public function testALongBacklogContinuesInAnotherSweep(): void
    {
        $this->harness(new MailboxSyncOptions(maxPagesPerRun: 1));
        $this->ports->add(MemoryMailboxPorts::settings('mbx-ana', 'ana@example.com', ['inbox'], false, 'P30D'));
        $this->graph->delta['inbox'] = [[['id' => 'm1']], [['id' => 'm2']]];
        $this->graph->messages       = ['m1' => MailboxGraphFake::message('m1'), 'm2' => MailboxGraphFake::message('m2')];
        $this->engine->start($this->ana);
        self::assertSame([], $this->graph->subscribed, 'no notification URL: sweep only');
        self::assertSame([], $this->drain());
        self::assertCount(2, $this->ports->stored);
        self::assertStringContainsString('receivedDateTime%20ge%202026-10-24T12%3A00%3A00Z', $this->graph->matching('/delta')[0]['url']);
    }

    public function testALostCursorResyncsFromTheResyncWindow(): void
    {
        $this->engine->start($this->ana);
        $this->drain();
        $expired               = 0;
        $this->graph->override = static function (string $method, string $path) use (&$expired): ?MockResponse {
            if (str_contains($path, 'deltatoken') && $expired < 2) {
                ++$expired;

                return MailboxGraphFake::json(['error' => ['code' => 'syncStateNotFound']], 410);
            }

            return null;
        };
        $this->graph->delta['inbox'] = [[['id' => 'm1']]];
        $this->graph->messages['m1'] = MailboxGraphFake::message('m1');
        $this->engine->sweepFolder($this->ana, 'inbox');
        self::assertCount(1, $this->events->of(MailboxResyncedEvent::class));
        self::assertEquals([new FetchMailboxMessage('mbx-ana', 'inbox', 'm1', MessageOrigin::Resync->value)], $this->bus->messages);
        self::assertSame([], $this->drain());
        self::assertSame(MessageOrigin::Resync, $this->ports->stored['mbx-ana' . "\n" . 'm1']->origin);

        // A second expiry right after the resync ends the run (the next sweep tries again).
        $expired               = 0;
        $this->graph->override = static fn (string $method, string $path): ?MockResponse => str_contains($path, '/delta') ? MailboxGraphFake::json(['error' => ['code' => 'resyncRequired']], 410) : null;
        $this->engine->sweepFolder($this->ana, 'inbox');
        self::assertCount(2, $this->events->of(MailboxResyncedEvent::class));
        self::assertSame(['cursor' => null, 'version' => 4], $this->mailboxStore->cursor($this->ana, 'inbox'));
    }

    public function testALostLeaseStopsTheRunWithoutWriting(): void
    {
        $this->engine->start($this->ana);
        $this->bus->messages         = [];
        $this->graph->delta['inbox'] = [[['id' => 'm1']], [['id' => 'm2']]];
        $this->graph->override       = function (string $method, string $path): ?MockResponse {
            if (str_contains($path, '/delta')) {
                $this->mailboxConnection->executeStatement("UPDATE mail_kit_mailbox_cursor SET lease_owner = 'thief' WHERE folder_key = 'inbox'");
            }

            return null;
        };
        $this->engine->sweepFolder($this->ana, 'inbox');
        self::assertSame(['cursor' => null, 'version' => 0], $this->mailboxStore->cursor($this->ana, 'inbox'));
        self::assertCount(1, $this->graph->matching('/delta'));
        // A cursor lost while another worker holds the folder is not cleared either.
        $this->mailboxStore->advanceCursor($this->ana, 'inbox', 'thief', 0, MailboxGraphFake::BASE . "/me/mailFolders('inbox')/messages/delta?\$deltatoken=done", 120, true);
        $this->mailboxConnection->executeStatement("UPDATE mail_kit_mailbox_cursor SET lease_owner = NULL, lease_until = NULL WHERE folder_key = 'inbox'");
        $this->graph->override = function (string $method, string $path): MockResponse {
            $this->mailboxConnection->executeStatement("UPDATE mail_kit_mailbox_cursor SET lease_owner = 'thief' WHERE folder_key = 'inbox'");

            return MailboxGraphFake::json(['error' => ['code' => 'syncStateNotFound']], 410);
        };
        $this->engine->sweepFolder($this->ana, 'inbox');
        self::assertSame([], $this->events->of(MailboxResyncedEvent::class));
    }

    public function testFolderAndDirectoryGuardsOfTheMessages(): void
    {
        $this->engine->start($this->ana);
        $this->drain();
        $this->graph->messages['m1'] = MailboxGraphFake::message('m1');
        $this->engine->fetchMessage($this->ana, 'archive', new ProviderMessageId('m1'), MessageOrigin::Sync);
        $this->engine->sweepFolder($this->ana, 'archive');
        self::assertSame([], $this->ports->stored);

        // A folder the host stopped following loses its cursor.
        $this->ports->add(MemoryMailboxPorts::settings('mbx-ana', 'ana@example.com', ['inbox']));
        $this->engine->sweepFolder($this->ana, 'sentitems');
        self::assertSame(['inbox'], array_map(static fn (array $c): string => $c['folder_key'], $this->mailboxStore->cursors($this->ana)));

        $this->engine->fetchMessage($this->ana, 'inbox', new ProviderMessageId('gone'), MessageOrigin::Sync);
        self::assertTrue($this->log->has('Mailbox message gone before it was read.'));
        $this->ports->policy = static fn (MessageEnvelope $e): ExclusionDecision => $e->complete && $e->listId === 'news.example.org' ? ExclusionDecision::exclude('lists') : ExclusionDecision::keep();
        $this->engine->fetchMessage($this->ana, 'inbox', new ProviderMessageId('m1'), MessageOrigin::Sync);
        self::assertSame([], $this->ports->stored);
        self::assertCount(0, $this->graph->matching('/attachments'), 'excluded before the attachment list');

        // A gateway the configuration does not enable drops the work.
        $this->ports->add(new MailboxSettings($this->ana, MailboxProvider::Gmail, 'ana@example.com', [FolderRef::inbox()]));
        $this->engine->sweepFolder($this->ana, 'inbox');

        // The directory forgot the mailbox: the next message deletes its rows.
        unset($this->ports->mailboxes['mbx-ana']);
        $this->engine->fetchMessage($this->ana, 'inbox', new ProviderMessageId('m1'), MessageOrigin::Sync);
        self::assertSame(MailboxStatus::Unknown, $this->engine->status($this->ana)->status);
    }

    public function testGrantFailuresDuringFetchAndRenewPause(): void
    {
        $this->engine->start($this->ana);
        $this->drain();
        $this->graph->override = static fn (string $method, string $path): ?MockResponse => str_contains($path, '/me/messages/') ? MailboxGraphFake::json(['error' => ['code' => 'ErrorAccessDenied']], 403) : null;
        $this->bus->dispatch(new FetchMailboxMessage('mbx-ana', 'inbox', 'm1'));
        self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $this->drain()[0] ?? null);
        self::assertSame(GrantFailureReason::ConsentMissing, $this->engine->status($this->ana)->reason);

        $this->engine->resume($this->ana);
        $this->drain();
        $this->graph->override = static fn (string $method, string $path): ?MockResponse => $path === '/v1.0/me?$select=id,mail,userPrincipalName' ? MailboxGraphFake::json(['error' => ['code' => 'MailboxNotEnabledForRESTAPI']], 404) : null;
        $this->bus->dispatch(new RenewMailboxSubscriptions('mbx-ana'));
        self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $this->drain()[0] ?? null);
        self::assertSame(GrantFailureReason::MailboxNotFound, $this->engine->status($this->ana)->reason);
        self::assertCount(2, $this->events->of(MailboxGrantFailedEvent::class));
    }

    public function testRenewalsAndLifecycleEvents(): void
    {
        $this->engine->start($this->ana);
        $this->drain();
        $subscriptions = $this->mailboxStore->subscriptions($this->ana);
        self::assertCount(2, $subscriptions);

        // Nothing expires soon: nothing to do (the account is checked again).
        $this->engine->renew($this->ana);
        self::assertSame([], $this->graph->renewed);
        self::assertCount(2, $this->graph->matching('/v1.0/me?'));

        // reauthorizationRequired renews at once, only for the subscription it names.
        $this->engine->renew($this->ana, RenewMailboxSubscriptions::REAUTHORIZE, $subscriptions[0]['id']);
        self::assertSame([$subscriptions[0]['id']], $this->graph->renewed);

        // subscriptionRemoved recreates.
        $this->engine->renew($this->ana, RenewMailboxSubscriptions::REMOVED, $subscriptions[1]['id']);
        self::assertCount(3, $this->graph->subscribed);

        // Close to expiry: renewed; gone at Graph: recreated.
        $this->clock->sleep(7 * 86_400 - 3600);
        $current                                           = $this->mailboxStore->subscriptions($this->ana);
        $this->graph->goneSubscriptions[$current[0]['id']] = true;
        $this->engine->renew($this->ana);
        self::assertCount(4, $this->graph->subscribed);
        self::assertContains($current[1]['id'], $this->graph->renewed);

        // A folder no longer followed loses its subscription (remote and local) and its cursor.
        $this->ports->add(MemoryMailboxPorts::settings('mbx-ana', 'ana@example.com', ['inbox']));
        $this->engine->renew($this->ana);
        self::assertCount(1, $this->mailboxStore->subscriptions($this->ana));
        self::assertCount(1, $this->graph->unsubscribed);
        self::assertSame(['inbox'], array_map(static fn (array $c): string => $c['folder_key'], $this->mailboxStore->cursors($this->ana)));
        // A remote delete that fails is best effort.
        $this->ports->add(MemoryMailboxPorts::settings('mbx-ana', 'ana@example.com', ['archive']));
        $this->graph->override = static fn (string $method): ?MockResponse => $method === 'DELETE' ? MailboxGraphFake::json(['error' => ['code' => 'x']], 500) : null;
        $this->engine->renew($this->ana);
        self::assertSame(['archive'], array_map(static fn (array $s): string => $s['folder_key'], $this->mailboxStore->subscriptions($this->ana)));
    }

    public function testARefusedSubscriptionLeavesTheFolderSweepOnly(): void
    {
        $this->graph->override = static fn (string $method, string $path): ?MockResponse => $method === 'POST' && $path === '/v1.0/subscriptions' ? MailboxGraphFake::json(['error' => ['code' => 'ExtensionError']], 400) : null;
        self::assertSame(MailboxStatus::Active, $this->engine->start($this->ana)->status);
        self::assertSame([], $this->mailboxStore->subscriptions($this->ana));
        self::assertTrue($this->log->has('the folder is synced by the sweep only'));

        $this->graph->override = static fn (string $method, string $path): ?MockResponse => $method === 'POST' && $path === '/v1.0/subscriptions' ? MailboxGraphFake::json(['error' => ['code' => 'x']], 503, ['retry-after' => '7']) : null;
        $this->expectException(MailboxProviderFailure::class);
        $this->engine->renew($this->ana);
    }

    public function testRenewAllContinuesAfterAFailure(): void
    {
        $this->ports->add(MemoryMailboxPorts::settings('mbx-bob', 'ana@example.com'));
        $this->engine->start($this->ana);
        $this->engine->start(new MailboxId('mbx-bob'));
        $this->graph->override = static fn (string $method, string $path, array $body): ?MockResponse => null;
        self::assertSame(0, $this->engine->renewAll());
        $this->ports->tokenOverride = static fn (MailboxId $id): never => throw new MailboxTokenUnavailable();
        self::assertSame(2, $this->engine->renewAll());
        self::assertTrue($this->log->has('Mailbox subscriptions not renewed.'));
        $this->bus->messages = [];
        $this->handler->renew(new RenewMailboxSubscriptions());
    }

    public function testDisconnectDeletesRemoteSubscriptionsAndRows(): void
    {
        $this->engine->start($this->ana);
        $result = $this->engine->disconnect($this->ana);
        self::assertSame([2, false], [$result->subscriptionsDeleted, $result->remoteCleanupFailed]);
        self::assertSame(5, $result->rowsDeleted);
        self::assertSame(MailboxStatus::Unknown, $this->engine->status($this->ana)->status);

        // A remote failure is reported; the rows go anyway.
        $this->engine->start($this->ana);
        $this->graph->override = static fn (string $method): ?MockResponse => $method === 'DELETE' ? MailboxGraphFake::json(['error' => ['code' => 'x']], 500) : null;
        $result                = $this->engine->disconnect($this->ana);
        self::assertSame([0, true, 5], [$result->subscriptionsDeleted, $result->remoteCleanupFailed, $result->rowsDeleted]);

        // A dead grant stops after the first attempt.
        $this->graph->override = null;
        $this->engine->start($this->ana);
        $this->ports->revoked['mbx-ana'] = GrantFailureReason::Revoked;
        $result                          = $this->engine->disconnect($this->ana);
        self::assertTrue($result->remoteCleanupFailed);
        self::assertSame(1, count($this->log->records) > 0 ? 1 : 0);

        // A provider that is not enabled cannot clean up remotely.
        unset($this->ports->revoked['mbx-ana']);
        $this->engine->start($this->ana);
        $this->mailboxConnection->executeStatement("UPDATE mail_kit_mailbox SET provider = 'gmail'");
        self::assertTrue($this->engine->disconnect($this->ana)->remoteCleanupFailed);
        self::assertSame(0, $this->engine->disconnect($this->ana)->rowsDeleted);
    }

    public function testCallsAreMetered(): void
    {
        $this->engine->start($this->ana);
        $operations = array_map(static fn (ProviderCall $c): string => $c->operation, $this->ports->calls);
        self::assertSame(['account', 'subscribe', 'subscribe'], $operations);
        foreach ($this->ports->calls as $call) {
            self::assertSame('mbx-ana', $call->mailbox->value);
        }
        self::assertSame(StoreOutcome::Stored->value, 'stored');
    }
}
