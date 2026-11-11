<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use Nowo\MailKitBundle\Event\SentMailPersonalDataErasedEvent;

/**
 * Erasure port of the sent-mail log keyed by a subject (1.7, GDPR art. 17): the same erasure as
 * {@see SentMailErasureInterface::forgetAddress()} (which stays, unchanged), with the validated
 * {@see SentMailPersonalDataSubject} in and a {@see SentMailErasureResult} out. Exact match, complete (every row the
 * address received or sent, the address masked elsewhere) and idempotent (a second run returns zero counts).
 *
 * Transactions: without an open transaction on the log connection it runs in its own and dispatches
 * {@see SentMailPersonalDataErasedEvent} after the commit; inside the caller's transaction it joins it and dispatches
 * nothing (the returned result is the outcome). Always registered: {@see SentMailRecorder} whenever Doctrine is,
 * {@see NullSentMailErasure} otherwise.
 */
interface SentMailPersonalDataEraserInterface
{
    /**
     * Erases the log rows of the subject's address.
     *
     * @throws SentMailLogTableMissingException when a log table is missing while the entities are mapped
     */
    public function erase(SentMailPersonalDataSubject $subject): SentMailErasureResult;
}
