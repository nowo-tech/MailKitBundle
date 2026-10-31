<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Test transport `memory://<host>`: keeps the messages in {@see MemoryMailStore}; host `fail` throws a transport error
 * that carries a credential-like URL (the log must scrub it).
 */
final class MemoryTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $host,
        private readonly MemoryMailStore $store,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'memory://' . $this->host;
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->host === 'fail') {
            throw new TransportException('Connection refused by smtp://user:REDACTED-TEST@relay.example.com:25');
        }
        $this->store->sent[] = ['host' => $this->host, 'message' => $message];
    }
}
