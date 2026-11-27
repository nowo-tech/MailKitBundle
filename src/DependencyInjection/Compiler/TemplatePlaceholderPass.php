<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\DependencyInjection\Compiler;

use Nowo\MailKitBundle\Template\Engine\PlaceholderResolver;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderProviderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Reference;

use function is_a;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * The closed placeholder registry (2.7.0): every service tagged `nowo_mail_kit.template_placeholder_provider` must
 * implement {@see PlaceholderProviderInterface}; its static `placeholderNamespace()` is the registry key. The build
 * fails on a class that does not implement the interface, an invalid namespace or a namespace provided twice.
 *
 * @internal
 */
final class TemplatePlaceholderPass implements CompilerPassInterface
{
    /**
     * Builds the locator of {@see PlaceholderResolver}.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(PlaceholderResolver::class)) {
            return;
        }
        $providers = [];
        foreach ($container->findTaggedServiceIds(PlaceholderProviderInterface::TAG) as $id => $tags) {
            $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass() ?? $id);
            if (!is_string($class) || !is_a($class, PlaceholderProviderInterface::class, true)) {
                throw new LogicException(sprintf('NowoMailKitBundle: the placeholder provider "%s" must implement %s.', $id, PlaceholderProviderInterface::class));
            }
            $namespace = $class::placeholderNamespace();
            if (preg_match(PlaceholderProviderInterface::NAMESPACE_PATTERN, $namespace) !== 1) {
                throw new LogicException(sprintf('NowoMailKitBundle: the placeholder provider "%s" declares the namespace "%s"; a namespace must match [a-z][a-z0-9_]{0,31}.', $id, $namespace));
            }
            if (isset($providers[$namespace])) {
                throw new LogicException(sprintf('NowoMailKitBundle: the placeholder namespace "%s" is provided twice ("%s" and "%s").', $namespace, (string) $providers[$namespace], $id));
            }
            $providers[$namespace] = new Reference($id);
        }
        $container->getDefinition(PlaceholderResolver::class)->replaceArgument(0, ServiceLocatorTagPass::register($container, $providers));
    }
}
