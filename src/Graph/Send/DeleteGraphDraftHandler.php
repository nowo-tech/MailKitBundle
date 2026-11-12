<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Send;

use function preg_match;

/**
 * Messenger handler of {@see DeleteGraphDraft} (registered with `graph.draft_cleanup.bus`).
 */
final class DeleteGraphDraftHandler
{
    /** Graph ids accepted (as for inbound messages). */
    private const ID_PATTERN = '/^[A-Za-z0-9+\/=_@.-]{1,512}\z/';

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
        if (preg_match(self::ID_PATTERN, $message->graphId) !== 1) {
            return;
        }
        $this->sender->deleteDraftIfStillDraft($message->profile, $message->graphId);
    }
}
