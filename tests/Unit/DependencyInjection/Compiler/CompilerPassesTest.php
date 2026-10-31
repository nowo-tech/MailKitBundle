<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\MailKitBundle\Controller\MailKitController;
use Nowo\MailKitBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\MailKitBundle\DependencyInjection\Compiler\MailerIntegrationPass;
use Nowo\MailKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\MailKitBundle\DependencyInjection\Compiler\UnauthenticatedAccessPass;
use Nowo\MailKitBundle\DependencyInjection\NowoMailKitExtension;
use Nowo\MailKitBundle\NowoMailKitBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Filesystem\Filesystem;

use function array_map;
use function bin2hex;
use function dirname;
use function random_bytes;
use function sys_get_temp_dir;

final class CompilerPassesTest extends TestCase
{
    public function testCsrfPassFailsClosed(): void
    {
        $container = new ContainerBuilder();
        (new CsrfProtectionPass())->process($container);
        $container->setDefinition(MailKitController::class, new Definition(MailKitController::class));
        $container->setDefinition('security.csrf.token_manager', new Definition('stdClass'));
        $container->setDefinition('form.type_extension.csrf', new Definition('stdClass'));
        (new CsrfProtectionPass())->process($container);

        $container->setParameter('form.type_extension.csrf.enabled', false);
        $this->expectException(LogicException::class);
        (new CsrfProtectionPass())->process($container);
    }

    public function testUnauthenticatedPassNeedsTheController(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(UnauthenticatedAccessPass::PARAMETER, true);
        (new UnauthenticatedAccessPass())->process($container);
        $this->addToAssertionCount(1);
    }

    public function testBundleRegistersPassesAndExtension(): void
    {
        $bundle    = new NowoMailKitBundle();
        $container = new ContainerBuilder();
        $bundle->build($container);

        $classes = array_map(static fn (object $pass): string => $pass::class, $container->getCompilerPassConfig()->getPasses());
        foreach ([TwigPathsPass::class, CsrfProtectionPass::class, UnauthenticatedAccessPass::class, MailerIntegrationPass::class] as $pass) {
            self::assertContains($pass, $classes);
        }
        $extension = $bundle->getContainerExtension();
        self::assertInstanceOf(NowoMailKitExtension::class, $extension);
        self::assertSame($extension, $bundle->getContainerExtension());
    }

    public function testTwigPathsPass(): void
    {
        $views = dirname(__DIR__, 4) . '/src/Resources/views';

        $none = new ContainerBuilder();
        (new TwigPathsPass())->process($none);
        $this->addToAssertionCount(1);

        $project = sys_get_temp_dir() . '/nowo-mail-kit-twig-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($project . '/templates/bundles/NowoMailKitBundle');

        try {
            $container = new ContainerBuilder();
            $container->setParameter('kernel.project_dir', $project);
            $container->setDefinition('twig.loader.native_filesystem', new Definition('stdClass'));
            $container->setAlias('twig.loader.native', 'twig.loader.alias');
            $container->setAlias('twig.loader.alias', 'twig.loader.native_filesystem');
            (new TwigPathsPass())->process($container);

            self::assertSame([
                ['prependPath', [$project . '/templates/bundles/NowoMailKitBundle', 'NowoMailKitBundle']],
                ['addPath', [$views, 'NowoMailKitBundle']],
            ], $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls());

            $plain = new ContainerBuilder();
            $plain->setParameter('kernel.project_dir', $project . '/missing');
            $plain->setDefinition('twig.loader.native', new Definition('stdClass'));
            (new TwigPathsPass())->process($plain);
            self::assertSame([['addPath', [$views, 'NowoMailKitBundle']]], $plain->getDefinition('twig.loader.native')->getMethodCalls());
        } finally {
            (new Filesystem())->remove($project);
        }
    }
}
