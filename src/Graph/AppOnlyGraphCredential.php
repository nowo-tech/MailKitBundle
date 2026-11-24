<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph;

/**
 * App-only credential of a shared mailbox profile (1.8 behaviour behind {@see GraphCredentialInterface}, 2.5.0):
 * client-credentials token from {@see GraphTokenProvider} (cached), forgotten on 401. Built per call; holds no token.
 *
 * @internal
 */
final readonly class AppOnlyGraphCredential implements GraphCredentialInterface
{
    /**
     * Wires the token provider and the mailbox profile.
     */
    public function __construct(
        private GraphTokenProvider $tokens,
        private GraphMailbox $mailbox,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function token(): string
    {
        return $this->tokens->token($this->mailbox);
    }

    /**
     * {@inheritDoc}
     */
    public function rejected(): void
    {
        $this->tokens->forget($this->mailbox);
    }

    /**
     * {@inheritDoc}
     */
    public function logContext(): array
    {
        return ['profile' => $this->mailbox->profile];
    }

    /**
     * {@inheritDoc}
     */
    public function called(string $operation, int $status, int $durationMs, int $bytes, ?int $retryAfter, int $attempt): void
    {
    }
}
