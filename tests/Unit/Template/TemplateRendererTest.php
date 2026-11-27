<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Template;

use DateTimeImmutable;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Nowo\MailKitBundle\Template\Exception\InvalidTemplate;
use Nowo\MailKitBundle\Template\Exception\TemplateAccessDenied;
use Nowo\MailKitBundle\Template\Exception\TemplateNotFound;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderDefinition;
use Nowo\MailKitBundle\Template\Value\StoredTemplate;
use Nowo\MailKitBundle\Template\Value\TemplateActor;
use Nowo\MailKitBundle\Template\Value\TemplateDraft;
use Nowo\MailKitBundle\Template\Value\TemplateKind;
use Nowo\MailKitBundle\Template\Value\TemplatePermission;
use Nowo\MailKitBundle\Template\Value\TemplateScope;
use Nowo\MailKitBundle\Tests\Support\TemplateHarness;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;

use function str_repeat;

/**
 * 2.7.0 renderer: pure substitution through the closed registry (no evaluation, no re-expansion), unknown placeholders
 * as literal text, escaping per part, providers asked once per namespace, sanitised output, size caps, text fallback.
 */
final class TemplateRendererTest extends TestCase
{
    private TemplateHarness $h;

    private TemplateActor $ana;

    protected function setUp(): void
    {
        $this->h   = new TemplateHarness($this);
        $this->ana = new TemplateActor('t1', 'ana');
        $this->h->permission->grant('*', TemplatePermission::Personal, TemplatePermission::UseShared);
    }

    public function testPlaceholdersAreSubstitutedNeverEvaluated(): void
    {
        $row = $this->h->library->create($this->ana, new TemplateDraft(
            TemplateKind::Template,
            TemplateScope::Personal,
            'hello',
            'Hello',
            'es',
            'Hola {{contact.first_name}} de {{ me.company }} {{ me.nope }}',
            '<p>{{ me.first_name }} / {{contact.first_name}} / {{contact.echo}} / {{ unknown.thing }} / {{ contact.invalid }} / {{contact.number}} / {{ Me.first_name }} / {{ {{me.first_name}} }} / {% if 1 %}x{% endif %} / {{ 7*7 }}</p>',
            "{{ me.company }}\n{{contact.first_name}}\n{{contact.note}}",
        ));
        $out = $this->h->renderer->render($this->ana, $row->id, ['contact' => '42']);

        self::assertSame($row->id, $out->templateId);
        self::assertSame('hello', $out->key);
        self::assertSame('es', $out->locale);
        self::assertSame('Hola <script>alert(1)</script>Pedro de Acme & <Sons> {{ me.nope }}', $out->subject);
        self::assertSame(
            '<p>Ana / &lt;script&gt;alert(1)&lt;/script&gt;Pedro / {{ me.first_name }} / {{ unknown.thing }} / {{ contact.invalid }} / {{contact.number}} / {{ Me.first_name }} / {{ Ana }} / {% if 1 %}x{% endif %} / {{ 7*7 }}</p>',
            $out->html,
        );
        self::assertSame("Acme & <Sons>\n<script>alert(1)</script>Pedro\nline1\nline2\tend", $out->text);
        self::assertSame(['contact.invalid', 'contact.number', 'me.nope', 'unknown.thing'], $out->unresolved);
        // Each provider was asked once, with the distinct names of its namespace.
        self::assertSame([['company', 'nope', 'first_name']], $this->h->me->calls);

        // Without the subject reference the contact provider returns nothing: the placeholders stay as text.
        $plain = $this->h->renderer->render($this->ana, $row->id);
        self::assertStringContainsString('Hola {{contact.first_name}} de', $plain->subject);
    }

    public function testTheOutputIsSanitisedAndCappedAndTextIsDerived(): void
    {
        $row = $this->h->library->create($this->ana, new TemplateDraft(TemplateKind::Snippet, TemplateScope::Personal, 'sig', 'Sig', 'en', '', '<p>Regards,<br>{{ me.first_name }}</p><p>Line</p>', ''));
        $out = $this->h->renderer->render($this->ana, $row->id);
        self::assertSame('<p>Regards,<br />Ana</p><p>Line</p>', $out->html);
        self::assertSame("Regards,\nAna\nLine", $out->text);
        self::assertSame(TemplateKind::Snippet, $out->kind);

        $this->h->me->people['ana']['first_name'] = str_repeat('x', 9000);
        try {
            $this->h->renderer->render($this->ana, $row->id);
            self::fail('too large');
        } catch (InvalidTemplate $invalid) {
            self::assertSame('output', $invalid->field);
        }
        $text = $this->h->library->create($this->ana, new TemplateDraft(TemplateKind::Snippet, TemplateScope::Personal, 'txt', 'Txt', 'en', '', '', '{{ me.first_name }}'));
        $this->expectException(InvalidTemplate::class);
        $this->h->renderer->render($this->ana, $text->id);
    }

