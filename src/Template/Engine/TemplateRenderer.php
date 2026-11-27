<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Engine;

use Nowo\MailKitBundle\Mailbox\Html\MailHtmlSanitizer;
use Nowo\MailKitBundle\Template\Exception\InvalidTemplate;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderContext;
use Nowo\MailKitBundle\Template\Service\TemplateRendererInterface;
use Nowo\MailKitBundle\Template\Value\RenderedTemplate;
use Nowo\MailKitBundle\Template\Value\TemplateActor;
use Nowo\MailKitBundle\Template\Value\TemplateDraft;
use Nowo\MailKitBundle\Template\Value\TemplateKind;

use function strlen;
use function trim;

/**
 * {@see TemplateRendererInterface} (2.7.0): reads the row through the library (same access rules), substitutes with
 * the {@see PlaceholderResolver}, checks the output size, sanitises the HTML again (a value can never add markup: it
 * is escaped, and the sanitiser drops anything a substituted attribute value could have made unsafe) and derives the
 * text body from the HTML when the template has none. Stateless (worker mode).
 *
 * @internal
 */
final readonly class TemplateRenderer implements TemplateRendererInterface
{
    /**
     * @param int $maxOutputBytes `templates.max_output_bytes` (rendered HTML and text, each)
     */
    public function __construct(
        private TemplateLibrary $library,
        private PlaceholderResolver $placeholders,
        private MailHtmlSanitizer $sanitizer,
        private int $maxOutputBytes,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function render(TemplateActor $actor, string $id, array $subjects = []): RenderedTemplate
    {
        $template = $this->library->get($actor, $id);

        return $this->substitute($template->id, $template->kind, $template->key, $template->locale, $template->subject, $template->html, $template->text, new PlaceholderContext($actor, $template->locale, $subjects));
    }

    /**
     * {@inheritdoc}
     */
    public function renderDraft(TemplateActor $actor, TemplateDraft $draft, array $subjects = []): RenderedTemplate
    {
        $this->library->assertManage($actor, $draft->scope);
        $html = $this->library->prepare($draft);

        return $this->substitute(null, $draft->kind, $draft->key, $draft->locale, $draft->subject, $html, $draft->text, new PlaceholderContext($actor, $draft->locale, $subjects));
    }

    /**
     * {@inheritdoc}
     */
    public function placeholders(): array
    {
        return $this->placeholders->definitions();
    }

    private function substitute(?string $id, TemplateKind $kind, string $key, string $locale, string $subject, string $html, string $text, PlaceholderContext $context): RenderedTemplate
    {
        $out = $this->placeholders->substitute($subject, $html, $text, $context);
        if (strlen($out['html']) > $this->maxOutputBytes || strlen($out['text']) > $this->maxOutputBytes) {
            throw new InvalidTemplate('output', 'The rendered template is larger than the configured limit.');
        }
        $html = trim($out['html']) === '' ? '' : $this->sanitizer->sanitize($out['html']);
        $text = trim($out['text']) === '' && $html !== '' ? $this->sanitizer->toText($html) : $out['text'];

        return new RenderedTemplate($id, $kind, $key, $locale, $out['subject'], $html, $text, $out['unresolved']);
    }
}
