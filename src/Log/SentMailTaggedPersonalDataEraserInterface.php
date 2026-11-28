<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use InvalidArgumentException;
use Nowo\MailKitBundle\Event\SentMailPersonalDataErasedEvent;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Nowo\TransactionContract\TransientStorageFailure;

/**
 * Erasure port of the sent-mail log limited to one tag (2.8): {@see SentMailPersonalDataEraserInterface::erase()} for
 * the rows of the subject whose tag ({@see MailHeaders::tag()}) is exactly the given one. For a caller that owns only
 * part of the log (a tenant's module that tags every message it sends): rows with another tag or no tag stay as they
 * are, also their subjects and bodies.
 *
 * Same semantics as {@see SentMailPersonalDataEraserInterface::erase()} within the tag: exact address match, a row
 * with other recipients is unlinked and the address masked in it, a row with nobody else is deleted with its body, the
 * address is masked in the subjects, errors and stored bodies of the other rows with that tag; idempotent. Exact tag
 * match ({@see SentMailRecorder::tagFilter()}): SQL pre-filters, PHP re-checks with `===`, so a case- or
 * pad-insensitive collation never widens the scope.
 *
 * Transactions: without an open transaction on the log connection it runs in its own and dispatches
 * {@see SentMailPersonalDataErasedEvent} (with the tag) after the commit; inside the caller's transaction it joins it
 * and dispatches nothing. Always registered: {@see SentMailRecorder} whenever Doctrine is, {@see NullSentMailErasure}
 * otherwise. A separate port (not a method on {@see SentMailPersonalDataEraserInterface}) so existing implementations
 * keep compiling.
 */
interface SentMailTaggedPersonalDataEraserInterface
{
    /**
     * Erases the log rows of the subject's address that carry the tag.
     *
     * @param string $tag exact tag of the rows (1 to {@see MailHeaders::TAG_MAX} characters, trimmed)
     *
     * @throws InvalidArgumentException for an empty tag, a tag with control characters or longer than
     *                                  {@see MailHeaders::TAG_MAX} characters
     * @throws SentMailLogTableMissingException when a log table is missing while the entities are mapped
     * @throws TransientStorageFailure when the erasure runs its own transaction (none open on the log
     *                                 connection) and meets a deadlock or lock wait timeout: rolled back,
     *                                 nothing committed, run it again (2.9.0). Inside the caller's
     *                                 transaction the database error is thrown instead.
     */
    public function eraseTagged(SentMailPersonalDataSubject $subject, string $tag): SentMailErasureResult;
}
