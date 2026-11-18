<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Concurrency;

use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

/**
 * Storage conflicts of the bundle's writes (REQ-RUNTIME-003 rules 6 and 8, 1.13.0), on top of transaction-doctrine's
 * classifier: a deadlock, lock wait timeout or serialization failure (MySQL / MariaDB 1213, 1205, 1020; PostgreSQL
 * 40001, 40P01, 55P03), a {@see TransientStorageFailure} of a runner, or a lost savepoint of unknown outcome.
 *
 * Unique (and other constraint) violations and lost connections are never conflicts, wherever they sit in the chain:
 * the classifier excludes them. Stateless (static methods only).
 */
final class StorageConflicts
{
    /**
     * Whether a replay of the failed write can succeed (deadlock, lock wait timeout, serialization failure, or a
     * runner's transient failure). Never true for a unique violation or a lost connection.
     */
    public static function isTransient(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return true;
            }
        }

        return (new TransientFailureClassifier())->isTransient($failure);
    }

    /**
     * Whether the failure gets the conflict answer: transient, or a lost savepoint whose commit outcome is unknown.
     */
    public static function isConflict(Throwable $failure): bool
    {
        return self::isTransient($failure) || (new TransientFailureClassifier())->isCommitOutcomeUnknown($failure);
    }

    /**
     * Marks a transient failure met after part of the work was committed: the conflict answer is then CONFLICT_002.
     */
    public static function committed(Throwable $failure): ConcurrencyConflict
    {
        return new ConcurrencyConflict(1, (new TransientFailureClassifier())->classify($failure) ?? TransientFailureKind::Retryable, null, $failure, true);
    }

    /**
     * The conflict code: CONFLICT_002 when the chain holds a committed {@see ConcurrencyConflict}, a Messenger
     * HandlerFailedException whose envelope carries a HandledStamp (a handler already completed) or a
     * DelayedMessageHandlingException (the outer dispatch completed), when the request's commit mark of
     * transaction-doctrine >= 1.6 saw a commit, or for a lost savepoint; CONFLICT_001 otherwise.
     */
    public static function code(Request $request, Throwable $failure): ConflictCode
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof DelayedMessageHandlingException) {
                return ConflictCode::Committed;
            }
            if ($current instanceof HandlerFailedException && $current->getEnvelope()->all(HandledStamp::class) !== []) {
                return ConflictCode::Committed;
            }
        }

        return ConflictCode::forRequest($request, $failure);
    }

    /**
     * The Retry-After hint of the first runner failure of the chain, or null (the configured value).
     */
    public static function retryAfterHint(Throwable $failure): ?int
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return $current->retryAfterSeconds();
            }
        }

        return null;
    }
}
