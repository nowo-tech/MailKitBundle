<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Tracking;

use Closure;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MailKitBundle\Concurrency\StorageConflicts;
use Nowo\MailKitBundle\Event\MailOpenedEvent;
use Nowo\MailKitBundle\Tests\Support\HostTrackingPolicy;
use Nowo\MailKitBundle\Tests\Support\RecordingLogger;
use Nowo\MailKitBundle\Tests\Support\ShortLinkRewriter;
use Nowo\MailKitBundle\Tests\Support\StatementHookMiddleware;
use Nowo\MailKitBundle\Tests\Support\StorageFailures;
use Nowo\MailKitBundle\Tracking\ConfiguredTrackableLinkFilter;
use Nowo\MailKitBundle\Tracking\CredentialLinkDetector;
use Nowo\MailKitBundle\Tracking\EmailLinkRewriterInterface;
use Nowo\MailKitBundle\Tracking\EmailTracker;
use Nowo\MailKitBundle\Tracking\EmailTrackingPolicyInterface;
use Nowo\MailKitBundle\Tracking\HtmlLinkTracker;
use Nowo\MailKitBundle\Tracking\Http\TrackingPixelController;
use Nowo\MailKitBundle\Tracking\Message\RecordMailOpen;
use Nowo\MailKitBundle\Tracking\OpenClassifier;
use Nowo\MailKitBundle\Tracking\OpenRecorder;
use Nowo\MailKitBundle\Tracking\Store\TrackingStore;
use Nowo\MailKitBundle\Tracking\TrackedLink;
use Nowo\MailKitBundle\Tracking\TrackingCandidate;
use Nowo\MailKitBundle\Tracking\TrackingDecision;
use Nowo\MailKitBundle\Tracking\TrackingTokenSigner;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Email;
use Throwable;

use function str_starts_with;

/**
 * REQ-RUNTIME-003 rule 8 (1.13): lock conflicts of the tracking writes are rethrown where a replay is safe (before the
 * e-mail leaves, inside the host's transaction, on the pixel), and stay best effort after the send in autocommit.
 */
final class TrackingConflictsTest extends TestCase
{
    private const SECRET = 'test-tracking-secret-REDACTED-0000000000';

    private ?Throwable $inject = null;

    private ?Connection $connection = null;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testCreateSendRethrowsALockConflictAttachAndDiscardLogItInAutocommit(): void
    {
        $store        = $this->store('INSERT INTO mail_kit_tracking_send');
        $this->inject = StorageFailures::deadlock();
        try {
            $store->createSend(TrackingTokenSigner::newSendId(), null, null, null);
            self::fail('A lock conflict before the send is rethrown.');
        } catch (DriverException $failure) {
            self::assertTrue(StorageConflicts::isTransient($failure));
        }

        // Any other failure keeps the e-mail untracked.
        $this->inject = new RuntimeException('disk full');
        self::assertFalse($store->createSend(TrackingTokenSigner::newSendId(), null, null, null));

        $store        = $this->store('UPDATE mail_kit_tracking_send SET log_id');
        $this->inject = StorageFailures::lockWaitTimeout();
        $store->attachLog(TrackingTokenSigner::newSendId(), 'log-1');
        self::assertTrue($this->logger->has('Mail tracking store write failed; the e-mail is sent untracked.'));

        // Inside the host's transaction the conflict is rethrown after the savepoint rollback.
        $this->db()->beginTransaction();
        try {
            $store->attachLog(TrackingTokenSigner::newSendId(), 'log-1');
            self::fail('A lock conflict inside the host transaction is rethrown.');
        } catch (DriverException $failure) {
            self::assertTrue(StorageConflicts::isTransient($failure));
            self::assertTrue($this->db()->isTransactionActive());
        } finally {
            $this->db()->rollBack();
        }
    }

