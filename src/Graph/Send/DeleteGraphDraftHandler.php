<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Send;

/**
 * Messenger handler of {@see DeleteGraphDraft} (registered with `graph.draft_cleanup.bus`).
 */
final class DeleteGraphDraftHandler
{
    /**
     * @param GraphMailSender $sender reads `isDraft` and deletes
     */
    public function __construct(
        private readonly GraphMailSender $sender,
    ) {
    }

    /**
     * Deletes the draft when it is still a draft.
     */
    public function __invoke(DeleteGraphDraft $message): void
    {
        $this->sender->deleteDraftIfStillDraft($message->profile, $message->graphId);
    }
}
