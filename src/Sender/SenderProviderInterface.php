<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Sender;

use Nowo\MailKitBundle\Settings\MailSettings;
use Symfony\Component\Mime\Address;

/**
 * Source of the default sender of outgoing messages (1.5, DDD P2): {@see SenderPolicy} adds this `From` / `Reply-To`
 * to a message that has none.
 *
 * The default implementation is {@see MailSettings} (the `mailer` Instance Settings
 * section). A host whose sender identity lives elsewhere (tenant, brand, its own configuration) implements this
 * interface and sets `nowo_mail_kit.mailer.sender_provider` to its service id. Read on every send: implementations
 * must not memoize across requests (worker mode).
 */
interface SenderProviderInterface
{
    /**
     * Default `From`, or null to leave the message without one (the framework / transport default applies).
     */
    public function from(): ?Address;

    /**
     * Default `Reply-To`, or null for none.
     */
    public function replyTo(): ?Address;
}
