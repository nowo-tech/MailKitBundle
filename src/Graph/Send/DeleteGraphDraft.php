<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Send;

/**
 * Messenger message (1.9, audit NQ-L5): delete a draft left by an ambiguous send, **only if it is still a draft**
 * when the message is handled (dispatched with a `DelayStamp` of `graph.draft_cleanup.delay_ms`). Ids only.
 */
final class DeleteGraphDraft
{
    /**
     * @param string $profile mailbox profile
     * @param string $graphId immutable Graph id of the draft
     */
    public function __construct(
        public readonly string $profile,
        public readonly string $graphId,
    ) {
    }
}
