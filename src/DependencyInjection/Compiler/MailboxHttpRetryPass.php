<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\MailKitBundle\DependencyInjection\NowoMailKitExtension;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function in_array;
use function is_a;
use function is_array;
use function is_string;
use function trigger_error;

use const E_USER_DEPRECATED;

/**
 * Build-time guard (audits ZBU-L1, ZBUv-L1): the HTTP client of the delegated mailboxes must never retry by itself. A
 * `RetryableHttpClient` (FrameworkBundle `retry_failed`, on the default client or a scoped one) re-POSTs a send that the
 * provider accepted but answered 502 / 503, outside MailKit's claim and submit window: the message could leave twice.
 *
 * The configured service is followed through its alias chain (decorators of every id on it count), and through the
 * HTTP client services its definition, its decorators and its factory reference by argument (a host wrapper such as
 * `NoPrivateNetworkHttpClient(@http_client)`), up to {@see MAX_DEPTH} levels. A static factory that returns a
 * retrying client, or a retry decorator that is not a `RetryableHttpClient`, cannot be seen: the documentation rule
 * ("the client must not retry by itself") covers them.
 *
 * `mailboxes.http_client` that retries fails the build. The 1.8 shared-mailbox `graph.http_client` gets a deprecation
 * (and a compiler-log line) in 2.x; 3.0 fails the build for it too. Runs before the decorators are applied, while the
 * `<client>.retryable` definitions still name the client they decorate.
 *
 * @internal
 */
final class MailboxHttpRetryPass implements CompilerPassInterface
{
    /** Levels of argument / factory references followed from the configured client. */
    public const MAX_DEPTH = 4;

    /**
     * Refuses a retrying mailbox client; deprecates a retrying shared-mailbox Graph client.
     *
     * @throws LogicException
     */
    public function process(ContainerBuilder $container): void
    {
        $mailbox = $container->hasParameter(NowoMailKitExtension::MAILBOX_HTTP_CLIENT_PARAMETER) ? $container->getParameter(NowoMailKitExtension::MAILBOX_HTTP_CLIENT_PARAMETER) : null;
        if (is_string($mailbox) && $this->retries($container, $mailbox, 0, [])) {
            throw new LogicException('NowoMailKitBundle: nowo_mail_kit.mailboxes.http_client "' . $mailbox . '" retries failed requests (framework.http_client retry_failed): a retried send could deliver a message twice. Point mailboxes.http_client at a scoped HTTP client with retry_failed: false.');
        }
        $graph = $container->hasParameter(NowoMailKitExtension::GRAPH_HTTP_CLIENT_PARAMETER) ? $container->getParameter(NowoMailKitExtension::GRAPH_HTTP_CLIENT_PARAMETER) : null;
        if (is_string($graph) && $this->retries($container, $graph, 0, [])) {
            $message = 'nowo_mail_kit.graph.http_client "' . $graph . '" retries failed requests (framework.http_client retry_failed): a retried Graph send could deliver a message twice. Use a scoped HTTP client with retry_failed: false; MailKit 3.0 fails the build for a retrying client.';
            $container->log($this, $message);
            // The symfony/deprecation-contracts format, without the extra dependency (profiler and deprecation log).
            @trigger_error('Since nowo-tech/mail-kit-bundle 2.5: ' . $message, E_USER_DEPRECATED);
        }
    }

    /**
     * True when the service, an id on its alias chain, a decorator of one of them, or an HTTP client service they
     * reference (arguments, factory) is a `RetryableHttpClient`.
     *
     * @param list<string> $seen ids already followed
     */
    private function retries(ContainerBuilder $container, string $id, int $depth, array $seen): bool
    {
        $chain = [$id];
        while ($container->hasAlias($id) && !in_array($id = (string) $container->getAlias($id), $chain, true)) {
            $chain[] = $id;
        }
        if (in_array($id, $seen, true)) {
            return false;
        }
        $seen    = [...$seen, ...$chain];
        $related = [];
        foreach ($container->getDefinitions() as $definition) {
            if (in_array($definition->getDecoratedService()[0] ?? null, $chain, true)) {
                if ($this->isRetryable($container, $definition)) {
                    return true;
                }
                $related[] = $definition;
            }
        }
        if ($container->hasDefinition($id)) {
            $definition = $container->getDefinition($id);
            if ($this->isRetryable($container, $definition)) {
                return true;
            }
            $related[] = $definition;
        }
        if ($depth >= self::MAX_DEPTH) {
            return false;
        }
        foreach ($related as $definition) {
            foreach ($this->references([$definition->getArguments(), $definition->getFactory()]) as $reference) {
                if ($this->isHttpClient($container, $reference) && $this->retries($container, $reference, $depth + 1, $seen)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Service ids referenced anywhere in nested arguments.
     *
     * @return list<string>
     */
    private function references(mixed $value): array
    {
        if ($value instanceof Reference) {
            return [(string) $value];
        }
        if ($value instanceof Definition) {
            return $this->references([$value->getArguments(), $value->getFactory()]);
        }
        $ids = [];
        foreach (is_array($value) ? $value : [] as $item) {
            $ids = [...$ids, ...$this->references($item)];
        }

        return $ids;
    }

    /**
     * An alias, or a definition that is an HTTP client (by class or by FrameworkBundle's `http_client.client` tag).
     */
    private function isHttpClient(ContainerBuilder $container, string $id): bool
    {
        if ($container->hasAlias($id)) {
            return true;
        }
        if (!$container->hasDefinition($id)) {
            return false;
        }
        $definition = $container->getDefinition($id);
        $class      = $container->getParameterBag()->resolveValue((string) $definition->getClass());

        return $definition->hasTag('http_client.client') || (is_string($class) && is_a($class, HttpClientInterface::class, true));
    }

    private function isRetryable(ContainerBuilder $container, Definition $definition): bool
    {
        $class = $container->getParameterBag()->resolveValue((string) $definition->getClass());

        return is_string($class) && is_a($class, RetryableHttpClient::class, true);
    }
}
