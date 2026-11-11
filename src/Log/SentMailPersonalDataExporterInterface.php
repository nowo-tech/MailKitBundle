<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use Nowo\MailKitBundle\Mailer\MailHeaders;

/**
 * Export port of the sent-mail log (1.7, GDPR art. 15 / 20): the log rows one address received (to, cc, bcc) or sent,
 * matched exactly on the normalised address ({@see SentMailPersonalDataSubject}). Erasure stays
 * {@see SentMailErasureInterface::forgetAddress()}.
 *
 * Envelope data only: the bodies are never exported (they may hold other people's data and masked secrets, and are
 * stored only with `log.store_bodies`), nor the other recipients, the transport, the provider message id or the error.
 * Always registered: {@see SentMailRecorder} whenever Doctrine is, {@see NullSentMailPersonalDataExporter} otherwise.
 */
interface SentMailPersonalDataExporterInterface
{
    /**
     * Rows of the address, oldest id first. Dates are ATOM strings; `template` is the message tag
     * ({@see MailHeaders::tag()}); `roles` says whether the address was a recipient, the
     * sender or both.
     *
     * @throws SentMailLogTableMissingException when the log table is missing while the entities are mapped
     *
     * @return list<array{id: string, roles: list<'recipient'|'sender'>, status: string, template: string|null, subject: string, queued_at: string|null, sent_at: string|null, failed_at: string|null}>
     */
    public function export(SentMailPersonalDataSubject $subject): array;
}
