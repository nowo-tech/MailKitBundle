<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Sender;

use Nowo\MailKitBundle\Settings\MailSettings;

/**
 * Port (1.12): the framework-free twin of the deprecated {@see SenderProviderInterface} (REQ-ARCH-001 rule 3): a
 * bundle-owned {@see MailAddress} instead of the Symfony Mime `Address`. Source of the default sender of outgoing
 * messages — {@see SenderPolicy} adds this `From` / `Reply-To` to a message that has none.
 *
 * Set `nowo_mail_kit.mailer.sender_provider` to the service id of your implementation; the option takes either
 * interface, and MailKit wraps a twin implementation in an internal gate ({@see SenderProviderGate}) so the rest of
 * the bundle keeps working. Implement this one, not the deprecated port: a DDD / hexagonal host whose sender identity
 * lives in its domain (tenant, brand, its own configuration) then needs no Symfony Mime type.
 *
 * The built-in implementation is {@see MailSettings} (the `mailer` Instance Settings section), which implements both.
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
