<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\MailKitBundle\DependencyInjection\Compiler\MailboxUrlGuardPass;
use Nowo\MailKitBundle\DependencyInjection\Configuration;
use Nowo\MailKitBundle\DependencyInjection\NowoMailKitExtension;
use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\GuardedGraphRequestOptions;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function implode;

/**
 * 2.5.0 `mailboxes` configuration: off by default with the design's defaults, the build-time checks, and the
 * OutboundUrlGuard host pin (auto | required | off).
 */
final class MailboxConfigurationTest extends TestCase
{
    private const PORTS = ['token_provider' => 'app.tokens', 'directory' => 'app.directory', 'sink' => 'app.sink'];

    public function testDefaults(): void
    {
        $mailboxes = $this->process([]);
        self::assertFalse($mailboxes['enabled']);
        self::assertSame(['initial_window' => 'P7D', 'max_initial_window' => 'P90D', 'resync_window' => 'P7D', 'page_size' => 50, 'max_pages_per_run' => 20, 'lease_seconds' => 120], $mailboxes['sync']);
        self::assertSame(['max_message_bytes' => 26_214_400, 'max_html_bytes' => 1_048_576, 'max_attachment_bytes' => 26_214_400], $mailboxes['limits']);
        self::assertSame('blocked', $mailboxes['html']['remote_images']);
        self::assertSame(10, $mailboxes['max_folders']);
        self::assertFalse($mailboxes['graph']['enabled']);
        self::assertSame('P1D', $mailboxes['graph']['renew_before']);
        self::assertSame('auto', $mailboxes['graph']['outbound_url_guard']);
        self::assertSame('/hooks/mail-kit/v1/mailbox/graph', $mailboxes['graph']['webhook']['path']);
        self::assertNull($mailboxes['graph']['webhook']['notification_url']);
        self::assertSame(['limit' => 600, 'interval' => '1 minute'], $mailboxes['graph']['webhook']['rate_limit']);
        self::assertSame(['limit' => 60, 'interval' => '1 minute'], $mailboxes['graph']['webhook']['client_rate_limit']);
        self::assertNull($mailboxes['message_index']);
        self::assertSame(GraphApiClient::DEFAULT_UPLOAD_HOSTS, $mailboxes['graph']['upload_hosts']);
        self::assertSame([
            'stale_claim_seconds'  => 300,
            'retention'            => 'P7D',
            'max_recipients'       => 100,
            'max_attachments'      => 50,
            'max_attachment_bytes' => 36_700_160,
            'max_message_bytes'    => 36_700_160,
            'max_html_bytes'       => 1_048_576,
            'max_text_bytes'       => 1_048_576,
            'max_attempts'         => 5,
            'sent_copy'            => ['delay_ms' => 5000, 'attempts' => 3],
        ], $mailboxes['send']);

        $on = $this->process(['enabled' => true, 'graph' => ['enabled' => true, 'webhook' => ['notification_url' => 'https://app.example.com/hooks/mail-kit/v1/mailbox/graph', 'allowed_ips' => ['203.0.113.0/24']]], 'sync' => ['initial_window' => 'PT12H']] + self::PORTS);
        self::assertTrue($on['graph']['enabled']);
        self::assertSame('PT12H', $on['sync']['initial_window']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalid(): iterable
    {
        yield 'enabled without ports' => [['enabled' => true]];
        yield 'graph without mailboxes' => [['graph' => ['enabled' => true]]];
        yield 'window in minutes' => [['sync' => ['initial_window' => 'PT30M']]];
        yield 'initial over max' => [['sync' => ['initial_window' => 'P100D']]];
        yield 'resync over max' => [['sync' => ['resync_window' => 'P30D', 'max_initial_window' => 'P14D', 'initial_window' => 'P7D']]];
        yield 'renew_before too long' => [['graph' => ['renew_before' => 'P6D']]];
        yield 'renew_before not a duration' => [['graph' => ['renew_before' => 'tomorrow']]];
        yield 'remote images' => [['html' => ['remote_images' => 'proxy']]];
        yield 'guard mode' => [['graph' => ['outbound_url_guard' => 'maybe']]];
        yield 'http notification url' => [['graph' => ['webhook' => ['notification_url' => 'http://app.example.com/x']]]];
        yield 'relative path' => [['graph' => ['webhook' => ['path' => 'hooks']]]];
        yield 'bad ip' => [['graph' => ['webhook' => ['allowed_ips' => ['not-an-ip']]]]];
        yield 'bad interval' => [['graph' => ['webhook' => ['rate_limit' => ['interval' => 'soon']]]]];
        yield 'too many folders' => [['max_folders' => 101]];
        yield 'send retention' => [['send' => ['retention' => 'P1W']]];
        yield 'send recipients' => [['send' => ['max_recipients' => 501]]];
        yield 'send stale claim' => [['send' => ['stale_claim_seconds' => 5]]];
        yield 'send stale claim within a call' => [['send' => ['stale_claim_seconds' => 240]]];
        yield 'upload host' => [['graph' => ['upload_hosts' => ['Outlook.Office.com']]]];
        yield 'no upload host' => [['graph' => ['upload_hosts' => []]]];
    }

    /**
     * @param array<string, mixed> $mailboxes
     */
    #[DataProvider('invalid')]
    public function testInvalidConfigurationsFailTheBuild(array $mailboxes): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process($mailboxes);
    }

    public function testTheGuardPassPinsTheGraphHost(): void
    {
        // Nothing to do without the delegated Graph client.
        $container = new ContainerBuilder();
        (new MailboxUrlGuardPass())->process($container);
        self::assertFalse($container->hasDefinition(GuardedGraphRequestOptions::class));

        foreach (['auto' => true, 'required' => true, 'off' => false] as $mode => $pinned) {
            $container = $this->container($mode, true);
            (new MailboxUrlGuardPass())->process($container);
            self::assertSame($pinned, $container->hasDefinition(GuardedGraphRequestOptions::class), $mode);
            $argument = $container->getDefinition(NowoMailKitExtension::MAILBOX_GRAPH_CLIENT_ID)->getArgument(5);
            self::assertSame($pinned, $argument instanceof Reference, $mode);
        }

        $container = $this->container('auto', false);
        (new MailboxUrlGuardPass())->process($container);
        self::assertStringContainsString('graph.microsoft.com is not pinned', implode("\n", $container->getCompiler()->getLog()));
        self::assertFalse($container->hasDefinition(GuardedGraphRequestOptions::class));

        $this->expectException(LogicException::class);
        (new MailboxUrlGuardPass())->process($this->container('required', false));
    }

    private function container(string $mode, bool $guard): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoMailKitExtension::MAILBOX_URL_GUARD_PARAMETER, $mode);
        $container->setDefinition(NowoMailKitExtension::MAILBOX_GRAPH_CLIENT_ID, new Definition(GraphApiClient::class, [null, null, [], 30.0, null, null]));
        if ($guard) {
            $container->setDefinition(OutboundUrlGuard::class, new Definition(OutboundUrlGuard::class));
        }

        return $container;
    }

    /**
     * @param array<string, mixed> $mailboxes
     *
     * @return array<string, mixed>
     */
    private function process(array $mailboxes): array
    {
        /** @var array{mailboxes: array<string, mixed>} $config */
        $config = (new Processor())->processConfiguration(new Configuration(), [['mailboxes' => $mailboxes]]);

        return $config['mailboxes'];
    }
}
