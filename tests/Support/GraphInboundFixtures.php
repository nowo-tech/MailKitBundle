<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\MailKitBundle\Graph\GraphMailbox;
use Nowo\MailKitBundle\Graph\GraphMailboxProviderInterface;

use function array_merge;

/**
 * Fixtures of the Graph inbound tests: a Graph message resource, a recording bus and the two
 * fixture mailboxes. No real tenant, mailbox or content.
 */
final class GraphInboundFixtures
{
    /** Minimal PDF bytes (detected as application/pdf). */
    public const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    /** PNG signature + IHDR (detected as image/png). */
    public const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\x0aIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\x0d\x0a\x2d\xb4\x00\x00\x00\x00IEND\xaeB`\x82";

    /** Windows executable header (detected as an executable, not allowed). */
    public const EXE = "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00\xb8\x00\x00\x00\x00\x00\x00\x00\x40\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x80\x00\x00\x00PE\x00\x00L\x01";

    /**
     * A Graph message resource.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function message(string $id, array $overrides = []): array
    {
        return array_merge([
            'id'                     => $id,
            'internetMessageId'      => '<reply-' . $id . '@client.example.net>',
            'conversationId'         => 'AAQkConv' . $id,
            'subject'                => 'Re: Offer for task 42',
            'from'                   => ['emailAddress' => ['name' => 'Client', 'address' => 'Client@Example.net']],
            'sender'                 => ['emailAddress' => ['name' => 'Client', 'address' => 'client@example.net']],
            'replyTo'                => [],
            'toRecipients'           => [['emailAddress' => ['name' => 'Projects', 'address' => 'projects+t-42@example.com']]],
            'ccRecipients'           => [['emailAddress' => ['address' => 'other@example.net']]],
            'receivedDateTime'       => '2026-11-12T10:00:00Z',
            'body'                   => ['contentType' => 'html', 'content' => '<p>Hello <b>team</b><script>alert(1)</script></p><img src="cid:plan@client">'],
            'hasAttachments'         => false,
            'internetMessageHeaders' => [
                ['name' => 'Authentication-Results', 'value' => 'spf=pass (sender IP is 192.0.2.1) smtp.mailfrom=example.net; dkim=pass header.d=example.net;dmarc=pass action=none header.from=example.net;compauth=pass reason=100'],
                ['name' => 'Authentication-Results', 'value' => 'spf=fail; dkim=fail; dmarc=fail'],
                ['name' => 'In-Reply-To', 'value' => '<task-42-1@example.com>'],
                ['name' => 'References', 'value' => '<task-42-0@example.com> <task-42-1@example.com>'],
            ],
        ], $overrides);
    }

    /**
     * Mailbox port of the two fixture mailboxes (`acme`, `globex`).
     */
    public static function mailboxes(): GraphMailboxProviderInterface
    {
        return new HostGraphMailboxProvider();
    }

    /**
     * Messenger bus that keeps the dispatched messages.
     */
    public static function bus(): RecordingBus
    {
        return new RecordingBus();
    }

    /**
     * The acme mailbox.
     */
    public static function acme(): GraphMailbox
    {
        return GraphApiMock::mailboxA();
    }
}
