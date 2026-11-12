<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\MailKitBundle\DependencyInjection\Configuration;
use Nowo\MailKitBundle\DependencyInjection\NowoMailKitExtension;
use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\Inbound\InboundLimits;
use Nowo\MailKitBundle\Graph\Send\GraphSendLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class GraphConfigurationTest extends TestCase
{
    private const PROFILE = [
        'tenant_id'     => '%env(GRAPH_TENANT)%',
        'client_id'     => '%env(GRAPH_CLIENT)%',
        'client_secret' => '%env(GRAPH_SECRET)%',
        'mailbox'       => 'projects@example.com',
    ];

    /**
     * @param array<string, mixed> $graph
     *
     * @return array<string, mixed>
     */
    private function process(array $graph): array
    {
        /** @var array{graph: array<string, mixed>} $config */
        $config = (new Processor())->processConfiguration(new Configuration(), [['graph' => $graph]]);

        return $config['graph'];
    }

    public function testDefaults(): void
    {
        $graph = $this->process([]);
        self::assertFalse($graph['enabled']);
        self::assertNull($graph['mailbox_provider']);
        self::assertSame('default', $graph['default_profile']);
        self::assertSame([], $graph['profiles']);
        self::assertSame('http_client', $graph['http_client']);
        self::assertSame('cache.app', $graph['token_cache']);
        self::assertSame(30.0, $graph['timeout']);
        self::assertSame(GraphApiClient::DEFAULT_UPLOAD_HOSTS, $graph['upload_hosts']);
        self::assertSame(['max_attachments' => 50, 'max_attachment_bytes' => GraphSendLimits::DEFAULT_MAX_BYTES, 'max_message_bytes' => GraphSendLimits::DEFAULT_MAX_BYTES], $graph['limits']);
    }

    public function testInboundDefaults(): void
    {
        $inbound = $this->process([])['inbound'];
        self::assertIsArray($inbound);
        self::assertFalse($inbound['enabled']);
        self::assertSame('/hooks/mail-kit/v1/graph', $inbound['webhook']['path']);
        self::assertNull($inbound['webhook']['notification_url']);
        self::assertSame('P2D', $inbound['sweep']['initial_window']);
        self::assertSame(InboundLimits::DEFAULT_ALLOWED_TYPES, $inbound['limits']['allowed_types']);
        $custom = $this->process(['inbound' => ['limits' => ['allowed_types' => ['IMAGE/*']], 'webhook' => ['notification_url' => 'https://app.example.com/hooks/mail-kit/v1/graph']]])['inbound'];
        self::assertIsArray($custom);
        self::assertSame(['image/*'], $custom['limits']['allowed_types']);
        self::assertSame(['bus' => null, 'delay_ms' => 5000], $this->process([])['draft_cleanup']);
        self::assertNotContains('application/zip', $inbound['limits']['allowed_types']);
        self::assertSame(60, $inbound['webhook']['client_rate_limit']);
        self::assertSame([], $inbound['webhook']['allowed_ips']);
    }

    public function testIpRanges(): void
    {
        foreach (['203.0.113.7', '203.0.113.0/24', '0.0.0.0/0', '2001:db8::/32', '2001:db8::1', '::/0'] as $range) {
            self::assertTrue(Configuration::isIpRange($range), $range);
        }
        foreach (['203.0.113.0/33', '2001:db8::/129', 'x/24', '203.0.113.0/', '203.0.113.0/a'] as $range) {
            self::assertFalse(Configuration::isIpRange($range), $range);
        }
        $inbound = $this->process(['inbound' => ['webhook' => ['allowed_ips' => ['203.0.113.0/24', '2001:db8::/32']]]])['inbound'];
        self::assertIsArray($inbound);
        self::assertSame(['203.0.113.0/24', '2001:db8::/32'], $inbound['webhook']['allowed_ips']);
    }

    public function testRequireInterface(): void
    {
        NowoMailKitExtension::requireInterface(LoggerInterface::class, 'x');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('needs it');
        NowoMailKitExtension::requireInterface('Nope\\Missing\\Interface', 'needs it');
    }

    public function testEnabledWithProfilesOrProvider(): void
    {
        $graph = $this->process(['enabled' => true, 'profiles' => ['default' => self::PROFILE], 'upload_hosts' => [' Outlook.Office.com ']]);
        self::assertSame(self::PROFILE, $graph['profiles']['default']);
        self::assertSame(['outlook.office.com'], $graph['upload_hosts']);
        self::assertSame('App\\Mail\\Mailboxes', $this->process(['enabled' => true, 'mailbox_provider' => 'App\\Mail\\Mailboxes'])['mailbox_provider']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalid(): iterable
    {
        yield 'no mailbox' => [['enabled' => true], 'needs a mailbox_provider service or a profile'];
        yield 'default profile missing' => [['enabled' => true, 'default_profile' => 'other', 'profiles' => ['default' => self::PROFILE]], 'needs a mailbox_provider'];
        yield 'bad profile name' => [['profiles' => ['a/b' => self::PROFILE]], 'profiles names'];
        yield 'bad default profile' => [['default_profile' => 'a b'], 'default_profile must be a profile name'];
        yield 'missing secret' => [['profiles' => ['default' => ['tenant_id' => 'x', 'client_id' => 'y', 'mailbox' => 'z']]], 'client_secret'];
        yield 'timeout' => [['timeout' => 0.1], 'timeout'];
        yield 'attachment above Graph max' => [['limits' => ['max_attachment_bytes' => GraphSendLimits::GRAPH_MAX_ATTACHMENT_BYTES + 1]], 'max_attachment_bytes'];
        yield 'no upload host' => [['upload_hosts' => []], 'upload_hosts'];
        yield 'inbound profile' => [['inbound' => ['profiles' => ['a b']]], 'inbound.profiles must contain profile names'];
        yield 'inbound folder' => [['inbound' => ['folder' => "inbox')/x"]], 'well-known folder name'];
        yield 'webhook path' => [['inbound' => ['webhook' => ['path' => 'relative']]], 'webhook.path must be an absolute path'];
        yield 'notification url http' => [['inbound' => ['webhook' => ['notification_url' => 'http://app.example.com/h']]], 'absolute https:// URL'];
        yield 'notification url userinfo' => [['inbound' => ['webhook' => ['notification_url' => 'https://u:p@app.example.com/h']]], 'absolute https:// URL'];
        yield 'sweep window' => [['inbound' => ['sweep' => ['initial_window' => 'P1Y']]], 'initial_window'];
        yield 'retention shorter than the sweep window' => [['inbound' => ['retention_days' => 2, 'sweep' => ['initial_window' => 'P2DT1H']]], 'retention_days must exceed'];
        yield 'allowed ip' => [['inbound' => ['webhook' => ['allowed_ips' => ['203.0.113.0/33']]]], 'allowed_ips must contain IP addresses or CIDR ranges'];
        yield 'allowed ip name' => [['inbound' => ['webhook' => ['allowed_ips' => ['graph.microsoft.com']]]], 'allowed_ips must contain'];
        yield 'lifetime' => [['inbound' => ['subscriptions' => ['lifetime_minutes' => 20_000]]], 'lifetime_minutes'];
    }

    /**
     * @param array<string, mixed> $graph
     */
    #[DataProvider('invalid')]
    public function testInvalid(array $graph, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);
        $this->process($graph);
    }
}
