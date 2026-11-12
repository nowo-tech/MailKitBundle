<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\GraphMailbox;
use Nowo\MailKitBundle\Graph\GraphMailboxProviderInterface;

/**
 * Host mailbox port of a multi-tenant DDD application (one shared mailbox and app per organization, as from encrypted
 * host settings): builds the mailbox per call, keeps nothing (worker mode). Fixture tenants only.
 */
final class HostGraphMailboxProvider implements GraphMailboxProviderInterface
{
    public function mailbox(string $profile): GraphMailbox
    {
        return match ($profile) {
            'acme'   => GraphApiMock::mailboxA(),
            'globex' => GraphApiMock::mailboxB(),
            default  => throw GraphException::configuration('Unknown organization.'),
        };
    }

    public function defaultProfile(): string
    {
        return 'acme';
    }
}
