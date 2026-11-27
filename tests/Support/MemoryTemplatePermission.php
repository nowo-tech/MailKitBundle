<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\MailKitBundle\Template\Port\TemplatePermissionInterface;
use Nowo\MailKitBundle\Template\Value\TemplateActor;
use Nowo\MailKitBundle\Template\Value\TemplatePermission;

use function array_values;
use function in_array;

/**
 * Test permission port: grants per owner reference (`*` = everyone).
 */
final class MemoryTemplatePermission implements TemplatePermissionInterface
{
    /** @var array<string, list<TemplatePermission>> */
    public array $grants = [];

    /** @var list<string> */
    public array $asked = [];

    public function grant(string $owner, TemplatePermission ...$permissions): self
    {
        $this->grants[$owner] = array_values($permissions);

        return $this;
    }

    public function isGranted(TemplatePermission $permission, TemplateActor $actor): bool
    {
        $this->asked[] = $permission->value . ':' . $actor->tenant . '/' . $actor->owner;

        return in_array($permission, $this->grants[$actor->owner] ?? $this->grants['*'] ?? [], true);
    }
}
