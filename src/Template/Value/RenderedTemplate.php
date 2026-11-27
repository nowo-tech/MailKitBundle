<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Value;

use Nowo\MailKitBundle\Mailer\MailHeaders;
use Symfony\Component\Mime\Email;

/**
 * A rendered template or snippet (2.7.0), ready for MailKit's send path: placeholders substituted (values HTML-escaped
 * in `html`, plain in `subject` / `text`), the HTML sanitised again, the text derived from the HTML when the template
 * has none. `unresolved` lists the placeholders left as literal text (unknown namespace or name, or no value), so an
 * editor can warn before sending.
 */
final readonly class RenderedTemplate
{
    /**
     * @param string|null $templateId the stored template id, null for a draft preview
     * @param list<string> $unresolved `namespace.name` of every placeholder left as text, once each
     */
    public function __construct(
        public ?string $templateId,
        public TemplateKind $kind,
        public string $key,
        public string $locale,
        public string $subject,
        public string $html,
        public string $text,
        public array $unresolved = [],
    ) {
    }

    /**
     * Sets the subject (templates only), the HTML and the text body of `$email`; with `$tag` the sent-mail log tag and
     * the tracking template id become the template key ({@see MailHeaders::tag()} / {@see MailHeaders::template()}).
     * Returns the same message.
     */
    public function applyTo(Email $email, bool $tag = true): Email
    {
        if ($this->kind === TemplateKind::Template) {
            $email->subject($this->subject);
        }
        if ($this->html !== '') {
            $email->html($this->html);
        }
        $email->text($this->text);
        if ($tag) {
            MailHeaders::tag($email, $this->key);
            MailHeaders::template($email, $this->key);
        }

        return $email;
    }
}
