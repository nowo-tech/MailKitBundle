<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Log;

use InvalidArgumentException;
use Nowo\MailKitBundle\Event\SentMailPersonalDataErasedEvent;
use Nowo\MailKitBundle\Log\DefaultSentMailPersonalDataSubjectResolver;
use Nowo\MailKitBundle\Log\NullSentMailErasure;
use Nowo\MailKitBundle\Log\NullSentMailPersonalDataExporter;
use Nowo\MailKitBundle\Log\SentMailErasureResult;
use Nowo\MailKitBundle\Log\SentMailPersonalDataSubject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function str_repeat;

/**
 * GDPR ports (1.7): the subject value object, the default subject resolver, the null exporter and the erased event.
 */
final class PersonalDataSubjectTest extends TestCase
{
    public function testTheSubjectUsesTheLogNormalisationOnly(): void
    {
        $subject = SentMailPersonalDataSubject::fromEmail(' José@Ejemplo.ES ');
        self::assertSame('josé@ejemplo.es', $subject->email);
        self::assertSame(['josé@ejemplo.es'], $subject->forms());
        // Case is folded on write by the log, accents are not: jose@ and josé@ are two people.
        self::assertNotSame($subject->email, SentMailPersonalDataSubject::fromEmail('jose@ejemplo.es')->email);
        self::assertSame(['user@bücher.de', 'user@xn--bcher-kva.de'], SentMailPersonalDataSubject::fromEmail('User@XN--BCHER-KVA.DE')->forms());
        self::assertSame(255, mb_strlen(SentMailPersonalDataSubject::fromEmail(str_repeat('a', 243) . '@example.com')->email));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'no at' => ['username'];
        yield 'nul byte' => ["a\0b@example.com"];
        yield 'index separator' => ["a\x1Fb@example.com"];
        yield 'delete character' => ["a\x7Fb@example.com"];
        yield 'invalid utf-8' => ["a\xFF@example.com"];
        yield 'too long' => [str_repeat('a', 244) . '@example.com'];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRefused(string $value): void
    {
        self::assertNull(SentMailPersonalDataSubject::tryFromEmail($value));
        $this->expectException(InvalidArgumentException::class);
        SentMailPersonalDataSubject::fromEmail($value);
    }

    public function testTryFromEmail(): void
    {
        self::assertNull(SentMailPersonalDataSubject::tryFromEmail(null));
        self::assertSame('a@example.com', SentMailPersonalDataSubject::tryFromEmail('A@example.com')?->email);
    }

    public function testTheDefaultResolverUsesTheIdentifierAndGetEmail(): void
    {
        $resolver = new DefaultSentMailPersonalDataSubjectResolver();
        $user     = new class {
            public ?string $email = 'Current@Example.com';

            public function getEmail(): ?string
            {
                return $this->email;
            }
        };

        $emails = static fn (array $subjects): array => array_map(static fn (SentMailPersonalDataSubject $s): string => $s->email, $subjects);
        self::assertSame(['login@example.com', 'current@example.com'], $emails($resolver->subjectsFor($user, 'Login@Example.com')));
        // Same address once; a username is not an address.
        self::assertSame(['current@example.com'], $emails($resolver->subjectsFor($user, 'current@example.com')));
        self::assertSame(['current@example.com'], $emails($resolver->subjectsFor($user, 'jdoe')));
        $user->email = null;
        self::assertSame([], $resolver->subjectsFor($user, null));
        self::assertSame(['a@example.com'], $emails($resolver->subjectsFor(new stdClass(), 'a@example.com')));
    }

    public function testNullExporterAndEvent(): void
    {
        self::assertSame([], (new NullSentMailPersonalDataExporter())->export(SentMailPersonalDataSubject::fromEmail('a@example.com')));
        self::assertSame(['deleted' => 2, 'unlinked' => 0, 'redacted' => 1], (new SentMailPersonalDataErasedEvent('a@example.com', 2, 1))->toArray());
        $event = new SentMailPersonalDataErasedEvent('a@example.com', 2, 1, 3);
        self::assertSame(['deleted' => 2, 'unlinked' => 3, 'redacted' => 1], $event->toArray());
        self::assertSame('a@example.com', $event->email);
    }

    public function testErasureResultAndNullEraser(): void
    {
        $result = new SentMailErasureResult(3, 2, 4);
        self::assertSame(['deleted' => 3, 'unlinked' => 4, 'redacted' => 2], $result->toArray());
        self::assertSame(9, $result->total());
        $none = (new NullSentMailErasure())->erase(SentMailPersonalDataSubject::fromEmail('a@example.com'));
        self::assertSame(['deleted' => 0, 'unlinked' => 0, 'redacted' => 0], $none->toArray());
        self::assertSame(0, $none->total());
    }
}
