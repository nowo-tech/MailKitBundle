<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Concurrency;

use Closure;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MailKitBundle\Concurrency\StorageConflicts;
use Nowo\MailKitBundle\Event\MailOpenedEvent;
use Nowo\MailKitBundle\EventListener\MailKitConflictListener;
use Nowo\MailKitBundle\Graph\Inbound\Store\GraphInboundStore;
use Nowo\MailKitBundle\Tests\Support\StatementHookMiddleware;
use Nowo\MailKitBundle\Tracking\Http\TrackingPixelController;
use Nowo\MailKitBundle\Tracking\OpenClassifier;
use Nowo\MailKitBundle\Tracking\OpenRecorder;
use Nowo\MailKitBundle\Tracking\Store\TrackingStore;
use Nowo\MailKitBundle\Tracking\TrackingTokenSigner;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

use function getenv;
use function is_string;
use function str_starts_with;

/**
 * REQ-RUNTIME-003 race tests (1.13): two real connections on the same MySQL 8.4 / MariaDB 11 / PostgreSQL database
 * (MAIL_KIT_TEST_DATABASE_URL; skipped on SQLite, which has no row locks). Connection A holds a lock or commits in the
 * middle; connection B runs the bundle's write. Lock waits are capped at one second (innodb_lock_wait_timeout /
 * lock_timeout) so a regression fails fast instead of hanging. Each test asserts the answer, the stored rows and that
 * connection B is still usable afterwards.
 */
#[Group('database')]
final class ConcurrencyRaceTest extends TestCase
{
    private const PREFIX = 'race_';

    private const SECRET = 'test-tracking-secret-REDACTED-0000000000';

    private Connection $a;

    private Connection $b;

    /** @var (Closure(string): void)|null */
    private ?Closure $hook = null;

