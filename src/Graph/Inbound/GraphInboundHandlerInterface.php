<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound;

/**
 * Host port (1.9): decides what an inbound message becomes — route it (e.g. to the task named by its plus-address
 * tag or its thread ids), quarantine it (unknown sender) or discard it — and stores what it needs **inside this call**
 * (attachment streams are deleted afterwards). Plain value objects in, a bundle value object out.
 *
 * Called at most once per message and mailbox profile (idempotency by internetMessageId); an exception makes MailKit
 * forget the claim, so the message is processed again on the next delivery / retry. Write in your own transaction.
 */
interface GraphInboundHandlerInterface
{
    /**
     * The decision for one message.
     */
    public function handle(GraphInboundMessage $message): InboundDecision;
}
