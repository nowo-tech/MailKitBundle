<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\DependencyInjection\Compiler;

use Nowo\MailKitBundle\Controller\MailKitController;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * `security.allow_unauthenticated: true` is a dev / demo switch: with the admin UI enabled the container refuses to
 * compile unless `kernel.debug` is true, so a demo configuration copied to production cannot open the sent-mail log
 * (personal data) and the mail actions without login.
 */
final class UnauthenticatedAccessPass implements CompilerPassInterface
{
    /** Container parameter holding `security.allow_unauthenticated`. */
    public const PARAMETER = 'nowo_mail_kit.security.allow_unauthenticated';

    /**
     * Throws when the switch is on outside debug.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(MailKitController::class) || !$container->hasParameter(self::PARAMETER) || $container->getParameter(self::PARAMETER) !== true) {
            return;
        }

        if ($container->hasParameter('kernel.debug') && $container->getParameter('kernel.debug') === true) {
            return;
        }

        throw new InvalidConfigurationException('nowo_mail_kit.security.allow_unauthenticated: true is only allowed when kernel.debug is true (dev / demo): the admin UI would expose the sent-mail log and the mail actions without login. Set it to false and protect the UI with security.access_roles.');
    }
}
