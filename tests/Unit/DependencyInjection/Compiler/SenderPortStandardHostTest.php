<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\MailKitBundle\DependencyInjection\Compiler\SenderPortPass;
use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;
use Nowo\MailKitBundle\Sender\SenderPolicy;
use Nowo\MailKitBundle\Sender\SenderProviderInterface;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\TestMail\TestMailSender;
use Nowo\MailKitBundle\Tests\Support\DualSenderProvider;
use Nowo\MailKitBundle\Tests\Support\StaticSenderIdentityProvider;
use Nowo\MailKitBundle\Tests\Support\StaticSenderProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_map;
use function file_get_contents;
use function is_array;
use function json_decode;
use function ksort;

use const JSON_THROW_ON_ERROR;

/**
 * 1.12.2: a host that decorates neither sender port gets the same compiled wiring as in 1.12.1.
 *
 * The fixture `sender-port-standard-hosts.json` was recorded with the 1.12.1 pass. Each case compiles a container with
 * the pass, the bundle's two sender consumers (wired as the extension wires them) and a host provider of each kind,
 * and compares every compiled definition (class, factory, arguments, inlined gates) and alias with that record.
 */
final class SenderPortStandardHostTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function hosts(): iterable
    {
        foreach (['settings', 'deprecated', 'identity', 'dual', 'unknowable'] as $host) {
            yield $host => [$host];
        }
    }

    #[DataProvider('hosts')]
    public function testTheCompiledWiringOfAStandardHostIsThatOf1121(string $host): void
    {
        $recorded = json_decode((string) file_get_contents(__DIR__ . '/../../../Fixtures/sender-port-standard-hosts.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($recorded);

        self::assertSame($recorded[$host], self::snapshot($host));
    }

    /**
     * The compiled definitions and aliases of a standard host, normalised to plain arrays.
     *
     * @return array{definitions: array<string, mixed>, aliases: array<string, mixed>}
     */
    public static function snapshot(string $host): array
    {
        $container = new ContainerBuilder();
        $sender    = match ($host) {
            'settings'   => MailSettings::class,
            'deprecated' => StaticSenderProvider::class,
            'identity'   => StaticSenderIdentityProvider::class,
            'dual'       => DualSenderProvider::class,
            default      => 'app.sender',
        };
        if ($host === 'unknowable') {
            $container->setDefinition($sender, (new Definition(stdClass::class))->setFactory([StaticSenderProvider::class, 'create']));
        } else {
            $container->setDefinition($sender, new Definition($sender));
        }
        $container->setAlias(SenderProviderInterface::class, $sender);
        $container->setDefinition(SenderPolicy::class, (new Definition(SenderPolicy::class, [new Reference(SenderProviderInterface::class)]))->setPublic(true));
        $container->setDefinition(TestMailSender::class, (new Definition(TestMailSender::class, [
            null,
            null,
            null,
            true,
            null,
            $host === 'settings' ? null : new Reference(SenderProviderInterface::class),
        ]))->setPublic(true));
        $container->setAlias('test.old', SenderProviderInterface::class)->setPublic(true);
        $container->setAlias('test.new', SenderIdentityProviderInterface::class)->setPublic(true);
        $container->addCompilerPass(new SenderPortPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
        $container->compile(true);

        $definitions = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            if ($id !== 'service_container') {
                $definitions[$id] = self::normalize($definition);
            }
        }
        $aliases = [];
        foreach ($container->getAliases() as $id => $alias) {
            if ((string) $alias !== 'service_container') {
                $aliases[$id] = self::normalize($alias);
            }
        }
        ksort($definitions);
        ksort($aliases);

        return ['definitions' => $definitions, 'aliases' => $aliases];
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Reference  => '@' . $value . '#' . $value->getInvalidBehavior(),
            $value instanceof Alias      => ['target' => (string) $value, 'public' => $value->isPublic()],
            $value instanceof Definition => [
                'class'     => $value->getClass(),
                'factory'   => self::normalize($value->getFactory()),
                'arguments' => self::normalize($value->getArguments()),
                'public'    => $value->isPublic(),
            ],
            is_array($value) => array_map(self::normalize(...), $value),
            default          => $value,
        };
    }
}
