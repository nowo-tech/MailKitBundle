<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Symfony\Component\Mailer\SentMessage;

/**
 * Messages delivered by {@see MemoryTransport}, keyed by the transport host (`memory://<host>`).
 */
final class MemoryMailStore
{
    /** @var list<array{host: string, message: SentMessage}> */
    public array $sent = [];

    /**
     * @return list<SentMessage>
     */
    public function sentVia(string $host): array
    {
        $list = [];
        foreach ($this->sent as $entry) {
            if ($entry['host'] === $host) {
                $list[] = $entry['message'];
            }
        }

        return $list;
    }
}
