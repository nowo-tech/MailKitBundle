<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\MailKitBundle\Template\Placeholder\PlaceholderContext;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderDefinition;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderProviderInterface;

/**
 * Test provider `contact.*`: values by the `contact` subject reference, only for the contact's tenant; it returns
 * hostile values on purpose (markup, a placeholder look-alike, control characters, a non-string).
 */
final class ContactPlaceholderProvider implements PlaceholderProviderInterface
{
    public static function placeholderNamespace(): string
    {
        return 'contact';
    }

    public function definitions(): array
    {
        return [new PlaceholderDefinition('first_name', 'First name')];
    }

    public function resolve(array $names, PlaceholderContext $context): array
    {
        if ($context->subject('contact') !== '42' || $context->actor->tenant !== 't1') {
            return [];
        }

        // @phpstan-ignore return.type (a hostile provider: the non-string value must be ignored)
        return [
            'first_name' => "<script>alert(1)</script>\u{202E}Pedro",
            'echo'       => '{{ me.first_name }}',
            'note'       => "line1\r\nline2\x07\tend",
            'invalid'    => "\xC3\x28",
            'number'     => 42,
            'unasked'    => 'never used',
        ];
    }
}
