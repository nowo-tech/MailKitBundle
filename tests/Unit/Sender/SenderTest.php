<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Sender;

use Nowo\MailKitBundle\Sender\SenderAddress;
use Nowo\MailKitBundle\Sender\SenderPolicy;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Tests\Support\FakeSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class SenderTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string|null, 1: bool}>
     */
    public static function senders(): iterable
    {
        yield 'public' => ['noreply@example.com', true];
        yield 'named' => ['Acme <noreply@example.com>', true];
        yield 'null' => [null, false];
        yield 'blank' => ['  ', false];
        yield 'garbage' => ['<<>>', false];
        yield 'single label' => ['root@localhost', false];
        yield 'dot local' => ['a@mail.local', false];
        yield 'dot localhost' => ['a@app.localhost', false];
        yield 'dot internal' => ['a@corp.internal', false];
        yield 'trailing dot' => ['a@example.com.', false];
    }

    #[DataProvider('senders')]
    public function testIsDeliverable(?string $from, bool $expected): void
    {
        self::assertSame($expected, SenderAddress::isDeliverable($from));
    }

    public function testPolicyFillsOnlyMissingHeaders(): void
    {
        $policy = new SenderPolicy(new MailSettings(new FakeSettings(['mailer.from' => 'noreply@example.com', 'mailer.from_name' => 'Acme', 'mailer.reply_to' => 'help@example.com'])));

        $email = new Email();
        $policy->apply($email);
        self::assertSame('"Acme" <noreply@example.com>', $email->getFrom()[0]->toString());
        self::assertSame('help@example.com', $email->getReplyTo()[0]->getAddress());

        $own = (new Email())->from('own@example.com')->replyTo('own-reply@example.com');
        $policy->apply($own);
        self::assertSame('own@example.com', $own->getFrom()[0]->getAddress());
        self::assertSame('own-reply@example.com', $own->getReplyTo()[0]->getAddress());

        $raw = new RawMessage('raw');
        $policy->apply($raw);
        self::assertSame('raw', $raw->toString());

        $empty = new Email();
        (new SenderPolicy(new MailSettings(new FakeSettings())))->apply($empty);
        self::assertSame([], $empty->getFrom());
        self::assertSame([], $empty->getReplyTo());
    }
}
