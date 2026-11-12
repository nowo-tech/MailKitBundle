<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\DependencyInjection;

use Nowo\MailKitBundle\DependencyInjection\Configuration;
use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\Send\GraphSendLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
