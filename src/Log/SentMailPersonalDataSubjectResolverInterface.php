<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

/**
 * SPI (1.7): the addresses whose sent-mail log rows belong to a host user, for the UserKit export provider and eraser
 * (`emails_received`). Set your own with `nowo_mail_kit.personal_data.subject_resolver`; the default is
 * {@see DefaultSentMailPersonalDataSubjectResolver}. Must keep no per-user state (FrankenPHP worker mode).
 */
interface SentMailPersonalDataSubjectResolverInterface
{
    /**
     * Distinct subjects of the user; empty when the user has no address.
     *
     * @param object $user host user
     * @param string|null $originalIdentifier the user identifier before any eraser ran (UserKit `ErasureRequest::identifier()`),
     *                                        the current identifier on export
     *
     * @return list<SentMailPersonalDataSubject>
     */
    public function subjectsFor(object $user, ?string $originalIdentifier): array;
}
