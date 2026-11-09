<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\Brand\Contract\Brand;
use Nowo\Brand\Contract\BrandAsset;
use Nowo\Brand\Contract\BrandAssetOrigin;
use Nowo\Brand\Contract\BrandColors;

use function crc32;
use function hash;
use function pack;
use function strlen;

/**
 * Brand values and image bytes of the spec 220 S7 tests. The PNG bytes are a valid header (signature + IHDR with
 * CRC) followed by an empty IDAT and IEND: enough for `getimagesizefromstring()`, no image library needed.
 */
final class BrandFixtures
{
    public const LIGHT = [
        'primary'     => '#1f6f54',
        'primaryDeep' => '#134736',
        'danger'      => '#b42318',
        'warning'     => '#b54708',
        'paper'       => '#f3f6f4',
        'text'        => '#0f1c18',
        'surface'     => '#ffffff',
    ];

    public const DARK = [
        'primary'     => '#4aad7f',
        'primaryDeep' => '#6bc49a',
        'danger'      => '#f97066',
        'warning'     => '#fdb022',
        'paper'       => '#0c1210',
        'text'        => '#e6eee9',
        'surface'     => '#151c19',
    ];

    /** A small vector image, the kind of file that must never be attached to an e-mail. */
    public const SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';

    /**
     * @param array<string, BrandAsset> $assets
     */
    public static function brand(array $assets = [], string $name = 'Acme', bool $isDefault = false): Brand
    {
        return new Brand($name, BrandColors::fromArray(self::LIGHT), BrandColors::fromArray(self::DARK), $assets, isDefault: $isDefault);
    }

    public static function asset(
        string $slot,
        int $width = 200,
        int $height = 50,
        string $mimeType = 'image/png',
        BrandAssetOrigin $origin = BrandAssetOrigin::Uploaded,
        string $url = '/_settings-file/brand/logo.png',
    ): BrandAsset {
        return new BrandAsset($slot, $url, $mimeType, $width, $height, hash('sha256', $slot . $width . $height), $origin);
    }

    /**
     * PNG header bytes of a `$width` × `$height` image, padded with `$padding` bytes after IEND.
     */
    public static function png(int $width = 200, int $height = 50, int $padding = 0): string
    {
        $ihdr = pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', $ihdr)
            . self::chunk('IDAT', '')
            . self::chunk('IEND', '')
            . ($padding > 0 ? str_repeat("\0", $padding) : '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
