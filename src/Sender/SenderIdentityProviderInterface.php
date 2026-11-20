<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Sender;

use Nowo\MailKitBundle\Settings\MailSettings;

/**
 * Port (1.12; the only sender port since 2.0, REQ-ARCH-001 rule 3): a bundle-owned {@see MailAddress} instead of the
 * Symfony Mime `Address`. Source of the default sender of outgoing messages — {@see SenderPolicy} adds this `From` /
 * `Reply-To` to a message that has none.
 *
 * Set `nowo_mail_kit.mailer.sender_provider` to the service id of your implementation. A DDD / hexagonal host whose
 * sender identity lives in its domain (tenant, brand, its own configuration) then needs no Symfony Mime type. The
 * Mime-typed `SenderProviderInterface` (deprecated since 1.12) was removed in 2.0.
 *
 * The built-in implementation is {@see MailSettings} (the `mailer` Instance Settings section).
 *
 * Read on every send: implementations must not memoize across requests (REQ-RUNTIME-002, FrankenPHP worker mode with
 * `kernel.reset: false`).
 */
interface SenderIdentityProviderInterface
{
    /**
     * Default `From`, or null to leave the message without one (the framework / transport default then applies).
     */
    public function defaultFrom(): ?MailAddress;

    /**
     * Default `Reply-To`, or null for none.
     */
    public function defaultReplyTo(): ?MailAddress;
}
