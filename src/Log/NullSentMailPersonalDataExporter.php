<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

/**
 * {@see SentMailPersonalDataExporterInterface} on a host without Doctrine (the log requires it): the bundle cannot have
 * stored anything, so there is nothing to export. With Doctrine registered the port is always {@see SentMailRecorder}.
 */
final readonly class NullSentMailPersonalDataExporter implements SentMailPersonalDataExporterInterface
{
    /**
     * Nothing stored.
     *
     * @return list<array{id: string, roles: list<'recipient'|'sender'>, status: string, template: string|null, subject: string, queued_at: string|null, sent_at: string|null, failed_at: string|null}>
     */
    public function export(SentMailPersonalDataSubject $subject): array
    {
        return [];
    }
}
