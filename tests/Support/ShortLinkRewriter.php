<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\MailKitBundle\Tracking\EmailLinkRewriterInterface;
use Nowo\MailKitBundle\Tracking\TrackedLink;

use function substr;

/**
 * Link rewriter double like Beacon's short links: `https://app.example.com/s/<send id 8>-<position>`; records the links
 * it was given.
 */
final class ShortLinkRewriter implements EmailLinkRewriterInterface
{
    /** @var list<TrackedLink> */
    public array $links = [];

    public function rewrite(TrackedLink $link): string
    {
        $this->links[] = $link;

        return 'https://app.example.com/s/' . substr($link->sendId, 0, 8) . '-' . $link->position;
    }
}
