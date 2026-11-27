<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Template;

use InvalidArgumentException;
use Nowo\MailKitBundle\Template\Exception\InvalidTemplate;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderContext;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderDefinition;
use Nowo\MailKitBundle\Template\Port\DenyAllTemplatePermission;
use Nowo\MailKitBundle\Template\Value\TemplateActor;
use Nowo\MailKitBundle\Template\Value\TemplateDraft;
use Nowo\MailKitBundle\Template\Value\TemplateErasureResult;
use Nowo\MailKitBundle\Template\Value\TemplateKind;
use Nowo\MailKitBundle\Template\Value\TemplatePermission;
use Nowo\MailKitBundle\Template\Value\TemplateScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_fill_keys;
use function range;
use function str_repeat;

/**
 * 2.7.0 value objects: the grammar of drafts, actor references and placeholder contexts, and the deny-all port.
 */
final class TemplateValueObjectsTest extends TestCase
{
    public function testAValidDraft(): void
    {
        $draft = new TemplateDraft(TemplateKind::Template, TemplateScope::Shared, 'a.b_c-1', '  Name  ', 'es_ES', 'Subject ñ', '<p>x</p>', '');
        self::assertSame('Name', $draft->name);
        self::assertSame('a.b_c-1', $draft->key);
        $snippet = new TemplateDraft(TemplateKind::Snippet, TemplateScope::Personal, '0', 'N', 'ast', '', '', 'text');
        self::assertSame('', $snippet->subject);
    }

    /**
     * @return iterable<string, array{0: string, 1: TemplateKind, 2: string, 3: string, 4: string, 5: string, 6: string, 7: string}>
     */
    public static function invalidDrafts(): iterable
    {
        $t = TemplateKind::Template;
        yield 'upper-case key' => ['key', $t, 'Key', 'n', 'es', 's', 'h', ''];
        yield 'leading dot key' => ['key', $t, '.k', 'n', 'es', 's', 'h', ''];
        yield 'long key' => ['key', $t, str_repeat('k', 65), 'n', 'es', 's', 'h', ''];
        yield 'key with newline' => ['key', $t, "k\n", 'n', 'es', 's', 'h', ''];
        yield 'hyphen locale' => ['locale', $t, 'k', 'n', 'es-ES', 's', 'h', ''];
        yield 'blank name' => ['name', $t, 'k', '   ', 'es', 's', 'h', ''];
        yield 'long name' => ['name', $t, 'k', str_repeat('n', 191), 'es', 's', 'h', ''];
        yield 'name with control' => ['name', $t, 'k', "a\x07b", 'es', 's', 'h', ''];
        yield 'invalid UTF-8 name' => ['name', $t, 'k', "\xC3\x28", 'es', 's', 'h', ''];
        yield 'snippet subject' => ['subject', TemplateKind::Snippet, 'k', 'n', 'es', 's', 'h', ''];
        yield 'missing subject' => ['subject', $t, 'k', 'n', 'es', '', 'h', ''];
        yield 'subject header injection' => ['subject', $t, 'k', 'n', 'es', "s\r\nBcc: x@example.com", 'h', ''];
        yield 'subject bidi' => ['subject', $t, 'k', 'n', 'es', "s\u{202E}x", 'h', ''];
        yield 'subject line separator' => ['subject', $t, 'k', 'n', 'es', "s\u{2028}x", 'h', ''];
        yield 'long subject' => ['subject', $t, 'k', 'n', 'es', str_repeat('s', 256), 'h', ''];
        yield 'invalid UTF-8 html' => ['body', $t, 'k', 'n', 'es', 's', "\xC3\x28", ''];
        yield 'invalid UTF-8 text' => ['body', $t, 'k', 'n', 'es', 's', 'h', "\xC3\x28"];
        yield 'no body' => ['body', $t, 'k', 'n', 'es', 's', ' ', "\n"];
    }

    #[DataProvider('invalidDrafts')]
    public function testInvalidDrafts(string $field, TemplateKind $kind, string $key, string $name, string $locale, string $subject, string $html, string $text): void
    {
        try {
            new TemplateDraft($kind, TemplateScope::Personal, $key, $name, $locale, $subject, $html, $text);
            self::fail('invalid');
        } catch (InvalidTemplate $invalid) {
            self::assertSame($field, $invalid->field);
        }
    }

    public function testActorReferences(): void
    {
        $actor = new TemplateActor('org:1', 'user@example.com');
        self::assertSame('org:1', $actor->tenant);
        foreach ([['', 'a', 'tenant'], ['t', ' a', 'owner'], ['t', str_repeat('a', 129), 'owner'], ["t\n", 'a', 'tenant']] as [$tenant, $owner, $what]) {
            try {
                new TemplateActor($tenant, $owner);
                self::fail('invalid');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('template ' . $what . ' reference', $e->getMessage());
            }
        }
    }

    public function testPlaceholderContextAndDefinition(): void
    {
        $context = new PlaceholderContext(new TemplateActor('t', 'o'), 'es', ['contact' => '42', 'deal' => 'd-7']);
        self::assertSame('42', $context->subject('contact'));
        self::assertNull($context->subject('company'));
        foreach ([['Contact' => '1'], ['contact' => ''], ['contact' => 'a b'], [0 => '1'], array_fill_keys(range('a', 'q'), '1')] as $subjects) {
            try {
                new PlaceholderContext(new TemplateActor('t', 'o'), 'es', $subjects); // @phpstan-ignore argument.type
                self::fail('invalid');
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame('first_name', (new PlaceholderDefinition('first_name', 'First'))->name);
        $this->expectException(InvalidArgumentException::class);
        new PlaceholderDefinition('First', 'x');
    }

    public function testDenyAllAndResults(): void
    {
        $deny = new DenyAllTemplatePermission();
        foreach (TemplatePermission::cases() as $permission) {
            self::assertFalse($deny->isGranted($permission, new TemplateActor('t', 'o')));
        }
        $result = new TemplateErasureResult(2, 3);
        self::assertSame([2, 3], [$result->deleted, $result->anonymised]);
    }
}
