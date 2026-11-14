<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Sender;

use InvalidArgumentException;
use Nowo\MailKitBundle\Sender\MailAddress;
use Nowo\MailKitBundle\Sender\MimeAddressMapper;
use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;
use Nowo\MailKitBundle\Sender\SenderPolicy;
use Nowo\MailKitBundle\Sender\SenderProviderGate;
use Nowo\MailKitBundle\Sender\SenderProviderInterface;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Tests\Support\DualSenderProvider;
use Nowo\MailKitBundle\Tests\Support\FakeSettings;
use Nowo\MailKitBundle\Tests\Support\StaticSenderProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * 1.12: the framework-free sender port, its value object and the gate that serves the deprecated port from it.
 */
final class SenderIdentityTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: string|null, 3: string}>
     */
    public static function addresses(): iterable
    {
        yield 'plain' => ['no-reply@example.com', 'no-reply@example.com', null, 'no-reply@example.com'];
        yield 'padded' => ['  no-reply@example.com  ', 'no-reply@example.com', null, 'no-reply@example.com'];
        yield 'named' => ['Acme <no-reply@example.com>', 'no-reply@example.com', 'Acme', '"Acme" <no-reply@example.com>'];
        yield 'quoted name' => ['"Acme Inc" <no-reply@example.com>', 'no-reply@example.com', 'Acme Inc', '"Acme Inc" <no-reply@example.com>'];
        yield 'blank name' => ['  <no-reply@example.com>', 'no-reply@example.com', null, 'no-reply@example.com'];
        yield 'escaped name' => ['Ac"m\\e <no-reply@example.com>', 'no-reply@example.com', 'Ac"m\\e', '"Ac\\"m\\\\e" <no-reply@example.com>'];
    }

    #[DataProvider('addresses')]
    public function testTheValueObjectParsesAndPrints(string $input, string $email, ?string $name, string $printed): void
    {
        $address = MailAddress::fromString($input);

        self::assertSame($email, $address->email);
        self::assertSame($name, $address->name);
        self::assertSame($printed, $address->toString());
        self::assertSame($printed, (string) $address);
    }

    public function testTheValueObjectNeedsAnAddress(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MailAddress('   ');
    }

    public function testTheValueObjectNeedsAnAddressInsideTheBrackets(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MailAddress::fromString('Acme <>');
    }

    public function testTheMapperTranslatesBothWaysAndDropsWhatTheMailerRefuses(): void
    {
        self::assertNull(MimeAddressMapper::toValueObject(null));
        self::assertNull(MimeAddressMapper::toMimeAddress(null));

        $value = MimeAddressMapper::toValueObject(new Address('no-reply@example.com', 'Acme'));
        self::assertInstanceOf(MailAddress::class, $value);
        self::assertSame(['no-reply@example.com', 'Acme'], [$value->email, $value->name]);
        self::assertNull(MimeAddressMapper::toValueObject(new Address('no-reply@example.com'))?->name);

        $mime = MimeAddressMapper::toMimeAddress($value);
        self::assertInstanceOf(Address::class, $mime);
        self::assertSame('"Acme" <no-reply@example.com>', $mime->toString());
        self::assertSame('no-reply@example.com', MimeAddressMapper::toMimeAddress(new MailAddress('no-reply@example.com'))?->toString());
        self::assertNull(MimeAddressMapper::toMimeAddress(new MailAddress('not-an-address')));
    }

    public function testTheGateServesTheDeprecatedPortFromTheNewOne(): void
    {
        $gate = new SenderProviderGate(new class implements SenderIdentityProviderInterface {
            public function defaultFrom(): MailAddress
            {
                return new MailAddress('tenant@example.com', 'Tenant');
            }

            public function defaultReplyTo(): ?MailAddress
            {
                return null;
            }
        });

        $email = new Email();
        (new SenderPolicy($gate))->apply($email);

        self::assertSame('"Tenant" <tenant@example.com>', $email->getFrom()[0]->toString());
        self::assertSame([], $email->getReplyTo());
    }

    public function testTheGateAsksADeprecatedPortImplementationDirectly(): void
    {
        $gate = new SenderProviderGate(new class implements SenderProviderInterface {
            public function from(): Address
            {
                return new Address('legacy@example.com');
            }

            public function replyTo(): Address
            {
                return new Address('legacy-help@example.com');
            }
        });

        self::assertSame('legacy@example.com', $gate->from()?->getAddress());
        self::assertSame('legacy-help@example.com', $gate->replyTo()?->getAddress());
    }

    public function testTheNewPortWinsWhenAClassImplementsBoth(): void
    {
        $gate = new SenderProviderGate(new DualSenderProvider());

        self::assertSame('"Identity" <identity@example.com>', $gate->from()?->toString());
        self::assertSame('identity-help@example.com', $gate->replyTo()?->getAddress());

        $from = $gate->defaultFrom();
        self::assertInstanceOf(MailAddress::class, $from);
        self::assertSame(['identity@example.com', 'Identity'], [$from->email, $from->name]);
        self::assertSame('identity-help@example.com', $gate->defaultReplyTo()?->email);
    }

    public function testTheGateServesTheNewPortFromADeprecatedPortImplementation(): void
    {
        $gate = new SenderProviderGate(new StaticSenderProvider());

        $from = $gate->defaultFrom();
        self::assertInstanceOf(MailAddress::class, $from);
        self::assertSame(['tenant@example.com', 'Tenant'], [$from->email, $from->name]);
        $replyTo = $gate->defaultReplyTo();
        self::assertInstanceOf(MailAddress::class, $replyTo);
        self::assertSame(['tenant-help@example.com', null], [$replyTo->email, $replyTo->name]);
    }

    public function testTheGateServesTheNewPortFromANewPortImplementationUnchanged(): void
    {
        $address = new MailAddress('tenant@example.com', 'Tenant');
        $gate    = new SenderProviderGate(new class($address) implements SenderIdentityProviderInterface {
            public function __construct(private readonly MailAddress $address)
            {
            }

            public function defaultFrom(): MailAddress
            {
                return $this->address;
            }

            public function defaultReplyTo(): ?MailAddress
            {
                return null;
            }
        });

        self::assertSame($address, $gate->defaultFrom());
        self::assertNull($gate->defaultReplyTo());
    }

    public function testTheSettingsServeBothPorts(): void
    {
        $settings = new MailSettings(new FakeSettings(['mailer.from' => 'noreply@example.com', 'mailer.from_name' => 'Acme', 'mailer.reply_to' => 'help@example.com']));

        $from = $settings->defaultFrom();
        self::assertInstanceOf(MailAddress::class, $from);
        self::assertSame(['noreply@example.com', 'Acme'], [$from->email, $from->name]);

        $replyTo = $settings->defaultReplyTo();
        self::assertInstanceOf(MailAddress::class, $replyTo);
        self::assertSame('help@example.com', $replyTo->email);
        self::assertNull($replyTo->name);

        $empty = new MailSettings(new FakeSettings());
        self::assertNull($empty->defaultFrom());
        self::assertNull($empty->defaultReplyTo());
    }
}
