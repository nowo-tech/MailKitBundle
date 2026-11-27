<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Template;

use InvalidArgumentException;
use Nowo\MailKitBundle\Integration\UserKit\EmailTemplatesPersonalDataEraser;
use Nowo\MailKitBundle\Integration\UserKit\EmailTemplatesPersonalDataExportProvider;
use Nowo\MailKitBundle\Template\Event\TemplateDeletedEvent;
use Nowo\MailKitBundle\Template\Port\TemplateOwnerResolverInterface;
use Nowo\MailKitBundle\Template\Value\TemplateActor;
use Nowo\MailKitBundle\Template\Value\TemplateDraft;
use Nowo\MailKitBundle\Template\Value\TemplateKind;
use Nowo\MailKitBundle\Template\Value\TemplatePermission;
use Nowo\MailKitBundle\Template\Value\TemplateScope;
use Nowo\MailKitBundle\Tests\Support\TemplateHarness;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_column;

/**
 * 2.7.0 GDPR: export lists the owner's personal rows (with content) and the shared rows the owner wrote (no content);
 * erasure deletes the personal rows (one event each), clears the authorship of shared rows, is exact and idempotent,
 * and runs inside a host transaction too. The UserKit bridge loops over the owner references.
 */
final class TemplatePersonalDataTest extends TestCase
{
    private TemplateHarness $h;

    protected function setUp(): void
    {
        $this->h = new TemplateHarness($this);
        $this->h->permission->grant('*', TemplatePermission::Personal, TemplatePermission::ManageShared);
    }

    public function testExportAndErasure(): void
    {
        $ana    = new TemplateActor('t1', 'ana');
        $anaT2  = new TemplateActor('t2', 'ana');
        $bob    = new TemplateActor('t1', 'bob');
        $anaUp  = new TemplateActor('t1', 'Ana');
        $mine   = $this->h->library->create($ana, TemplateLibraryTest::draft());
        $mine2  = $this->h->library->create($anaT2, new TemplateDraft(TemplateKind::Snippet, TemplateScope::Personal, 'sig', 'Sig', 'en', '', '', 'Bye'));
        $shared = $this->h->library->create($ana, TemplateLibraryTest::draft(TemplateScope::Shared, 'by-ana'));
        $edited = $this->h->library->create($bob, TemplateLibraryTest::draft(TemplateScope::Shared, 'by-bob'));
        $edited = $this->h->library->update($ana, $edited->id, 1, TemplateLibraryTest::draft(TemplateScope::Shared, 'by-bob'));
        $both   = $this->h->library->create($ana, TemplateLibraryTest::draft(TemplateScope::Shared, 'both'));
        $both   = $this->h->library->update($ana, $both->id, 1, TemplateLibraryTest::draft(TemplateScope::Shared, 'both'));
        $other  = $this->h->library->create($anaUp, TemplateLibraryTest::draft());
        $this->h->library->create($bob, TemplateLibraryTest::draft());

        $export = $this->h->personalData->exportOwner('ana');
        self::assertSame([$mine->id, $mine2->id, $both->id, $shared->id, $edited->id], array_column($export, 'id'));
        self::assertSame(['owner', 'owner', 'author', 'author', 'last_author'], array_column($export, 'role'));
        self::assertSame('<p>Hola {{ me.first_name }}</p>', $export[0]['html']);
        self::assertSame('Bye', $export[1]['text']);
        self::assertNull($export[2]['html']);
        self::assertNull($export[2]['subject']);
        self::assertSame('2026-11-27T10:00:00+00:00', $export[0]['created_at']);

        $result = $this->h->personalData->eraseOwner('ana');
        self::assertSame(2, $result->deleted);
        self::assertSame(3, $result->anonymised);
        self::assertEquals([
            new TemplateDeletedEvent($mine->id, 't1', TemplateKind::Template, TemplateScope::Personal),
            new TemplateDeletedEvent($mine2->id, 't2', TemplateKind::Snippet, TemplateScope::Personal),
        ], $this->h->events->of(TemplateDeletedEvent::class));
        self::assertSame([], $this->h->personalData->exportOwner('ana'));
        $erasedAgain = $this->h->personalData->eraseOwner('ana');
        self::assertSame([0, 0], [$erasedAgain->deleted, $erasedAgain->anonymised]);

        // The shared rows stay for the tenant without the author; another person's rows and "Ana" are untouched.
        $sharedNow = $this->h->library->get($bob, $shared->id);
        self::assertNull($sharedNow->createdBy);
        self::assertNull($sharedNow->updatedBy);
        $editedNow = $this->h->library->get($bob, $edited->id);
        self::assertSame('bob', $editedNow->createdBy);
        self::assertNull($editedNow->updatedBy);
        self::assertSame($other->id, $this->h->library->get($anaUp, $other->id)->id);
        self::assertCount(2, $this->h->personalData->exportOwner('bob'), 'his personal row and the shared row he created');
    }

    public function testErasureJoinsAHostTransaction(): void
    {
        $ana = new TemplateActor('t1', 'ana');
        $this->h->library->create($ana, TemplateLibraryTest::draft());
        $this->h->connection->beginTransaction();
        self::assertSame(1, $this->h->personalData->eraseOwner('ana')->deleted);
        $this->h->connection->rollBack();
        self::assertCount(1, $this->h->personalData->exportOwner('ana'));
    }

    public function testMalformedReferencesAreRefused(): void
    {
        try {
            $this->h->personalData->exportOwner(' ana');
            self::fail('invalid');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(InvalidArgumentException::class);
        $this->h->personalData->eraseOwner('');
    }

    public function testTheUserKitBridge(): void
    {
        $ana = new TemplateActor('t1', 'ana');
        $this->h->library->create($ana, TemplateLibraryTest::draft());
        $this->h->library->create(new TemplateActor('t1', 'ana-2'), TemplateLibraryTest::draft());
        $owners = new class implements TemplateOwnerResolverInterface {
            public function ownerIdsFor(object $user): array
            {
                return ['ana', 'ana-2'];
            }
        };
        $export = new EmailTemplatesPersonalDataExportProvider($this->h->personalData, $owners);
        self::assertSame('email_templates', $export->getSectionKey());
        self::assertTrue($export->supports(new stdClass()));
        self::assertCount(2, $export->export(new stdClass()));

        $eraser = new EmailTemplatesPersonalDataEraser($this->h->personalData, $owners);
        self::assertSame('email_templates', $eraser->getKey());
        self::assertTrue($eraser->supports(new stdClass()));
        $eraser->erase(new ErasureRequest(new stdClass()));
        self::assertSame([], $export->export(new stdClass()));
    }
}
