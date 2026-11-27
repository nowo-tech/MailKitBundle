<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use InvalidArgumentException;
use Nowo\MailKitBundle\Mailer\MailHeaders;

/**
 * Export port of the sent-mail log limited to one tag (2.8): {@see SentMailPersonalDataExporterInterface::export()}
 * for the rows of the subject whose tag ({@see MailHeaders::tag()}) is exactly the given one. For a caller that owns
 * only part of the log (a tenant's module that tags every message it sends). Same row shape, envelope only, exact
 * address and tag match. Always registered: {@see SentMailRecorder} whenever Doctrine is,
 * {@see NullSentMailPersonalDataExporter} otherwise. A separate port so existing exporter implementations keep
 * compiling.
 */
interface SentMailTaggedPersonalDataExporterInterface
{
    /**
     * Rows of the address with the tag, oldest id first.
     *
     * @param string $tag exact tag of the rows (1 to {@see MailHeaders::TAG_MAX} characters, trimmed)
     *
     * @throws InvalidArgumentException for an empty tag, a tag with control characters or longer than
     *                                  {@see MailHeaders::TAG_MAX} characters
     * @throws SentMailLogTableMissingException when the log table is missing while the entities are mapped
     *
     * @return list<array{id: string, roles: list<'recipient'|'sender'>, status: string, template: string|null, subject: string, queued_at: string|null, sent_at: string|null, failed_at: string|null}>
     */
    public function exportTagged(SentMailPersonalDataSubject $subject, string $tag): array;
}
