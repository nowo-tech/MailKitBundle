<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Graph\Inbound;

use LogicException;
use Nowo\MailKitBundle\Graph\Inbound\AuthenticationResults;
use Nowo\MailKitBundle\Graph\Inbound\ConfiguredInboundProfiles;
use Nowo\MailKitBundle\Graph\Inbound\InboundAction;
use Nowo\MailKitBundle\Graph\Inbound\InboundAddress;
use Nowo\MailKitBundle\Graph\Inbound\InboundAttachment;
use Nowo\MailKitBundle\Graph\Inbound\InboundDecision;
use Nowo\MailKitBundle\Graph\Inbound\InboundLimits;
use Nowo\MailKitBundle\Graph\Inbound\Message\FetchGraphMessage;
use Nowo\MailKitBundle\Graph\Inbound\Message\RenewGraphSubscriptions;
use Nowo\MailKitBundle\Graph\Inbound\Message\SweepGraphMailbox;
use Nowo\MailKitBundle\Tests\Support\HostGraphMailboxProvider;
use PHPUnit\Framework\TestCase;

use function fopen;
use function fwrite;
use function is_resource;
use function serialize;
use function str_repeat;
use function stream_get_contents;
use function strlen;

final class InboundValueObjectsTest extends TestCase
{
    public function testDecision(): void
    {
        $route = InboundDecision::route(" task-42\n ");
        self::assertSame(InboundAction::Route, $route->action);
        self::assertSame('task-42', $route->reference);
        self::assertNull($route->reason);

        $quarantine = InboundDecision::quarantine('unknown_sender', 'q-1');
        self::assertSame(InboundAction::Quarantine, $quarantine->action);
        self::assertSame(['q-1', 'unknown_sender'], [$quarantine->reference, $quarantine->reason]);

        $discard = InboundDecision::discard(str_repeat('x', 100));
        self::assertSame(InboundAction::Discard, $discard->action);
        self::assertSame(64, strlen((string) $discard->reason));
        self::assertNull($discard->reference);
        self::assertNull(InboundDecision::discard('  ')->reason);
        self::assertSame('discard', InboundAction::Discard->value);
    }

    public function testAddress(): void
    {
        $address = new InboundAddress(" Projects+T-42@Example.com\r\n", "Projects\u{202E} Team\n", 'projects@example.com');
        self::assertSame('projects+t-42@example.com', $address->address);
        self::assertSame('Projects  Team', $address->name);
        self::assertSame('t-42', $address->tag);

        $plain = new InboundAddress('client@example.net', ' ', 'projects@example.com');
        self::assertNull($plain->name);
        self::assertNull($plain->tag);
        self::assertNull((new InboundAddress('a@b.c', null, 'projects@example.com'))->name);
    }

    public function testAuthenticationResults(): void
    {
        $results = AuthenticationResults::fromHeader('spf=pass (sender IP is 192.0.2.1) smtp.mailfrom=example.net; dkim=Pass header.d=example.net;dmarc=bestguesspass action=none');
        self::assertSame(['pass', 'pass', 'bestguesspass'], [$results->spf, $results->dkim, $results->dmarc]);
        self::assertTrue($results->aligned());

        $none = AuthenticationResults::fromHeader(null);
        self::assertSame([null, null, null], [$none->spf, $none->dkim, $none->dmarc]);
        self::assertFalse($none->aligned());

        $odd = AuthenticationResults::fromHeader('mx.example; spf=weird; xdkim=pass; dmarc=fail');
        self::assertNull($odd->spf);
        self::assertNull($odd->dkim);
        self::assertFalse((new AuthenticationResults('pass', null, 'fail'))->aligned());
        self::assertTrue((new AuthenticationResults(null, 'pass'))->aligned());
    }

    public function testLimits(): void
    {
        $limits = new InboundLimits();
        self::assertTrue($limits->allows('application/pdf'));
        self::assertTrue($limits->allows('Application/VND.openxmlformats-officedocument.wordprocessingml.document'));
        self::assertFalse($limits->allows('application/x-dosexec'));
        self::assertFalse($limits->allows('text/html'));
        self::assertTrue((new InboundLimits(allowedTypes: ['image/*']))->allows('image/svg+xml'));
    }

    public function testAttachment(): void
    {
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, 'bytes');
        $accepted = new InboundAttachment('a.pdf', 'application/pdf', 'application/pdf', 5, false, null, $stream, null);
        self::assertTrue($accepted->isAccepted());
        self::assertSame('bytes', stream_get_contents($accepted->stream()));
        $accepted->release();
        self::assertFalse(is_resource($stream));
        $accepted->release();

        try {
            $accepted->stream();
            self::fail('A released attachment has no stream.');
        } catch (LogicException $e) {
            self::assertStringContainsString('released', $e->getMessage());
        }

        $rejected = new InboundAttachment('a.exe', 'application/pdf', 'application/x-dosexec', 5, false, null, null, InboundAttachment::REJECTED_TYPE);
        self::assertFalse($rejected->isAccepted());
        $this->expectException(LogicException::class);
        serialize($rejected);
    }

    public function testRejectedAttachmentHasNoStream(): void
    {
        $this->expectException(LogicException::class);
        (new InboundAttachment('a', 'b/c', null, 0, false, null, null, InboundAttachment::REJECTED_SIZE))->stream();
    }

    public function testProfilesAndMessages(): void
    {
        self::assertSame(['acme'], (new ConfiguredInboundProfiles([], new HostGraphMailboxProvider()))->inboundProfiles());
        self::assertSame(['acme', 'globex'], (new ConfiguredInboundProfiles(['acme', 'globex'], new HostGraphMailboxProvider()))->inboundProfiles());
        self::assertSame(['acme', 'id'], [(new FetchGraphMessage('acme', 'id'))->profile, (new FetchGraphMessage('acme', 'id'))->graphMessageId]);
        self::assertNull((new SweepGraphMailbox())->profile);
        self::assertSame('acme', (new RenewGraphSubscriptions('acme'))->profile);
    }
}
