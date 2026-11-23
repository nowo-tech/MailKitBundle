<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Graph;

use Nowo\MailKitBundle\Graph\GraphCredentialInterface;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSession;
use Nowo\MailKitBundle\Mailbox\Value\ProviderCall;

/**
 * A delegated mailbox's session as the Graph client's credential (2.5.0): the host's token, the 401 report and the
 * per-call metering. The operation labels passed by {@see GraphMailboxGateway} are the closed
 * {@see ProviderCall::OPERATIONS} list.
 *
 * @internal
 */
final readonly class DelegatedGraphCredential implements GraphCredentialInterface
{
    /**
     * Wraps the session.
     */
    public function __construct(private MailboxSession $session)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function token(): string
    {
        return $this->session->token()->reveal();
    }

    /**
     * {@inheritDoc}
     */
    public function rejected(): void
    {
        $this->session->rejected();
    }

    /**
     * {@inheritDoc}
     */
    public function logContext(): array
    {
        return ['mailbox' => $this->session->mailbox->value];
    }

    /**
     * {@inheritDoc}
     */
    public function called(string $operation, int $status, int $durationMs, int $bytes, ?int $retryAfter, int $attempt): void
    {
        $this->session->called($operation, $status, $durationMs, $bytes, $retryAfter, $attempt);
    }
}
