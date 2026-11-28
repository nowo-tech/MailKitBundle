<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use Nowo\MailKitBundle\Mailer\MailHeaders;

/**
 * Export port of the sent-mail log (1.7, GDPR art. 15 / 20): the log rows one address received (to, cc, bcc) or sent,
 * matched exactly on the normalised address ({@see SentMailPersonalDataSubject}). Erasure stays
 * {@see SentMailErasureInterface::forgetAddress()}.
 *
 * The envelope, the error text and (2.8.1) the stored body (`html_body` / `text_body`, null unless `log.store_bodies`
 * kept one), with the addresses of the row's other recipients and sender masked in the error and the body. Never
 * exported: the other recipients, the transport and the provider message id. Secrets were already masked when the
 * body was stored.
 * Always registered: {@see SentMailRecorder} whenever Doctrine is, {@see NullSentMailPersonalDataExporter} otherwise.
 */
interface SentMailPersonalDataExporterInterface
{
    /**
     * Rows of the address, oldest id first. Dates are ATOM strings; `error`, `html_body` and `text_body` were added in
     * 2.8.1 (null when there is none); `template` is the message tag
     * ({@see MailHeaders::tag()}); `roles` says whether the address was a recipient, the
     * sender or both.
     *
     * @throws SentMailLogTableMissingException when the log table is missing while the entities are mapped
     *
     * @return list<array{id: string, roles: list<'recipient'|'sender'>, status: string, template: string|null, subject: string, queued_at: string|null, sent_at: string|null, failed_at: string|null, error: string|null, html_body: string|null, text_body: string|null}>
     */
    public function export(SentMailPersonalDataSubject $subject): array;
}
