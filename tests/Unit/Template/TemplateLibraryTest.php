<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Template;

use Nowo\MailKitBundle\Template\Event\TemplateDeletedEvent;
use Nowo\MailKitBundle\Template\Exception\InvalidTemplate;
use Nowo\MailKitBundle\Template\Exception\TemplateAccessDenied;
use Nowo\MailKitBundle\Template\Exception\TemplateKeyTaken;
use Nowo\MailKitBundle\Template\Exception\TemplateNotFound;
use Nowo\MailKitBundle\Template\Exception\TemplateVersionConflict;
use Nowo\MailKitBundle\Template\Value\TemplateActor;
use Nowo\MailKitBundle\Template\Value\TemplateDraft;
use Nowo\MailKitBundle\Template\Value\TemplateKind;
use Nowo\MailKitBundle\Template\Value\TemplatePermission;
use Nowo\MailKitBundle\Template\Value\TemplateScope;
use Nowo\MailKitBundle\Template\Value\TemplateSummary;
use Nowo\MailKitBundle\Tests\Support\TemplateHarness;
use PHPUnit\Framework\TestCase;

use function array_map;
use function str_repeat;

use const DATE_ATOM;

/**
 * 2.7.0 template library on SQLite: deny by default, tenant isolation, owner-only personal rows, shared rows by
 * permission, optimistic versions, caught duplicate keys, the per-scope cap, HTML sanitised on save, locale fallback.
 */
final class TemplateLibraryTest extends TestCase
{
    private TemplateHarness $h;

    private TemplateActor $ana;

    private TemplateActor $bob;

    private TemplateActor $eve;

    protected function setUp(): void
    {
        $this->h   = new TemplateHarness($this);
        $this->ana = new TemplateActor('t1', 'ana');
        $this->bob = new TemplateActor('t1', 'bob');
        $this->eve = new TemplateActor('t2', 'ana');
    }

    public static function draft(TemplateScope $scope = TemplateScope::Personal, string $key = 'follow-up', string $locale = 'es', string $html = '<p>Hola {{ me.first_name }}</p>', TemplateKind $kind = TemplateKind::Template): TemplateDraft
    {
        return new TemplateDraft($kind, $scope, $key, 'Follow-up', $locale, $kind === TemplateKind::Template ? 'Re: {{contact.first_name}}' : '', $html, '');
    }

    public function testEverythingIsDeniedByDefault(): void
    {
        self::assertSame([], $this->h->library->list($this->ana, TemplateKind::Template));
        try {
            $this->h->library->create($this->ana, self::draft());
            self::fail('denied');
        } catch (TemplateAccessDenied $denied) {
            self::assertSame(TemplatePermission::Personal, $denied->permission);
        }
        $this->expectException(TemplateAccessDenied::class);
        $this->h->library->create($this->ana, self::draft(TemplateScope::Shared));
    }

    public function testPersonalRowsAreTheOwnersOnly(): void
    {
        $this->h->permission->grant('*', TemplatePermission::Personal, TemplatePermission::UseShared);
        $mine = $this->h->library->create($this->ana, self::draft());
        self::assertSame('ana', $mine->owner);
        self::assertSame('ana', $mine->createdBy);
        self::assertSame(1, $mine->version);
        self::assertSame('t1', $mine->tenant);
        self::assertSame('2026-11-27T10:00:00+00:00', $mine->createdAt->format(DATE_ATOM));
        self::assertSame($mine->id, $this->h->library->get($this->ana, $mine->id)->id);
        self::assertSame([$mine->id], array_map(static fn (TemplateSummary $s): string => $s->id, $this->h->library->list($this->ana, TemplateKind::Template)));

        // Another person of the tenant, the same person in another tenant, and a garbage id: not found.
        foreach ([[$this->bob, $mine->id], [$this->eve, $mine->id], [$this->ana, 'not-a-uuid'], [$this->ana, '00000000-0000-7000-8000-000000000000']] as [$actor, $id]) {
            try {
                $this->h->library->get($actor, $id);
                self::fail('not found');
            } catch (TemplateNotFound $e) {
                self::assertSame('Template not found.', $e->getMessage());
            }
        }
        self::assertSame([], $this->h->library->list($this->bob, TemplateKind::Template));
        self::assertSame([], $this->h->library->list($this->eve, TemplateKind::Template));
        self::assertSame([], $this->h->library->list($this->ana, TemplateKind::Snippet));

        // The same key for another owner, another tenant or another locale is fine; the same one is taken.
        $this->h->library->create($this->bob, self::draft());
        $this->h->library->create($this->eve, self::draft());
        $this->h->library->create($this->ana, self::draft(locale: 'es_ES'));
        $this->expectException(TemplateKeyTaken::class);
        $this->h->library->create($this->ana, self::draft());
    }

