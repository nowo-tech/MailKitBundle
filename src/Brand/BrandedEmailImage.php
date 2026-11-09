<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Brand;

/**
 * One brand image attached to an e-mail as an inline (CID) part, with its display size in the layout.
 */
final readonly class BrandedEmailImage
{
    /**
     * @param string $name part name, also the CID reference after `cid:` (`nowo-brand-logo-light.png`)
     * @param string $mimeType sniffed raster type (`image/png`, `image/jpeg`, `image/webp`)
     * @param string $bytes the image bytes
     * @param int $width display width in CSS pixels
     * @param int $height display height in CSS pixels
     */
    public function __construct(
        public string $name,
        public string $mimeType,
        public string $bytes,
        public int $width,
        public int $height,
    ) {
    }

    /**
     * The `src` value of the `<img>` tag: `cid:<name>` (Symfony Mime rewrites it to the part's Content-ID).
     */
    public function src(): string
    {
        return 'cid:' . $this->name;
    }

    /**
     * The view of the layout: `src`, `width`, `height`.
     *
     * @return array{src: string, width: int, height: int}
     */
    public function toView(): array
    {
        return ['src' => $this->src(), 'width' => $this->width, 'height' => $this->height];
    }
}
