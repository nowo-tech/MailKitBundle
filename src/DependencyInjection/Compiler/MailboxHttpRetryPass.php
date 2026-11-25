<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\MailKitBundle\DependencyInjection\NowoMailKitExtension;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpClient\RetryableHttpClient;

use function is_a;
use function is_string;

/**
 * Build-time guard (audit ZBU-L1): the HTTP client of the delegated mailboxes must never retry by itself. A
 * `RetryableHttpClient` (FrameworkBundle `retry_failed`, on the default client or a scoped one) re-POSTs a send that the
 * provider accepted but answered 502 / 503, outside MailKit's claim and submit window: the message could leave twice.
 * `mailboxes.http_client` pointing at a retrying client fails the build; the 1.8 shared-mailbox `graph.http_client`
 * gets a warning in the compiler log (released behaviour, unchanged). Runs before the decorators are applied, when the
 * `<client>.retryable` decorator definitions still name the client they decorate.
 *
 * @internal
 */
final class MailboxHttpRetryPass implements CompilerPassInterface
{
    /**
     * Refuses a retrying mailbox client; warns about a retrying shared-mailbox Graph client.
     *
     * @throws LogicException
     */
    public function process(ContainerBuilder $container): void
    {
        $mailbox = $container->hasParameter(NowoMailKitExtension::MAILBOX_HTTP_CLIENT_PARAMETER) ? $container->getParameter(NowoMailKitExtension::MAILBOX_HTTP_CLIENT_PARAMETER) : null;
        if (is_string($mailbox) && $this->retries($container, $mailbox)) {
            throw new LogicException('NowoMailKitBundle: nowo_mail_kit.mailboxes.http_client "' . $mailbox . '" retries failed requests (framework.http_client retry_failed): a retried send could deliver a message twice. Point mailboxes.http_client at a scoped HTTP client without retry_failed.');
        }
        $graph = $container->hasParameter(NowoMailKitExtension::GRAPH_HTTP_CLIENT_PARAMETER) ? $container->getParameter(NowoMailKitExtension::GRAPH_HTTP_CLIENT_PARAMETER) : null;
        if (is_string($graph) && $this->retries($container, $graph)) {
            $container->log($this, 'nowo_mail_kit.graph.http_client "' . $graph . '" retries failed requests (framework.http_client retry_failed): a retried Graph send could deliver a message twice. Use a scoped HTTP client without retry_failed.');
        }
    }

    /**
     * True when the service (after aliases) is a `RetryableHttpClient` or is decorated by one.
     */
    private function retries(ContainerBuilder $container, string $id): bool
    {
        while ($container->hasAlias($id)) {
            $id = (string) $container->getAlias($id);
        }
        if ($container->hasDefinition($id) && $this->isRetryable($container, $container->getDefinition($id))) {
            return true;
        }
        foreach ($container->getDefinitions() as $definition) {
            if (($definition->getDecoratedService()[0] ?? null) === $id && $this->isRetryable($container, $definition)) {
                return true;
            }
        }

        return false;
    }

    private function isRetryable(ContainerBuilder $container, Definition $definition): bool
    {
        $class = $container->getParameterBag()->resolveValue((string) $definition->getClass());

        return is_string($class) && is_a($class, RetryableHttpClient::class, true);
    }
}