    public function testRevokingThePersonalPermissionHidesPersonalRows(): void
    {
        $this->h->permission->grant('*', TemplatePermission::Personal);
        $mine = $this->h->library->create($this->ana, self::draft());
        $this->h->permission->grant('*');
        self::assertSame([], $this->h->library->list($this->ana, TemplateKind::Template));
        $this->expectException(TemplateNotFound::class);
        $this->h->library->get($this->ana, $mine->id);
    }

    public function testSharedRowsFollowTheHostPermissions(): void
    {
        $this->h->permission->grant('ana', TemplatePermission::ManageShared);
        $this->h->permission->grant('bob', TemplatePermission::UseShared, TemplatePermission::Personal);
        $shared = $this->h->library->create($this->ana, self::draft(TemplateScope::Shared, 'proposal'));
        self::assertNull($shared->owner);
        self::assertSame('ana', $shared->createdBy);
        $personal = $this->h->library->create($this->bob, self::draft(TemplateScope::Personal, 'mine'));

        // Bob uses shared rows but cannot change them; his list has his own row first, then the shared one.
        self::assertSame(['mine', 'proposal'], array_map(static fn (TemplateSummary $s): string => $s->key, $this->h->library->list($this->bob, TemplateKind::Template)));
        self::assertSame(['proposal'], array_map(static fn (TemplateSummary $s): string => $s->key, $this->h->library->list($this->bob, TemplateKind::Template, TemplateScope::Shared)));
        self::assertSame(['mine'], array_map(static fn (TemplateSummary $s): string => $s->key, $this->h->library->list($this->bob, TemplateKind::Template, TemplateScope::Personal)));
        self::assertSame([], $this->h->library->list($this->bob, TemplateKind::Template, null, 'fr'));
        self::assertCount(2, $this->h->library->list($this->bob, TemplateKind::Template, null, 'es'));
        self::assertSame($shared->id, $this->h->library->get($this->bob, $shared->id)->id);
        try {
            $this->h->library->update($this->bob, $shared->id, 1, self::draft(TemplateScope::Shared, 'proposal'));
            self::fail('denied');
        } catch (TemplateAccessDenied $denied) {
            self::assertSame(TemplatePermission::ManageShared, $denied->permission);
            self::assertSame('Template access denied (manage_shared).', $denied->getMessage());
        }
        try {
            $this->h->library->delete($this->bob, $shared->id, 1);
            self::fail('denied');
        } catch (TemplateAccessDenied) {
        }

        // Ana manages shared rows only (no Personal): she sees the shared one, not Bob's personal one.
        self::assertSame(['proposal'], array_map(static fn (TemplateSummary $s): string => $s->key, $this->h->library->list($this->ana, TemplateKind::Template)));
        $this->expectException(TemplateNotFound::class);
        $this->h->library->get($this->ana, $personal->id);
    }

