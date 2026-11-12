<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound;

use Nowo\MailKitBundle\Graph\GraphMailboxProviderInterface;

/**
 * Default {@see GraphInboundProfilesProviderInterface} (1.9): `graph.inbound.profiles`, or the mailbox provider's
 * default profile when the list is empty.
 */
final class ConfiguredInboundProfiles implements GraphInboundProfilesProviderInterface
{
    /**
     * @param list<string> $profiles
     */
    public function __construct(
        private readonly array $profiles,
        private readonly GraphMailboxProviderInterface $mailboxes,
    ) {
    }

    /**
     * The configured profiles (or the default one).
     *
     * @return list<string>
     */
    public function inboundProfiles(): array
    {
        return $this->profiles !== [] ? $this->profiles : [$this->mailboxes->defaultProfile()];
    }
}