    protected function setUp(): void
    {
        $url = getenv('MAIL_KIT_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '' || str_starts_with($url, 'sqlite')) {
            self::markTestSkipped('Needs MAIL_KIT_TEST_DATABASE_URL on MySQL, MariaDB or PostgreSQL.');
        }
        $params  = (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']))->parse($url);
        $this->a = DriverManager::getConnection($params);
        $this->b = DriverManager::getConnection($params, (new Configuration())->setMiddlewares([new StatementHookMiddleware(function (string $sql): void {
            if ($this->hook instanceof Closure) {
                ($this->hook)($sql);
            }
        })]));
        foreach ([$this->a, $this->b] as $connection) {
            $connection->executeStatement($this->isPostgres() ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
        }
        foreach (['mail_kit_graph_subscription', 'mail_kit_graph_delta', 'mail_kit_graph_inbound', 'mail_kit_tracking_send', 'probe'] as $table) {
            $this->a->executeStatement('DROP TABLE IF EXISTS ' . self::PREFIX . $table);
        }
        $this->inbound($this->a)->execute($this->inbound($this->a)->missingTablesSql());
        $this->tracking($this->a)->execute($this->tracking($this->a)->missingTablesSql());
        $this->a->executeStatement('CREATE TABLE ' . self::PREFIX . 'probe (id INT NOT NULL PRIMARY KEY, n INT NOT NULL)');
        $this->a->insert(self::PREFIX . 'probe', ['id' => 1, 'n' => 0]);
    }

    protected function tearDown(): void
    {
        if (isset($this->a)) {
            $this->a->close();
            $this->b->close();
        }
    }

    public function testTwoWorkersClaimTheSameMessageOnce(): void
    {
        $storeA = $this->inbound($this->a);
        $storeB = $this->inbound($this->b);

        // A has claimed the message in an open transaction: B waits on the unique key, then gets a retry-safe conflict.
        $this->a->beginTransaction();
        self::assertTrue($storeA->claim('acme', 'msg@x', 900));
        $failure = $this->expectFailure(static fn (): bool => $storeB->claim('acme', 'msg@x', 900));
        self::assertTrue(StorageConflicts::isConflict($failure));
        self::assertSame(ConflictCode::RetrySafe, StorageConflicts::code(new Request(), $failure));
        $this->assertUsable($this->b);

        // A commits: B's replay sees the duplicate and does not claim it a second time.
        $this->a->commit();
        self::assertFalse($storeB->claim('acme', 'msg@x', 900));
        self::assertSame(1, $this->rows('mail_kit_graph_inbound'));

        // A failed hand-over releases the claim on the other connection; the next delivery claims it again.
        $storeA->release('acme', 'msg@x');
        self::assertTrue($storeB->claim('acme', 'msg@x', 900));
        self::assertSame(1, $this->rows('mail_kit_graph_inbound'));
    }

    public function testTwoSweepsStoreTheDeltaLinkOfANewProfile(): void
    {
        $storeA = $this->inbound($this->a);
        $storeB = $this->inbound($this->b);

        // B's UPDATE finds no row; A stores the link (committed) just before B's INSERT: the duplicate key is caught
        // and B's UPDATE wins. It used to fail with a unique violation (check-then-insert).
        $this->hook = function (string $sql) use ($storeA): void {
            if (str_starts_with($sql, 'INSERT INTO ' . self::PREFIX . 'mail_kit_graph_delta')) {
                $this->hook = null;
                $storeA->saveDeltaLink('acme', 'https://graph.microsoft.com/v1.0/from-a');
            }
        };
        $storeB->saveDeltaLink('acme', 'https://graph.microsoft.com/v1.0/from-b');
        self::assertSame('https://graph.microsoft.com/v1.0/from-b', $storeA->deltaLink('acme'));
        self::assertSame(1, $this->rows('mail_kit_graph_delta'));

        if (!$this->isPostgres()) {
            // MySQL / MariaDB: inside B's transaction the UPDATE that found no row holds a gap lock, so A's INSERT
            // waits for B instead of racing it (A would meet B's row and take the duplicate-key path itself).
            return;
        }

        // PostgreSQL: the same race inside B's own transaction. The duplicate key rolls back to a savepoint, so the
        // transaction goes on (without it PostgreSQL aborts the whole transaction).
        $this->hook = function (string $sql) use ($storeA): void {
            if (str_starts_with($sql, 'INSERT INTO ' . self::PREFIX . 'mail_kit_graph_delta')) {
                $this->hook = null;
                $storeA->saveDeltaLink('globex', 'https://graph.microsoft.com/v1.0/from-a');
            }
        };
        $this->b->beginTransaction();
        $storeB->saveDeltaLink('globex', 'https://graph.microsoft.com/v1.0/from-b');
        $this->assertUsable($this->b);
        $this->b->commit();
        self::assertSame('https://graph.microsoft.com/v1.0/from-b', $storeA->deltaLink('globex'));
        self::assertSame(2, $this->rows('mail_kit_graph_delta'));
    }

    public function testASubscriptionSwapIsAllOrNothingUnderALock(): void
    {
        $storeA = $this->inbound($this->a);
        $storeB = $this->inbound($this->b);
        $storeA->saveSubscription('sub-old', 'acme', 'state-old', 2_000_000_000);

        // A holds the profile's row: B's swap (DELETE + INSERT) times out and rolls back as one unit.
        $this->a->beginTransaction();
        $this->a->executeStatement('UPDATE ' . self::PREFIX . 'mail_kit_graph_subscription SET expires_at = 2000000001 WHERE profile = ?', ['acme']);
        $failure = $this->expectFailure(static fn () => $storeB->saveSubscription('sub-new', 'acme', 'state-new', 2_100_000_000));
        self::assertInstanceOf(TransientStorageFailure::class, $failure);
        self::assertSame(0, $this->b->getTransactionNestingLevel());
        $this->assertUsable($this->b);
        $this->a->rollBack();
        self::assertSame(['id' => 'sub-old', 'expires_at' => 2_000_000_000], $storeB->subscription('acme'));

        // The replay succeeds: exactly one subscription per profile.
        $storeB->saveSubscription('sub-new', 'acme', 'state-new', 2_100_000_000);
        self::assertSame(['id' => 'sub-new', 'expires_at' => 2_100_000_000], $storeA->subscription('acme'));
        self::assertSame(1, $this->rows('mail_kit_graph_subscription'));
    }

    public function testALockTimeoutOnTheInsertInsideAHostTransactionLeavesItUsable(): void
    {
        $storeA = $this->inbound($this->a);
        $storeB = $this->inbound($this->b);

        // Audit UE-MK-1. A holds uncommitted rows on the keys B is about to insert. B runs the bundle's writes inside its
        // own (host) transaction: each INSERT times out on A's key, the savepoint is rolled back, and B's next
        // statement still works (PostgreSQL aborts the whole transaction otherwise). On MySQL / MariaDB the delta
        // UPDATE may already wait on A's row; the host transaction survives either way.
        $this->a->beginTransaction();
        self::assertTrue($storeA->claim('acme', 'held@x', 900));
        $storeA->saveDeltaLink('acme', 'https://graph.microsoft.com/v1.0/from-a');

        $this->b->beginTransaction();
        $this->b->executeStatement('UPDATE ' . self::PREFIX . 'probe SET n = n + 1 WHERE id = ?', [1]);
        foreach ([
            static fn (): bool => $storeB->claim('acme', 'held@x', 900),
            static fn () => $storeB->saveDeltaLink('acme', 'https://graph.microsoft.com/v1.0/from-b'),
        ] as $write) {
            $failure = $this->expectFailure($write);
            self::assertTrue(StorageConflicts::isConflict($failure), $failure::class);
            $this->assertUsable($this->b);
        }
        $this->b->executeStatement('UPDATE ' . self::PREFIX . 'probe SET n = n + 1 WHERE id = ?', [1]);
        $this->b->commit();
        $this->a->rollBack();

        self::assertSame(2, (int) $this->a->fetchOne('SELECT n FROM ' . self::PREFIX . 'probe WHERE id = 1'), 'The host transaction committed its own work.');
        self::assertSame(0, $this->rows('mail_kit_graph_inbound'));
        self::assertSame(0, $this->rows('mail_kit_graph_delta'));
    }

    public function testASubscriptionSwapWhoseInsertFailsKeepsTheOldSubscription(): void
    {
        // Audit UE-MK-2: the DELETE succeeds, the INSERT fails (the new id belongs to another profile): one rollback.
        $storeB = $this->inbound($this->b);
        $storeB->saveSubscription('sub-acme', 'acme', 'state-a', 2_000_000_000);
        $storeB->saveSubscription('sub-globex', 'globex', 'state-g', 2_000_000_000);
        $failure = $this->expectFailure(static fn () => $storeB->saveSubscription('sub-globex', 'acme', 'state-x', 2_100_000_000));
        self::assertFalse(StorageConflicts::isConflict($failure), 'A duplicate id is a real error, not a conflict.');
        self::assertSame(0, $this->b->getTransactionNestingLevel());
        self::assertSame(['id' => 'sub-acme', 'expires_at' => 2_000_000_000], $this->inbound($this->a)->subscription('acme'));
        self::assertSame(2, $this->rows('mail_kit_graph_subscription'));
    }

    public function testTwoPixelsCountBothOpensAndALockWaitAnswersConflict001(): void
    {
        $storeA = $this->tracking($this->a);
        $signer = new TrackingTokenSigner(self::SECRET);
        $id     = TrackingTokenSigner::newSendId();
        self::assertTrue($storeA->createSend($id, null, null, null));
        $pixel = new TrackingPixelController($signer, new OpenClassifier(), new OpenRecorder($this->tracking($this->b)));

        // A counts an open in an open transaction (row lock): B's pixel waits, then answers 409 + CONFLICT_001.
        $this->a->beginTransaction();
        self::assertTrue($storeA->recordOpen($id, false, null, null, 1_800_000_000));
        $response = $this->answer(static fn (): Response => $pixel(Request::create('/e/o/x.gif'), $signer->issue($id)));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame('1', $response->headers->get('Retry-After'));
        $this->assertUsable($this->b);

        // A commits; the retried pixel counts: two opens, none lost (atomic increment, no read-modify-write).
        $this->a->commit();
        self::assertSame('image/gif', $pixel(Request::create('/e/o/x.gif'), $signer->issue($id))->headers->get('Content-Type'));
        self::assertSame(2, $storeA->find($id)?->opens);
    }

    public function testAListenerConflictAfterTheCountedOpenAnswersConflict002(): void
    {
        $storeA     = $this->tracking($this->a);
        $signer     = new TrackingTokenSigner(self::SECRET);
        $id         = TrackingTokenSigner::newSendId();
        $dispatcher = new EventDispatcher();
        $connection = $this->b;
        $dispatcher->addListener(MailOpenedEvent::class, static function () use ($connection): void {
            // The host's listener writes its own row, which connection A holds.
            $connection->executeStatement('UPDATE ' . self::PREFIX . 'probe SET n = n + 1 WHERE id = ?', [1]);
        });
        self::assertTrue($storeA->createSend($id, null, null, null));
        $pixel = new TrackingPixelController($signer, new OpenClassifier(), new OpenRecorder($this->tracking($this->b), $dispatcher));

        $this->a->beginTransaction();
        $this->a->executeStatement('UPDATE ' . self::PREFIX . 'probe SET n = 10 WHERE id = ?', [1]);
        $response = $this->answer(static fn (): Response => $pixel(Request::create('/e/o/x.gif'), $signer->issue($id)));
        $this->a->rollBack();

        // The open was committed before the listener failed: read again, do not repeat.
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_002', $response->headers->get('X-Conflict-Code'));
        self::assertFalse($response->headers->has('Retry-After'));
        self::assertSame(1, $storeA->find($id)?->opens);
        self::assertSame(0, (int) $this->a->fetchOne('SELECT n FROM ' . self::PREFIX . 'probe WHERE id = 1'));
        $this->assertUsable($this->b);
    }

    private function inbound(Connection $connection): GraphInboundStore
    {
        return new GraphInboundStore($this->registry($connection), null, self::PREFIX);
    }

    private function tracking(Connection $connection): TrackingStore
    {
        return new TrackingStore($this->registry($connection), null, self::PREFIX);
    }

    private function registry(Connection $connection): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getConnection')->willReturn($connection);

        return $registry;
    }

    private function isPostgres(): bool
    {
        return $this->a->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    /**
     * @param Closure(): mixed $write
     */
    private function expectFailure(Closure $write): Throwable
    {
        try {
            $write();
        } catch (Throwable $failure) {
            return $failure;
        }
        self::fail('The write was expected to meet the lock held by connection A.');
    }

    /**
     * The bundle's conflict answer to what the pixel threw.
     *
     * @param Closure(): Response $call
     */
    private function answer(Closure $call): Response
    {
        $failure = $this->expectFailure($call);
        $request = Request::create('/e/o/x.gif');
        $request->attributes->set('_controller', TrackingPixelController::class);
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
        (new MailKitConflictListener())($event);
        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response, 'A lock conflict gets the conflict answer.');

        return $response;
    }

    private function assertUsable(Connection $connection): void
    {
        self::assertSame(1, (int) $connection->fetchOne('SELECT 1'));
    }

    private function rows(string $table): int
    {
        return (int) $this->a->fetchOne('SELECT COUNT(*) FROM ' . self::PREFIX . $table);
    }
}
