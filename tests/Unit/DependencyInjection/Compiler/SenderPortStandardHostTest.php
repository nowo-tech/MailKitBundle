<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\MailKitBundle\DependencyInjection\Compiler\SenderPortPass;
use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;
use Nowo\MailKitBundle\Sender\SenderPolicy;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\TestMail\TestMailSender;
use Nowo\MailKitBundle\Tests\Support\StaticSenderIdentityProvider;
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
 * 2.0: the pass only checks the sender service; it never rewires it. A host that decorates nothing gets the compiled
 * wiring the extension asked for: the bundle's two sender consumers on the configured service.
 *
 * The fixture `sender-port-standard-hosts.json` was recorded with the 2.0 pass (the 1.12.1 records of the rows of the
 * removed `SenderProviderInterface` and of its gate were dropped). Each case compiles a container with the pass, the
 * two consumers (wired as the extension wires them) and a host provider of each kind, and compares every compiled
 * definition (class, factory, arguments) and alias with that record.
 */
final class SenderPortStandardHostTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function hosts(): iterable
    {
        foreach (['settings', 'identity', 'unknowable'] as $host) {
            yield $host => [$host];
        }
    }

    #[DataProvider('hosts')]
    public function testTheCompiledWiringOfAStandardHostIsUnchangedByThePass(string $host): void
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
            'settings' => MailSettings::class,
            'identity' => StaticSenderIdentityProvider::class,
            default    => 'app.sender',
        };
        if ($host === 'unknowable') {
            $container->setDefinition($sender, (new Definition(stdClass::class))->setFactory([StaticSenderIdentityProvider::class, 'create']));
        } else {
            $container->setDefinition($sender, new Definition($sender));
        }
        $container->setAlias(SenderIdentityProviderInterface::class, $sender);
        $container->setDefinition(SenderPolicy::class, (new Definition(SenderPolicy::class, [new Reference(SenderIdentityProviderInterface::class), null]))->setPublic(true));
        $container->setDefinition(TestMailSender::class, (new Definition(TestMailSender::class, [
            null,
            null,
            null,
            true,
            null,
            $host === 'settings' ? null : new Reference(SenderIdentityProviderInterface::class),
        ]))->setPublic(true));
        $container->setAlias('test.sender', SenderIdentityProviderInterface::class)->setPublic(true);
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
