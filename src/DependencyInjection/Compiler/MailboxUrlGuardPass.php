<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\MailKitBundle\DependencyInjection\NowoMailKitExtension;
use Nowo\MailKitBundle\Graph\GuardedGraphRequestOptions;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;

/**
 * Pins `graph.microsoft.com` of the delegated mailboxes' Graph client to the address OutboundUrlGuard resolved
 * (2.5.0, `mailboxes.graph.outbound_url_guard`, the CrmKit 0.10 pattern): `auto` when the OutboundUrlGuard bundle is
 * enabled, `required` fails the build without it, `off` never.
 *
 * @internal
 */
final class MailboxUrlGuardPass implements CompilerPassInterface
{
    /**
     * Wires the pin.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(NowoMailKitExtension::MAILBOX_URL_GUARD_PARAMETER) || !$container->hasDefinition(NowoMailKitExtension::MAILBOX_GRAPH_CLIENT_ID)) {
            return;
        }
        $mode = $container->getParameter(NowoMailKitExtension::MAILBOX_URL_GUARD_PARAMETER);
        $has  = class_exists(OutboundUrlGuard::class) && $container->has(OutboundUrlGuard::class);
        if ($mode === 'required' && !$has) {
            throw new LogicException('nowo_mail_kit.mailboxes.graph.outbound_url_guard is "required" but the nowo-tech/outbound-url-guard-bundle bundle is not enabled.');
        }
        if ($mode === 'auto' && !$has) {
            // Audit ZAT-I2: no silent downgrade. TLS still protects the token; use "required" in production.
            $container->log($this, 'nowo_mail_kit.mailboxes.graph.outbound_url_guard is "auto" but the OutboundUrlGuard bundle is not enabled: graph.microsoft.com is not pinned. Enable the bundle and set "required" in production.');
        }
        if ($mode !== 'off' && $has) {
            $container->setDefinition(GuardedGraphRequestOptions::class, (new Definition(GuardedGraphRequestOptions::class))->setArguments([new Reference(OutboundUrlGuard::class)]));
            $container->getDefinition(NowoMailKitExtension::MAILBOX_GRAPH_CLIENT_ID)->replaceArgument(5, new Reference(GuardedGraphRequestOptions::class));
        }
    }
}
