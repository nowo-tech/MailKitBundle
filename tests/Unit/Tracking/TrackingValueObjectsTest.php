<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Tracking;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\MailKitBundle\Tracking\ConfiguredTrackableLinkFilter;
use Nowo\MailKitBundle\Tracking\DefaultTrackingRecipientResolver;
use Nowo\MailKitBundle\Tracking\NullEmailLinkRewriter;
use Nowo\MailKitBundle\Tracking\OpenStats;
use Nowo\MailKitBundle\Tracking\TrackedLink;
use Nowo\MailKitBundle\Tracking\TrackingDecision;
use Nowo\MailKitBundle\Tracking\TrackingIds;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Uid\Uuid;

use function str_repeat;

final class TrackingValueObjectsTest extends TestCase
{
    public function testDecisions(): void
    {
        $none = TrackingDecision::none();
        self::assertFalse($none->tracks());
        self::assertNull($none->recipientId);

        $full = TrackingDecision::track('UserA', 'acme');
        self::assertTrue($full->tracks());
        self::assertTrue($full->pixel && $full->links);
        self::assertSame(['UserA', 'acme'], [$full->recipientId, $full->tenant]);
        self::assertTrue(TrackingDecision::track(pixel: false)->tracks());
        self::assertFalse(TrackingDecision::track(pixel: false, links: false)->tracks());
    }

    public function testIdsAreExactAndBounded(): void
    {
        self::assertNull(TrackingIds::recipient(null));
        self::assertSame('UserA', TrackingIds::recipient('UserA'));
        self::assertSame(str_repeat('é', 128), TrackingIds::recipient(str_repeat('é', 128)));
        self::assertSame('acme', TrackingIds::tenant('acme'));
        self::assertSame('alert', TrackingIds::template('alert'));
        self::assertTrue(TrackingIds::isSendId(str_repeat('a1', 16)));
        self::assertFalse(TrackingIds::isSendId(str_repeat('A1', 16)));
        self::assertFalse(TrackingIds::isSendId(str_repeat('a', 31)));

        foreach (['', ' a', "a\n", "a\x00b", "\xff", str_repeat('x', 129)] as $bad) {
            try {
                TrackingIds::recipient($bad);
                self::fail('Accepted ' . $bad);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        TrackingIds::tenant(str_repeat('t', 65));
    }

    public function testValueObjectsAndTheDefaultRewriter(): void
    {
        $link = new TrackedLink(str_repeat('a', 32), 'https://x.example/', 'Go', 3, true, 'alert', 'u1', 'acme');
        self::assertSame([3, true, 'acme'], [$link->position, $link->essential, $link->tenant]);
        self::assertNull((new NullEmailLinkRewriter())->rewrite($link));

        $stats = new OpenStats('id', null, null, null, null, new DateTimeImmutable('@0'), 0, null, null, 2, null, null, null, null);
        self::assertFalse($stats->opened());
        $opened = new OpenStats('id', null, null, null, null, new DateTimeImmutable('@0'), 1, null, null, 0, null, null, null, null);
        self::assertTrue($opened->opened());
    }

    public function testConfiguredLinkFilter(): void
    {
        self::assertFalse((new ConfiguredTrackableLinkFilter())->mayRewrite('https://app.example.com/', null), 'Deny by default.');

        $filter = new ConfiguredTrackableLinkFilter(['App.Example.com', '*.example.org'], ['/issues/', '/docs']);
        self::assertTrue($filter->mayRewrite('https://APP.example.com/issues/1', null));
        self::assertTrue($filter->mayRewrite('https://app.example.com/issues', null));
        self::assertTrue($filter->mayRewrite('https://help.example.org/docs/a', null));
        self::assertFalse($filter->mayRewrite('https://example.org/docs/a', null), 'The wildcard matches sub-domains only.');
        self::assertFalse($filter->mayRewrite('https://evil-example.org/docs', null));
        self::assertFalse($filter->mayRewrite('https://app.example.com/issues-old', null));
        self::assertFalse($filter->mayRewrite('https://app.example.com', null));
        self::assertFalse($filter->mayRewrite('https://other.example.net/issues/1', null));
        self::assertFalse($filter->mayRewrite('not a url', null));

        $any = new ConfiguredTrackableLinkFilter(['*'], ['/']);
        self::assertTrue($any->mayRewrite('https://anything.example.net', null));
    }

    public function testDefaultRecipientResolver(): void
    {
        $resolver = new DefaultTrackingRecipientResolver();
        $uuid     = Uuid::v4();
        self::assertSame([(string) $uuid], $resolver->recipientIdsFor(new class($uuid) {
            public function __construct(private readonly Uuid $id)
            {
            }

            public function getId(): Uuid
            {
                return $this->id;
            }
        }));
        self::assertSame(['42'], $resolver->recipientIdsFor(new class {
            public function getId(): int
            {
                return 42;
            }
        }));
        self::assertSame([], $resolver->recipientIdsFor(new class {
            public function getId(): mixed
            {
                return null;
            }
        }));
        self::assertSame([], $resolver->recipientIdsFor(new class {
            public function getId(): string
            {
                return ' padded ';
            }
        }));
        self::assertSame([], $resolver->recipientIdsFor(new stdClass()));
    }
}
