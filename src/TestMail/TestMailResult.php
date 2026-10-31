<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\TestMail;

/**
 * Result of a test e-mail: outcome, recipient used and scrubbed transport error.
 */
final readonly class TestMailResult
{
    /**
     * @param string|null $error scrubbed transport error ({@see TestMailOutcome::Failed})
     */
    public function __construct(
        public TestMailOutcome $outcome,
        public ?string $recipient = null,
        public ?string $error = null,
    ) {
    }

    /**
     * True when the message was accepted.
     */
    public function isSent(): bool
    {
        return $this->outcome === TestMailOutcome::Sent;
    }

    /**
     * Placeholders of the outcome message.
     *
     * @return array<string, string>
     */
    public function parameters(): array
    {
        return ['%to%' => (string) $this->recipient, '%error%' => (string) $this->error];
    }
}
