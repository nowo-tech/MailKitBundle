<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound\Message;

/**
 * Messenger message (1.9): create or renew the change-notification subscriptions that expire within
 * `subscriptions.renew_before` (null = every inbound profile). Schedule it hourly.
 */
final class RenewGraphSubscriptions
{
    /**
     * @param string|null $profile mailbox profile, null for all
     */
    public function __construct(
        public readonly ?string $profile = null,
    ) {
    }
}
