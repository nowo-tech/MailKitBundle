<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

/**
 * One link of a tracked e-mail handed to the {@see EmailLinkRewriterInterface} (1.10). Only absolute `http(s)` links
 * that are not credential-bearing ({@see CredentialLinkDetector}) and that the {@see TrackableLinkFilterInterface}
 * allows get here.
 *
 * Plain PHP value object (no Doctrine, Symfony or HTTP types).
 */
final readonly class TrackedLink
{
    /**
     * @param string $sendId opaque send id (32 hex characters)
     * @param string $url the original absolute http(s) URL (HTML entities decoded)
     * @param string|null $label visible text of the link (or its `aria-label`, image `alt`, `title`), white space
     *                           collapsed, at most 100 characters; null when it has none
     * @param int $position 1-based position among the http(s) links of the HTML part
     * @param bool $essential unsubscribe / preferences / legal link (`tracking.links.essential_patterns`): track it but
     *                        never block, expire or rate-limit the redirect
     * @param string|null $templateId template id of the e-mail
     * @param string|null $recipientId host recipient id of the send
     * @param string|null $tenant tenant key of the send
     */
    public function __construct(
        public string $sendId,
        public string $url,
        public ?string $label,
        public int $position,
        public bool $essential,
        public ?string $templateId = null,
        public ?string $recipientId = null,
        public ?string $tenant = null,
    ) {
    }
}
