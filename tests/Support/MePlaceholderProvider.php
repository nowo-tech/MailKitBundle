<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\MailKitBundle\Template\Placeholder\PlaceholderContext;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderDefinition;
use Nowo\MailKitBundle\Template\Placeholder\PlaceholderProviderInterface;

/**
 * Test provider `me.*`: the actor's own profile from a map keyed by owner reference.
 */
final class MePlaceholderProvider implements PlaceholderProviderInterface
{
    /** @var array<string, array<string, string>> */
    public array $people = [
        'ana' => ['first_name' => 'Ana', 'company' => 'Acme & <Sons>'],
        'bob' => ['first_name' => 'Bob', 'company' => 'Bobco'],
    ];

    /** @var list<list<string>> */
    public array $calls = [];

    public static function placeholderNamespace(): string
    {
        return 'me';
    }

    public function definitions(): array
    {
        return [new PlaceholderDefinition('first_name', 'First name'), new PlaceholderDefinition('company', 'Company')];
    }

    public function resolve(array $names, PlaceholderContext $context): array
    {
        $this->calls[] = $names;

        return $this->people[$context->actor->owner] ?? [];
    }
}
