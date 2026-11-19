<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Concurrency;

use Nowo\MailKitBundle\Concurrency\StorageConflicts;
use Nowo\MailKitBundle\Tests\Support\StorageFailures;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

/**
 * REQ-RUNTIME-003 rules 6 and 8: which failures are conflicts, and which code they get.
 */
final class StorageConflictsTest extends TestCase
{
    /**
     * @return iterable<string, array{Throwable, bool, bool}>
     */
    public static function failures(): iterable
    {
        yield 'deadlock' => [StorageFailures::deadlock(), true, true];
        yield 'lock wait timeout' => [StorageFailures::lockWaitTimeout(), true, true];
        yield 'serialization failure' => [StorageFailures::serializationFailure(), true, true];
        yield 'runner failure without previous' => [new TransientStorageFailure(2), true, true];
        yield 'wrapped deadlock' => [new RuntimeException('outer', 0, StorageFailures::deadlock()), true, true];
        yield 'lost savepoint (outcome unknown)' => [StorageFailures::lostSavepoint(), false, true];
        yield 'unique violation' => [StorageFailures::uniqueViolation(), false, false];
        yield 'connection lost' => [StorageFailures::connectionLost(), false, false];
        yield 'plain error' => [new RuntimeException('x'), false, false];
    }

    #[DataProvider('failures')]
    public function testClassification(Throwable $failure, bool $transient, bool $conflict): void
    {
        self::assertSame($transient, StorageConflicts::isTransient($failure));
        self::assertSame($conflict, StorageConflicts::isConflict($failure));
    }

    public function testCodes(): void
    {
        $request = new Request();
        self::assertSame(ConflictCode::RetrySafe, StorageConflicts::code($request, StorageFailures::deadlock()));
        self::assertSame(ConflictCode::Committed, StorageConflicts::code($request, StorageFailures::lostSavepoint()));

        $committed = StorageConflicts::committed(StorageFailures::lockWaitTimeout());
        self::assertTrue($committed->committed);
        self::assertSame(TransientFailureKind::LockWaitTimeout, $committed->kind);
        self::assertSame(ConflictCode::Committed, StorageConflicts::code($request, $committed));
        self::assertSame(TransientFailureKind::Retryable, StorageConflicts::committed(new TransientStorageFailure())->kind);

        // Request-level detection: something committed earlier in the same request (transaction-doctrine >= 1.6 mark).
        $ledger = new CommitLedger();
        $marked = new Request();
        $marked->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($ledger));
        self::assertSame(ConflictCode::RetrySafe, StorageConflicts::code($marked, StorageFailures::deadlock()));
        $ledger->record();
        self::assertSame(ConflictCode::Committed, StorageConflicts::code($marked, StorageFailures::deadlock()));
    }

    public function testMessengerFailuresThatAlreadyCommittedAnswerCommitted(): void
    {
        $request  = new Request();
        $envelope = new Envelope(new stdClass());
        self::assertSame(ConflictCode::RetrySafe, StorageConflicts::code($request, new HandlerFailedException($envelope, [StorageFailures::deadlock()])));

        $handled = $envelope->with(new HandledStamp(null, 'App\\Handler::__invoke'));
        self::assertSame(ConflictCode::Committed, StorageConflicts::code($request, new HandlerFailedException($handled, [StorageFailures::deadlock()])));

        $delayed = new DelayedMessageHandlingException([StorageFailures::deadlock()], $envelope);
        self::assertSame(ConflictCode::Committed, StorageConflicts::code($request, new RuntimeException('outer', 0, $delayed)));
    }

    public function testRetryAfterHint(): void
    {
        self::assertSame(7, StorageConflicts::retryAfterHint(new RuntimeException('x', 0, new TransientStorageFailure(7))));
        self::assertNull(StorageConflicts::retryAfterHint(StorageFailures::deadlock()));
    }
}
