<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\MailKitBundle\Mailer\MailKitMailer;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Checks the two hard dependencies before the decorators are resolved — the framework mailer (`mailer.transports`,
 * `mailer.transport_factory`) and the Instance Settings store — and tells {@see MailKitMailer} whether the framework
 * mailer has a message bus (then it records "queued" rows).
 */
final class MailerIntegrationPass implements CompilerPassInterface
{
    /** Service id of the MailerInterface decorator. */
    public const MAILER_ID = 'nowo_mail_kit.mailer';

    /**
     * Fails fast on a missing dependency; sets the `$queues` argument of the decorator.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(InstanceSettingsInterface::class)) {
            throw new LogicException('NowoMailKitBundle requires nowo-tech/instance-settings-bundle: register Nowo\\InstanceSettingsBundle\\NowoInstanceSettingsBundle in config/bundles.php (the mailer settings are an Instance Settings section).');
        }
        if (!$container->hasDefinition('mailer.transports') || !$container->has('mailer.transport_factory')) {
            throw new LogicException('NowoMailKitBundle requires the framework mailer: install symfony/mailer and configure framework.mailer.dsn (MAILER_DSN, for example null://null until a runtime DSN is stored).');
        }

        if (!$container->hasDefinition(self::MAILER_ID) || !$container->hasDefinition('mailer.mailer')) {
            return;
        }
        $arguments = $container->getDefinition('mailer.mailer')->getArguments();
        $bus       = $arguments[1] ?? null;
        $queues    = $bus instanceof Reference && $container->has((string) $bus);
        $container->getDefinition(self::MAILER_ID)->replaceArgument('$queues', $queues);
    }
}
