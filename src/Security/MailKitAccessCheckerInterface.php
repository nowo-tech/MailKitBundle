<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Security;

/**
 * Access to the MailKit admin UI (sent-mail log and the settings actions) — REQ-UI-002. Replace the role checker
 * with `nowo_mail_kit.security.access_checker: <service id>`.
 */
interface MailKitAccessCheckerInterface
{
    /**
     * True when the user may open the UI.
     *
     * @param object|null $user security user (null = anonymous)
     */
    public function canAccess(?object $user): bool;
}
