<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\Brand\Contract\BrandAsset;
use Nowo\Brand\Contract\BrandAssetReaderInterface;
use Nowo\Brand\Contract\BrandAssetReadException;

/**
 * Test asset reader: bytes per slot; a missing slot throws {@see BrandAssetReadException}. Records what it was asked.
 */
final class MemoryBrandAssetReader implements BrandAssetReaderInterface
{
    /** @var list<array{slot: string, url: string, variant: int|null}> */
    public array $reads = [];

    /**
     * @param array<string, string> $bytes
     */
    public function __construct(
        public array $bytes = [],
    ) {
    }

    public function read(BrandAsset $asset, ?int $variant = null): string
    {
        $this->reads[] = ['slot' => $asset->slot, 'url' => $asset->url, 'variant' => $variant];

        return $this->bytes[$asset->slot] ?? throw new BrandAssetReadException('Brand asset "' . $asset->slot . '" unavailable (test).');
    }
}
