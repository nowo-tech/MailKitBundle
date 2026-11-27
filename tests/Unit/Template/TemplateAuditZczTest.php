<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Template;

use Nowo\MailKitBundle\Template\Event\TemplateDeletedEvent;
use Nowo\MailKitBundle\Template\Exception\InvalidTemplate;
use Nowo\MailKitBundle\Template\Value\TemplateActor;
use Nowo\MailKitBundle\Template\Value\TemplateDraft;
use Nowo\MailKitBundle\Template\Value\TemplateKind;
use Nowo\MailKitBundle\Template\Value\TemplatePermission;
use Nowo\MailKitBundle\Template\Value\TemplateScope;
use Nowo\MailKitBundle\Tests\Support\TemplateHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function str_repeat;
use function strlen;

/**
 * 2.7.0 audit ZCZ fixes: no placeholder in URL attributes (L1), deleted-row events inside the delete's transaction
 * (L2), bounded provider values (I2), invisible characters and character-reference braces (I3).
 */
final class TemplateAuditZczTest extends TestCase
{
    private TemplateHarness $h;

    private TemplateActor $ana;

    protected function setUp(): void
    {
        $this->h   = new TemplateHarness($this);
        $this->ana = new TemplateActor('t1', 'ana');
        $this->h->permission->grant('*', TemplatePermission::Personal, TemplatePermission::ManageShared, TemplatePermission::UseShared);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function placeholderInUrls(): iterable
    {
        yield 'image path' => ['<img src="https://px.example/{{x.u}}.gif" alt="x">'];
        yield 'link query' => ['<a href="https://ex.com/r?to={{x.u}}">go</a>'];
        yield 'link path, spaced (%20)' => ['<a href="https://ex.com/{{ x.u }}">go</a>'];
        yield 'percent-encoded braces' => ['<a href="https://ex.com/%7B%7Bx.u%7D%7D">go</a>'];
        yield 'double-encoded braces' => ['<a href="https://ex.com/%257B%257Bx.u%257D%257D">go</a>'];
        yield 'contact link' => ['<a href="https://ex.com/c/{{contact.first_name}}" title="t">go</a>'];
    }

    #[DataProvider('placeholderInUrls')]
    public function testAPlaceholderInAnAddressIsRefused(string $html): void
    {
        foreach ([TemplateScope::Personal, TemplateScope::Shared] as $scope) {
            try {
                $this->h->library->create($this->ana, TemplateLibraryTest::draft($scope, html: $html));
                self::fail('refused');
            } catch (InvalidTemplate $invalid) {
                self::assertSame('html', $invalid->field);
            }
        }
        $this->expectException(InvalidTemplate::class);
        $this->h->renderer->renderDraft($this->ana, TemplateLibraryTest::draft(html: $html));
    }

    public function testTitleAndAltKeepPlaceholdersAndRenderInertly(): void
    {
        $row = $this->h->library->create($this->ana, TemplateLibraryTest::draft(html: '<a href="https://ex.com/" title="Hi {{ me.first_name }}">x</a><img src="https://cdn.example.com/l.png" alt="{{me.company}}">'));
        $out = $this->h->renderer->render($this->ana, $row->id);
        self::assertStringContainsString('title="Hi Ana"', $out->html);
        self::assertStringContainsString('alt="Acme &amp; &lt;Sons&gt;"', $out->html);
        self::assertStringContainsString('href="https://ex.com/"', $out->html);
    }

    public function testCharacterReferenceBracesNeverFormALivePlaceholder(): void
    {
        $row = $this->h->library->create($this->ana, TemplateLibraryTest::draft(html: "<p>&#123;&#123; me.first_name &#125;&#125; &#x7B;&#x7b;me.company&#X7D;&#x7d; &lbrace;&lcub;me.first_name&rcub;&rbrace; &#0123&#123me.first_name&#125;&#125; &#1234; {{ me.first_name }}\u{E000}</p>"));
        self::assertStringNotContainsString("\u{E000}", $row->html);
        $out = $this->h->renderer->render($this->ana, $row->id);
        self::assertSame("<p>{{ me.first_name }} {{me.company}} {{me.first_name}} {{me.first_name}} \u{04D2} Ana</p>", $out->html);
        self::assertSame(['contact.first_name'], $out->unresolved, 'only the subject placeholder; none from references');
    }

    public function testInvisibleCharactersAreRemovedFromValuesAndRefusedInSubjects(): void
    {
        $this->h->me->people['ana']['first_name'] = "A\u{200B}n\u{200E}a\u{FEFF}\u{061C}\u{2060}\u{200F}\u{200D}";
        $row                                      = $this->h->library->create($this->ana, new TemplateDraft(TemplateKind::Template, TemplateScope::Personal, 'k', 'N', 'es', 'Hi {{ me.first_name }}', '<p>{{ me.first_name }}</p>', '{{ me.first_name }}'));
        $out                                      = $this->h->renderer->render($this->ana, $row->id);
        self::assertSame("Hi Ana\u{200D}", $out->subject);
        self::assertSame("<p>Ana\u{200D}</p>", $out->html);
        self::assertSame("Ana\u{200D}", $out->text);
        foreach (["\u{200B}", "\u{200E}", "\u{200F}", "\u{061C}", "\u{FEFF}", "\u{2060}"] as $char) {
            try {
                new TemplateDraft(TemplateKind::Template, TemplateScope::Personal, 'k', 'N', 'es', 'S' . $char, '<p>x</p>', '');
                self::fail('refused');
            } catch (InvalidTemplate $invalid) {
                self::assertSame('subject', $invalid->field);
            }
        }
    }

    public function testProviderValuesAreBounded(): void
    {
        $h = new TemplateHarness($this, maxBodyBytes: 4096, maxOutputBytes: 200_000);
        $h->permission->grant('*', TemplatePermission::Personal);
        $h->me->people['ana']['first_name'] = str_repeat('x', 65_535) . 'é' . str_repeat('y', 10);
        $row                                = $h->library->create($this->ana, new TemplateDraft(TemplateKind::Snippet, TemplateScope::Personal, 'k', 'N', 'es', '', '', '{{ me.first_name }}'));
        self::assertSame(65_535, strlen($h->renderer->render($this->ana, $row->id)->text), 'cut at the character boundary below 65 536 bytes');

        // Many values together stop the render as soon as the part passes max_output_bytes.
        $many = $h->library->create($this->ana, new TemplateDraft(TemplateKind::Snippet, TemplateScope::Personal, 'many', 'N', 'es', '', '', str_repeat('{{ me.first_name }}', 4)));
        try {
            $h->renderer->render($this->ana, $many->id);
            self::fail('too large');
        } catch (InvalidTemplate $invalid) {
            self::assertSame('output', $invalid->field);
        }

        // A stored body larger than the output limit (written around the library) is refused too.
        $this->h->connection->executeStatement('UPDATE mail_kit_template SET body_html = ?', [str_repeat('a', 9000)]);
        $small = $this->h->library->create($this->ana, TemplateLibraryTest::draft(key: 'small'));
        $this->h->connection->executeStatement('UPDATE mail_kit_template SET body_html = ? WHERE id = ?', [str_repeat('a', 9000), $small->id]);
        $this->expectException(InvalidTemplate::class);
        $this->h->renderer->render($this->ana, $small->id);
    }

    public function testAThrowingListenerRollsTheDeleteBack(): void
    {
        $row                    = $this->h->library->create($this->ana, TemplateLibraryTest::draft());
        $this->h->events->throw = new RuntimeException('listener down');
        try {
            $this->h->library->delete($this->ana, $row->id, 1);
            self::fail('thrown');
        } catch (RuntimeException $e) {
            self::assertSame('listener down', $e->getMessage());
        }
        self::assertSame($row->id, $this->h->library->get($this->ana, $row->id)->id, 'the delete was rolled back');

        // Inside a host transaction too (savepoint): the host transaction stays usable.
        $this->h->connection->beginTransaction();
        try {
            $this->h->library->delete($this->ana, $row->id, 1);
            self::fail('thrown');
        } catch (RuntimeException) {
        }
        self::assertSame(1, $this->h->library->get($this->ana, $row->id)->version);
        $this->h->connection->commit();

        // A retry dispatches the event again.
        $this->h->events->throw = null;
        $this->h->library->delete($this->ana, $row->id, 1);
        self::assertEquals([new TemplateDeletedEvent($row->id, 't1', TemplateKind::Template, TemplateScope::Personal)], $this->h->events->of(TemplateDeletedEvent::class));
    }

    public function testAThrowingListenerRollsTheErasureBack(): void
    {
        $this->h->library->create($this->ana, TemplateLibraryTest::draft());
        $this->h->library->create($this->ana, TemplateLibraryTest::draft(TemplateScope::Shared));
        $this->h->events->throw = new RuntimeException('listener down');
        try {
            $this->h->personalData->eraseOwner('ana');
            self::fail('thrown');
        } catch (RuntimeException) {
        }
        self::assertCount(2, $this->h->personalData->exportOwner('ana'), 'nothing deleted, nothing anonymised');
        $this->h->events->throw = null;
        $result                 = $this->h->personalData->eraseOwner('ana');
        self::assertSame([1, 1], [$result->deleted, $result->anonymised]);
        self::assertCount(1, $this->h->events->of(TemplateDeletedEvent::class));
    }
}