    public function testRenderingSanitisesAgainWithHttpsImagesOnly(): void
    {
        // A row written around the library (an import, hand-written SQL) still renders https images only.
        $row = $this->h->library->create($this->ana, TemplateLibraryTest::draft(html: '<p>{{ me.first_name }}</p>'));
        $this->h->connection->executeStatement('UPDATE mail_kit_template SET body_html = ?', ['<p>{{ me.first_name }}</p><img src="http://t.example/p.gif"><img src="https://cdn.example.com/l.png"><img src="data:image/gif;base64,R0lGODlh"><script>x()</script>']);
        self::assertSame('<p>Ana</p><img /><img src="https://cdn.example.com/l.png" /><img />', $this->h->renderer->render($this->ana, $row->id)->html);
    }

    public function testATextOnlySnippetKeepsAnEmptyHtmlBody(): void
    {
        $row = $this->h->library->create($this->ana, new TemplateDraft(TemplateKind::Snippet, TemplateScope::Personal, 'txt', 'Txt', 'en', '', '', 'Bye {{ me.first_name }}'));
        $out = $this->h->renderer->render($this->ana, $row->id);
        self::assertSame('', $out->html);
        self::assertSame('Bye Ana', $out->text);

        $email = $out->applyTo((new Email())->subject('kept')->html('<p>old</p>'));
        self::assertSame('kept', $email->getSubject(), 'a snippet sets no subject');
        self::assertSame('<p>old</p>', $email->getHtmlBody(), 'an empty HTML body is not applied');
        self::assertSame('Bye Ana', $email->getTextBody());
        self::assertSame('txt', MailHeaders::value($email, MailHeaders::TAG));
        self::assertSame('txt', MailHeaders::value($email, MailHeaders::TEMPLATE));
    }

    public function testApplyToSetsTheTemplateParts(): void
    {
        $row   = $this->h->library->create($this->ana, TemplateLibraryTest::draft());
        $email = $this->h->renderer->render($this->ana, $row->id)->applyTo(new Email(), false);
        self::assertSame('Re: {{contact.first_name}}', $email->getSubject());
        self::assertSame('<p>Hola Ana</p>', $email->getHtmlBody());
        self::assertSame('Hola Ana', $email->getTextBody());
        self::assertNull(MailHeaders::value($email, MailHeaders::TAG));
    }

    public function testRenderingFollowsTheLibraryAccessRules(): void
    {
        $row = $this->h->library->create($this->ana, TemplateLibraryTest::draft());
        $this->expectException(TemplateNotFound::class);
        $this->h->renderer->render(new TemplateActor('t1', 'bob'), $row->id);
    }

    public function testDraftPreview(): void
    {
        $out = $this->h->renderer->renderDraft($this->ana, TemplateLibraryTest::draft(html: '<p onmouseover="x">{{ me.first_name }}</p>'), ['contact' => '42']);
        self::assertNull($out->templateId);
        self::assertSame('<p>Ana</p>', $out->html);
        self::assertSame('Re: <script>alert(1)</script>Pedro', $out->subject);
        try {
            $this->h->renderer->renderDraft($this->ana, TemplateLibraryTest::draft(TemplateScope::Shared));
            self::fail('denied');
        } catch (TemplateAccessDenied $denied) {
            self::assertSame(TemplatePermission::ManageShared, $denied->permission);
        }
        $this->expectException(InvalidTemplate::class);
        $this->h->renderer->renderDraft($this->ana, TemplateLibraryTest::draft(html: str_repeat('{{me.a}}', 21)));
    }

    public function testThePlaceholderListForEditors(): void
    {
        self::assertEquals([
            'contact' => [new PlaceholderDefinition('first_name', 'First name')],
            'me'      => [new PlaceholderDefinition('first_name', 'First name'), new PlaceholderDefinition('company', 'Company')],
        ], $this->h->renderer->placeholders());
    }

    public function testARowOfAnotherTenantIsNeverVisible(): void
    {
        $row = new StoredTemplate('id', TemplateKind::Template, TemplateScope::Shared, 't2', null, 'k', 'n', 'es', 's', '', 't', 1, null, null, new DateTimeImmutable(), new DateTimeImmutable());
        self::assertFalse($this->h->library->maySee($this->ana, $row));
        self::assertTrue($this->h->library->maySee(new TemplateActor('t2', 'ana'), $row));
    }
}
