<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Engine;

/**
 * One page of a folder's changes (2.5.0) and the cursor after it: the next page's link, or the delta link when the
 * page completed the sweep.
 *
 * @internal
 */
final readonly class ChangePage
{
    /**
     * @param list<ChangeItem> $items
     * @param bool $complete the cursor is the delta link (nothing more to read now)
     */
    public function __construct(
        public array $items,
        public string $cursor,
        public bool $complete,
    ) {
    }
}
