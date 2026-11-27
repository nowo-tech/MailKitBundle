<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Placeholder;

/**
 * A provider of one placeholder namespace (2.7.0), registered with the service tag
 * `nowo_mail_kit.template_placeholder_provider` (autoconfigured for classes implementing this interface). The
 * registry is closed: only tagged providers resolve anything, and one namespace has exactly one provider (the
 * container build fails on a duplicate or an invalid name).
 *
 * MailKit never evaluates a template: `{{ ns.name }}` is replaced by the string this provider returns, nothing else.
 * Providers must check that the context's actor may see the data they return (the CRM provider checks that the actor
 * may view the contact) and return plain text: MailKit escapes it for HTML bodies. Stateless (worker mode): no memo
 * between calls.
 */
interface PlaceholderProviderInterface
{
    /** Service tag. */
    public const TAG = 'nowo_mail_kit.template_placeholder_provider';

    /** Accepted namespace. */
    public const NAMESPACE_PATTERN = '/^[a-z][a-z0-9_]{0,31}\z/';

    /** Accepted placeholder name inside a namespace. */
    public const NAME_PATTERN = '/^[a-z0-9_]{1,40}\z/';

    /**
     * The namespace, such as `me` or `contact` (read at container build time).
     */
    public static function placeholderNamespace(): string;

    /**
     * The placeholders an editor may offer (names without the namespace). Names outside this list may still resolve.
     *
     * @return list<PlaceholderDefinition>
     */
    public function definitions(): array;

    /**
     * Values for some names of this namespace (each name asked once per render). Leave a name out when it has no
     * value or the actor may not see it: it stays as literal text. Exceptions propagate to the caller of the render.
     *
     * @param list<string> $names names without the namespace
     *
     * @return array<string, string> name => plain-text value
     */
    public function resolve(array $names, PlaceholderContext $context): array;
}
