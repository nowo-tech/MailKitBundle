<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound\Message;

/**
 * Messenger message (1.9): create or renew the change-notification subscriptions that expire within
 * `subscriptions.renew_before` (null profile = every inbound profile). Schedule it hourly. A lifecycle notification
 * names a reason: {@see REAUTHORIZE} (Graph asks to reauthorize the subscription) or {@see REMOVED} (Graph removed
 * it: recreate).
 */
final class RenewGraphSubscriptions
{
    /** Lifecycle `reauthorizationRequired`. */
    public const REAUTHORIZE = 'reauthorize';

    /** Lifecycle `subscriptionRemoved`. */
    public const REMOVED = 'removed';

    /**
     * @param string|null $profile mailbox profile, null for all
     * @param string|null $reason null (scheduled renewal), REAUTHORIZE or REMOVED
     */
    public function __construct(
        public readonly ?string $profile = null,
        public readonly ?string $reason = null,
    ) {
    }
}
