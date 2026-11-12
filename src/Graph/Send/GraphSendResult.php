<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Send;

/**
 * Outcome of a Graph send (1.8): the immutable Graph id of the message (`Prefer: IdType="ImmutableId"`, the same id
 * in Drafts and in Sent Items), its `internetMessageId` as Graph reports it (with angle brackets) and the conversation
 * id Outlook threads it under. Store them to match replies (`In-Reply-To` / `References` / `conversationId`).
 */
final class GraphSendResult
{
    /**
     * @param string $graphId immutable Graph message id
     * @param string $internetMessageId RFC 5322 Message-ID as reported by Graph (`<…@…>`)
     * @param string|null $conversationId Outlook conversation id
     * @param string $profile mailbox profile used
     */
    public function __construct(
        public readonly string $graphId,
        public readonly string $internetMessageId,
        public readonly ?string $conversationId,
        public readonly string $profile,
    ) {
    }
}
