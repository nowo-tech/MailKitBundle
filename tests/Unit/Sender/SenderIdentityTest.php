<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Sender;

use InvalidArgumentException;
use Nowo\MailKitBundle\Sender\MailAddress;
use Nowo\MailKitBundle\Sender\MimeAddressMapper;
use Nowo\MailKitBundle\Sender\SenderIdentityProviderInterface;
use Nowo\MailKitBundle\Sender\SenderPolicy;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Tests\Support\FakeSettings;
use Nowo\MailKitBundle\Tests\Support\RecordingLogger;
use Nowo\MailKitBundle\Tests\Support\StaticSenderIdentityProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

use function array_keys;
use function json_encode;
use function var_export;

/**
 * The framework-free sender port (1.12; the only one since 2.0), its value object and the sender policy that reads it.
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

    public function testThePolicyFillsTheSenderFromThePort(): void
    {
        $email = new Email();
        (new SenderPolicy(new StaticSenderIdentityProvider()))->apply($email);

        self::assertSame('"Tenant" <tenant@example.com>', $email->getFrom()[0]->toString());
        self::assertSame('tenant-help@example.com', $email->getReplyTo()[0]->getAddress());
    }

    public function testTheSettingsServeThePort(): void
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

    /**
     * PI-L1: an address the mailer refuses is left out (tolerant, like the settings sender), with a warning that names
     * the provider class and the field, never the address.
     */
    public function testARefusedAddressIsLeftOutWithAWarningThatHoldsNoAddress(): void
    {
        $logger = new RecordingLogger();
        $policy = new SenderPolicy(new class implements SenderIdentityProviderInterface {
            public function defaultFrom(): MailAddress
            {
                return new MailAddress('tenant@@secret-tenant.example', 'Secret Name');
            }

            public function defaultReplyTo(): MailAddress
            {
                return new MailAddress("help@secret-tenant.example\r\nBcc: x@evil.test");
            }
        }, $logger);

        $email = (new Email())->to('a@example.com')->text('Hi');
        $policy->apply($email);

        self::assertFalse($email->getHeaders()->has('From'));
        self::assertFalse($email->getHeaders()->has('Reply-To'));
        self::assertCount(2, $logger->records);
        self::assertSame(['from', 'replyTo'], [$logger->records[0]['context']['field'], $logger->records[1]['context']['field']]);
        foreach ($logger->records as $record) {
            self::assertSame('warning', $record['level']);
            self::assertSame(SenderPolicy::REFUSED_ADDRESS_MESSAGE, $record['message']);
            self::assertSame(['provider', 'field'], array_keys($record['context']));
            self::assertStringContainsString('@anonymous', (string) $record['context']['provider']);
            $dump = $record['message'] . json_encode($record['context']) . var_export($record['context'], true);
            self::assertStringNotContainsString('secret-tenant', $dump);
            self::assertStringNotContainsString('Secret Name', $dump);
        }
    }

    public function testAnAcceptedOrMissingAddressLogsNothing(): void
    {
        $logger = new RecordingLogger();
        $email  = new Email();
        (new SenderPolicy(new StaticSenderIdentityProvider(), $logger))->apply($email);
        self::assertSame('tenant@example.com', $email->getFrom()[0]->getAddress());

        $none = new class implements SenderIdentityProviderInterface {
            public function defaultFrom(): ?MailAddress
            {
                return null;
            }

            public function defaultReplyTo(): ?MailAddress
            {
                return null;
            }
        };
        $empty = new Email();
        (new SenderPolicy($none, $logger))->apply($empty);
        self::assertSame([], $empty->getFrom());
        self::assertNull(SenderPolicy::toMime($none, null, 'from', $logger));

        self::assertSame([], $logger->records);
    }

    public function testARefusedAddressWithoutALoggerIsStillLeftOut(): void
    {
        $provider = new class implements SenderIdentityProviderInterface {
            public function defaultFrom(): MailAddress
            {
                return new MailAddress('tenant@@bad');
            }

            public function defaultReplyTo(): ?MailAddress
            {
                return null;
            }
        };

        self::assertNull(SenderPolicy::toMime($provider, $provider->defaultFrom(), 'from', null));
        $email = new Email();
        (new SenderPolicy($provider))->apply($email);
        self::assertSame([], $email->getFrom());
    }
}
