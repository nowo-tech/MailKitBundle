<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Engine;

use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\MessageEnvelope;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;

/**
 * A message as fetched, before the exclusion decision (2.5.0): its envelope and the provider's raw resource, held in
 * memory only for the current operation. An excluded message is dropped here, never normalised.
 *
 * @internal
 */
final readonly class FetchedMessage
{
    /**
     * @param array<mixed> $data provider resource
     * @param bool $oversized over the size limit: body and headers were not read
     */
    public function __construct(
        public ProviderMessageId $id,
        public FolderRef $folder,
        public MessageEnvelope $envelope,
        public array $data,
        public bool $oversized,
    ) {
    }
}
