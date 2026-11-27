<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Service;

use Nowo\MailKitBundle\Template\Exception\InvalidTemplate;
use Nowo\MailKitBundle\Template\Exception\TemplateAccessDenied;
use Nowo\MailKitBundle\Template\Exception\TemplateNotFound;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderDefinition;
use Nowo\MailKitBundle\Template\Value\RenderedTemplate;
use Nowo\MailKitBundle\Template\Value\TemplateActor;
use Nowo\MailKitBundle\Template\Value\TemplateDraft;

/**
 * Renders stored templates and snippets, and drafts for a preview (2.7.0): placeholders `{{ namespace.name }}` are
 * replaced by the values of the tagged providers (pure substitution, never evaluated, never re-expanded); unknown ones
 * stay as literal text; values are HTML-escaped in the HTML body and plain in the subject and the text body; the HTML
 * is sanitised again and the output is capped at `templates.max_output_bytes`.
 */
interface TemplateRendererInterface
{
    /**
     * Renders a row the actor may see.
     *
     * @param array<string, string> $subjects the host's subject references for the providers, such as
     *                                        `['contact' => '42']`
     *
     * @throws TemplateNotFound
     * @throws InvalidTemplate when the output exceeds `templates.max_output_bytes`
     */
    public function render(TemplateActor $actor, string $id, array $subjects = []): RenderedTemplate;

    /**
     * Renders an unsaved draft (preview in an editor); needs the permission to manage the draft's scope.
     *
     * @param array<string, string> $subjects
     *
     * @throws TemplateAccessDenied
     * @throws InvalidTemplate
     */
    public function renderDraft(TemplateActor $actor, TemplateDraft $draft, array $subjects = []): RenderedTemplate;

    /**
     * The placeholders an editor may offer, by namespace (`['me' => [PlaceholderDefinition, …], …]`).
     *
     * @return array<string, list<PlaceholderDefinition>>
     */
    public function placeholders(): array;
}
