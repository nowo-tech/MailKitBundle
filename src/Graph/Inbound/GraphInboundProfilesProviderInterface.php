<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound;

/**
 * Host port (1.9): the mailbox profiles whose inbox MailKit reads (subscriptions and delta sweep). The default
 * ({@see ConfiguredInboundProfiles}) is `graph.inbound.profiles`; a host with a mailbox per organization returns the
 * organizations that configured one. Called per run (worker mode: keep nothing).
 */
interface GraphInboundProfilesProviderInterface
{
    /**
     * Profiles to read.
     *
     * @return list<string>
     */
    public function inboundProfiles(): array;
}