    public function testUpdatesAreOptimistic(): void
    {
        $this->h->permission->grant('*', TemplatePermission::ManageShared, TemplatePermission::Personal);
        $row = $this->h->library->create($this->ana, self::draft(TemplateScope::Shared));
        $this->h->clock->modify('+1 hour');
        $v2 = $this->h->library->update($this->bob, $row->id, 1, new TemplateDraft(TemplateKind::Template, TemplateScope::Shared, 'follow-up-2', 'Renamed', 'en', 'New subject', '<p onclick="x()">New <script>bad()</script>body</p>', 'Text'));
        self::assertSame(2, $v2->version);
        self::assertSame('follow-up-2', $v2->key);
        self::assertSame('en', $v2->locale);
        self::assertSame('Renamed', $v2->name);
        self::assertSame('<p>New body</p>', $v2->html);
        self::assertSame('Text', $v2->text);
        self::assertSame('ana', $v2->createdBy);
        self::assertSame('bob', $v2->updatedBy);
        self::assertSame('2026-11-27T11:00:00+00:00', $v2->updatedAt->format(DATE_ATOM));
        self::assertSame('bob', $v2->summary()->updatedBy);

        try {
            $this->h->library->update($this->ana, $row->id, 1, self::draft(TemplateScope::Shared));
            self::fail('conflict');
        } catch (TemplateVersionConflict $conflict) {
            self::assertSame(1, $conflict->expected);
        }
        try {
            $this->h->library->delete($this->ana, $row->id, 1);
            self::fail('conflict');
        } catch (TemplateVersionConflict) {
        }
        try {
            $this->h->library->update($this->ana, $row->id, 2, self::draft(TemplateScope::Personal));
            self::fail('scope');
        } catch (InvalidTemplate $invalid) {
            self::assertSame('scope', $invalid->field);
        }
        try {
            $this->h->library->update($this->ana, $row->id, 2, self::draft(TemplateScope::Shared, kind: TemplateKind::Snippet));
            self::fail('kind');
        } catch (InvalidTemplate $invalid) {
            self::assertSame('scope', $invalid->field);
        }
        $other = $this->h->library->create($this->ana, self::draft(TemplateScope::Shared, 'other', 'en'));
        try {
            $this->h->library->update($this->ana, $other->id, 1, new TemplateDraft(TemplateKind::Template, TemplateScope::Shared, 'follow-up-2', 'X', 'en', 'S', '<p>x</p>', ''));
            self::fail('taken');
        } catch (TemplateKeyTaken $taken) {
            self::assertSame('A template with this key and locale exists already.', $taken->getMessage());
        }

        $this->h->library->delete($this->ana, $row->id, 2);
        self::assertEquals([new TemplateDeletedEvent($row->id, 't1', TemplateKind::Template, TemplateScope::Shared)], $this->h->events->of(TemplateDeletedEvent::class));
        $this->expectException(TemplateNotFound::class);
        $this->h->library->delete($this->ana, $row->id, 2);
    }

    public function testAConcurrentDeleteReadsAsNotFound(): void
    {
        $this->h->permission->grant('*', TemplatePermission::Personal);
        $row = $this->h->library->create($this->ana, self::draft());
        $this->h->connection->executeStatement('DELETE FROM mail_kit_template');
        $this->expectException(TemplateNotFound::class);
        $this->h->library->update($this->ana, $row->id, 1, self::draft());
    }

    public function testDeleteThatLosesTheRaceToADelete(): void
    {
        $this->h->permission->grant('*', TemplatePermission::Personal);
        $row = $this->h->library->create($this->ana, self::draft());
        $this->h->connection->executeStatement('UPDATE mail_kit_template SET version = 5');
        try {
            $this->h->library->delete($this->ana, $row->id, 1);
            self::fail('conflict');
        } catch (TemplateVersionConflict) {
        }
        self::assertSame([], $this->h->events->events);
    }

