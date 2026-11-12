<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

use Symfony\Component\Mime\Email;

/**
 * The tracked copy of an outgoing e-mail and its send id (1.10, internal result of {@see EmailTracker}).
 */
final readonly class TrackedEmail
{
    /**
     * @param Email $email copy to deliver (pixel, rewritten links)
     * @param string $sendId opaque send id (32 hex characters)
     */
    public function __construct(
        public Email $email,
        public string $sendId,
    ) {
    }
}
