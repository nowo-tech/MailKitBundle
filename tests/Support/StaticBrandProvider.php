<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\Brand\Contract\Brand;
use Nowo\Brand\Contract\BrandProviderInterface;
use RuntimeException;

/**
 * Test brand provider: returns {@see self::$brand} (settable between sends) or throws when it is null.
 */
final class StaticBrandProvider implements BrandProviderInterface
{
    public int $calls = 0;

    public function __construct(
        public ?Brand $brand = null,
    ) {
    }

    public function getBrand(): Brand
    {
        ++$this->calls;

        return $this->brand ?? throw new RuntimeException('Brand store unavailable (test).');
    }
}