    public function testLimitsAndSanitising(): void
    {
        $this->h->permission->grant('*', TemplatePermission::Personal);
        foreach ([
            'body'         => self::draft(html: str_repeat('a', 4097)),
            'placeholders' => self::draft(html: str_repeat('{{me.x}}', 21)),
        ] as $field => $draft) {
            try {
                $this->h->library->create($this->ana, $draft);
                self::fail($field);
            } catch (InvalidTemplate $invalid) {
                self::assertSame($field, $invalid->field);
            }
        }
        $textOnly = $this->h->library->create($this->ana, new TemplateDraft(TemplateKind::Snippet, TemplateScope::Personal, 'sig', 'Signature', 'es', '', '  ', '-- {{ me.first_name }}'));
        self::assertSame('', $textOnly->html);
        self::assertSame('', $textOnly->subject);
        $html = $this->h->library->create($this->ana, self::draft(key: 'links', html: '<a href="javascript:alert(1)">x</a><img src="https://cdn.example.com/logo.png" alt="L">'));
        self::assertStringNotContainsString('javascript', $html->html);
        self::assertStringContainsString('src="https://cdn.example.com/logo.png"', $html->html);
        // https images only (owner answer Q2): http, data: and relative image sources are removed on save.
        $images = $this->h->library->create($this->ana, self::draft(key: 'images', html: '<p>x</p><img src="http://cdn.example.com/pixel.gif"><img src="data:image/gif;base64,R0lGODlhAQABAAAAACw="><img src="/logo.png"><img srcset="https://cdn.example.com/2x.png 2x" src="cid:logo@brand">'));
        self::assertSame('<p>x</p><img /><img /><img /><img src="cid:logo@brand" />', $images->html);
        $this->h->library->delete($this->ana, $images->id, 1);

        // The per-scope cap (5 here) counts per tenant, kind, scope and owner.
        for ($i = 0; $i < 4; ++$i) {
            $this->h->library->create($this->ana, self::draft(key: 'k' . $i));
        }
        try {
            $this->h->library->create($this->ana, self::draft(key: 'k9'));
            self::fail('cap');
        } catch (InvalidTemplate $invalid) {
            self::assertSame('scope', $invalid->field);
        }
        self::assertSame('bob', $this->h->library->create($this->bob, self::draft(key: 'k9'))->owner);
        self::assertCount(5, $this->h->library->list($this->ana, TemplateKind::Template));
    }

    public function testFindByKeyFallsBackToTheLanguage(): void
    {
        $this->h->permission->grant('*', TemplatePermission::Personal, TemplatePermission::UseShared, TemplatePermission::ManageShared);
        $es     = $this->h->library->create($this->ana, self::draft(TemplateScope::Shared));
        $esEs   = $this->h->library->create($this->ana, self::draft(TemplateScope::Shared, locale: 'es_ES'));
        $mineEs = $this->h->library->create($this->ana, self::draft());
        self::assertSame($esEs->id, $this->h->library->findByKey($this->bob, TemplateKind::Template, TemplateScope::Shared, 'follow-up', 'es_ES')->id);
        self::assertSame($es->id, $this->h->library->findByKey($this->bob, TemplateKind::Template, TemplateScope::Shared, 'follow-up', 'es_MX')->id);
        self::assertSame($es->id, $this->h->library->findByKey($this->bob, TemplateKind::Template, TemplateScope::Shared, 'follow-up', 'es')->id);
        self::assertSame($mineEs->id, $this->h->library->findByKey($this->ana, TemplateKind::Template, TemplateScope::Personal, 'follow-up', 'es_AR')->id);
        foreach ([[$this->bob, TemplateScope::Personal, 'es'], [$this->eve, TemplateScope::Shared, 'es'], [$this->bob, TemplateScope::Shared, 'fr']] as [$actor, $scope, $locale]) {
            try {
                $this->h->library->findByKey($actor, TemplateKind::Template, $scope, 'follow-up', $locale);
                self::fail('not found');
            } catch (TemplateNotFound) {
            }
        }
    }
}