    public function testTheTrackerRethrowsConflictsOfTheStoreThePolicyAndTheRewriter(): void
    {
        $store        = $this->store('INSERT INTO mail_kit_tracking_send');
        $this->inject = StorageFailures::deadlock();
        try {
            $this->tracker($store)->track($this->email());
            self::fail('Store conflict.');
        } catch (DriverException $failure) {
            self::assertTrue(StorageConflicts::isTransient($failure));
        }
        $this->inject = null;

        $policy = new class implements EmailTrackingPolicyInterface {
            public function decide(TrackingCandidate $candidate): TrackingDecision
            {
                throw StorageFailures::lockWaitTimeout();
            }
        };
        try {
            $this->tracker($store, policy: $policy)->track($this->email());
            self::fail('Policy conflict.');
        } catch (DriverException $failure) {
            self::assertTrue(StorageConflicts::isTransient($failure));
        }

        $rewriter = new class implements EmailLinkRewriterInterface {
            public function rewrite(TrackedLink $link): ?string
            {
                throw StorageFailures::serializationFailure();
            }
        };
        try {
            $this->tracker($store, rewriter: $rewriter)->track($this->email());
            self::fail('Rewriter conflict.');
        } catch (Throwable $failure) {
            self::assertStringContainsString('could not serialize', $failure->getMessage());
        }
    }

    public function testThePixelLetsAConflictReachTheListenerAndAListenerConflictIsCommitted(): void
    {
        $store      = $this->store('UPDATE mail_kit_tracking_send SET open_count');
        $signer     = new TrackingTokenSigner(self::SECRET);
        $dispatcher = new EventDispatcher();
        $controller = new TrackingPixelController($signer, new OpenClassifier(), new OpenRecorder($store, $dispatcher, $this->logger), null, null, $this->logger);
        $id         = TrackingTokenSigner::newSendId();
        self::assertTrue($store->createSend($id, null, null, null));

        $this->inject = StorageFailures::deadlock();
        try {
            $controller(Request::create('/e/o/x.gif'), $signer->issue($id));
            self::fail('The pixel rethrows a conflict.');
        } catch (DriverException $failure) {
            self::assertTrue(StorageConflicts::isTransient($failure));
        }
        $this->inject = null;

        // A host listener's lock conflict after the open was counted: committed (CONFLICT_002).
        $dispatcher->addListener(MailOpenedEvent::class, static function (): never {
            throw StorageFailures::deadlock();
        });
        try {
            (new OpenRecorder($store, $dispatcher, $this->logger))->record(new RecordMailOpen($id, false, null, null, 1_800_000_000));
            self::fail('The listener conflict is rethrown.');
        } catch (ConcurrencyConflict $conflict) {
            self::assertTrue($conflict->committed);
        }
        self::assertSame(1, $store->find($id)?->opens);
    }

    private function db(): Connection
    {
        self::assertInstanceOf(Connection::class, $this->connection);

        return $this->connection;
    }

    private function store(string $prefix): TrackingStore
    {
        $hook = function (string $sql) use ($prefix): void {
            if ($this->inject instanceof Throwable && str_starts_with($sql, $prefix)) {
                throw $this->inject;
            }
        };
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new Configuration())->setMiddlewares([new StatementHookMiddleware(Closure::fromCallable($hook))]));
        $registry         = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($this->connection);
        $store = new TrackingStore($registry, null, '', null, $this->logger);
        $store->execute($store->missingTablesSql());

        return $store;
    }

    private function tracker(TrackingStore $store, ?EmailTrackingPolicyInterface $policy = null, ?EmailLinkRewriterInterface $rewriter = null): EmailTracker
    {
        return new EmailTracker($policy ?? new HostTrackingPolicy(), $rewriter ?? new ShortLinkRewriter(), new ConfiguredTrackableLinkFilter(['app.example.com']), new CredentialLinkDetector(), new TrackingTokenSigner(self::SECRET), $store, new HtmlLinkTracker(), 'https://app.example.com/e/o', logger: $this->logger);
    }

    private function email(): Email
    {
        return (new Email())->from('app@example.com')->to('user@example.com')->subject('Alert')->html('<p>Hi</p><a href="https://app.example.com/issues/1">View issue</a>');
    }
}
