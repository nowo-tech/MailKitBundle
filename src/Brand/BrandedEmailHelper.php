<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Brand;

use Nowo\Brand\Contract\Brand;
use Nowo\Brand\Contract\BrandAsset;
use Nowo\Brand\Contract\BrandAssetDataUri;
use Nowo\Brand\Contract\BrandAssetReaderInterface;
use Nowo\Brand\Contract\BrandAssetSlot;
use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\Brand\Contract\ColorMath;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Email;
use Throwable;

use function bin2hex;
use function getimagesizefromstring;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function max;
use function min;
use function preg_match;
use function preg_quote;
use function random_bytes;
use function round;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

use const IMAGETYPE_JPEG;
use const IMAGETYPE_PNG;
use const IMAGETYPE_WEBP;

/**
 * Brand of the optional branded e-mail layout (spec 220 S7, MailKit 1.4): brand name, colours and logo from a
 * `nowo-tech/brand-contracts` {@see BrandProviderInterface}, with the logo attached as an inline CID part read through
 * {@see BrandAssetReaderInterface} (owner decision Q-O6).
 *
 * - {@see self::apply()} puts the brand into a {@see TemplatedEmail} whose templates extend
 *   `@NowoMailKit/email/branded_layout.html.twig` (and `.txt.twig`): the `nowo_mail_brand` context entry plus the
 *   `logo_light` image (or the `mark` when there is no logo) as a CID part.
 * - {@see self::embed()} attaches one allowed slot (`logo_light`, `mark`, `extra.email_*`) and returns its `cid:`
 *   reference for a host template.
 * - {@see self::view()} is the sanitised view the layout prints (Twig `nowo_mail_kit_brand()`).
 *
 * Images are attached only when the bytes the reader returns are PNG, JPEG or WebP (magic bytes and the image header
 * agreeing), within the size limits; SVG and every other type are refused whatever the asset's origin, and nothing is
 * ever fetched from the asset URL. Colours come only from the validated `BrandColors` (or the configured `#rrggbb`
 * fallback) and are checked again before printing. Without a provider, with `brand: off`, or when the provider fails,
 * {@see self::apply()} and {@see self::embed()} leave the e-mail untouched; a reader failure means no logo. Nothing
 * here throws because of the brand, so a send never fails because of it.
 *
 * Stateless: the provider is asked on every call and nothing is kept between messages, so a long-running worker
 * (FrankenPHP, Messenger) keeps no brand, bytes or e-mail between requests.
 */
final readonly class BrandedEmailHelper
{
    /** Context entry of {@see TemplatedEmail} read by the layout. */
    public const CONTEXT_KEY = 'nowo_mail_brand';

    /** Prefix of the names of the parts this helper attaches. */
    public const PART_PREFIX = 'nowo-brand-';

    /** Prefix of the extra image slots that may be embedded (`extra.email_mascot`, ...); a literal, so the class loads without brand-contracts. */
    public const EXTRA_EMAIL_PREFIX = 'extra.email_';

    /** Display height of the header logo, in CSS pixels. */
    public const LOGO_HEIGHT = 40;

    /** Widest header logo, in CSS pixels. */
    public const LOGO_MAX_WIDTH = 240;

    /** Display side of the mark, in CSS pixels. */
    public const MARK_SIZE = 40;

    /** Widest extra image (host templates), in CSS pixels. */
    public const EXTRA_MAX_WIDTH = 600;

    /** Colour keys of the view, in order. */
    public const COLOR_KEYS = ['primary', 'primary_contrast', 'text', 'muted', 'paper', 'surface', 'border'];

    /** Fallback colours (the UiKit `nowo-ui.css` defaults). */
    public const DEFAULT_COLORS = [
        'primary'          => '#2563eb',
        'primary_contrast' => '#ffffff',
        'text'             => '#0f172a',
        'muted'            => '#64748b',
        'paper'            => '#f8fafc',
        'surface'          => '#ffffff',
        'border'           => '#e2e8f0',
    ];

    /** Default per-image byte limit. */
    public const DEFAULT_MAX_IMAGE_BYTES = 524_288;

    /** Default limit of every brand part of one e-mail together. */
    public const DEFAULT_MAX_TOTAL_BYTES = 1_048_576;

    /** Default longest image side, in pixels. */
    public const DEFAULT_MAX_IMAGE_SIDE = 4096;

    private const HEX_PATTERN = '/^#[0-9a-f]{6}\z/';
    private const SRC_PATTERN = '/^cid:nowo-brand-[a-z0-9-]{1,96}\.(?:png|jpg|webp)\z/';

    private const IMAGE_TYPES = [
        'image/png'  => [IMAGETYPE_PNG, 'png'],
        'image/jpeg' => [IMAGETYPE_JPEG, 'jpg'],
        'image/webp' => [IMAGETYPE_WEBP, 'webp'],
    ];

    /** @var array<string, string> */
    private array $defaultColors;

    /**
     * @param string|null $defaultName `brand.name`: the name printed without a provider (null: none)
     * @param array<string, string> $defaultColors `brand.colors` (`#rrggbb`, keys of {@see self::COLOR_KEYS})
     * @param BrandProviderInterface|null $provider the brand (null without brand-contracts, a provider or with `brand: off`)
     * @param BrandAssetReaderInterface|null $reader the bytes of brand images (null: no images)
     * @param int $maxImageBytes `brand.max_image_bytes`
     * @param int $maxTotalBytes `brand.max_total_bytes`
     * @param int $maxImageSide `brand.max_image_side`
     */
    public function __construct(
        private ?string $defaultName = null,
        array $defaultColors = self::DEFAULT_COLORS,
        private ?BrandProviderInterface $provider = null,
        private ?BrandAssetReaderInterface $reader = null,
        private int $maxImageBytes = self::DEFAULT_MAX_IMAGE_BYTES,
        private int $maxTotalBytes = self::DEFAULT_MAX_TOTAL_BYTES,
        private int $maxImageSide = self::DEFAULT_MAX_IMAGE_SIDE,
        private ?LoggerInterface $logger = null,
    ) {
        $colors = [];
        foreach (self::COLOR_KEYS as $key) {
            $value        = strtolower($defaultColors[$key] ?? '');
            $colors[$key] = preg_match(self::HEX_PATTERN, $value) === 1 ? $value : self::DEFAULT_COLORS[$key];
        }
        $this->defaultColors = $colors;
    }

    /**
     * The current brand, or null without a provider or when the provider fails (logged).
     */
    public function brand(): ?Brand
    {
        if (!$this->provider instanceof BrandProviderInterface) {
            return null;
        }

        try {
            return $this->provider->getBrand();
        } catch (Throwable $e) {
            $this->logger?->warning('MailKitBundle: the brand provider failed; the e-mail keeps the configured brand.', ['exception' => $e]);

            return null;
        }
    }

    /**
     * Puts the brand into `$email` for the branded layout: the `nowo_mail_brand` context entry (name, colours, logo)
     * and the `logo_light` image, or the `mark` when there is no usable logo, as an inline CID part. Without a brand
     * the e-mail is returned untouched (the layout then prints the configured fallback).
     */
    public function apply(TemplatedEmail $email): TemplatedEmail
    {
        $brand = $this->brand();
        if (!$brand instanceof Brand) {
            return $email;
        }

        try {
            $view = $this->brandView($brand);
            $logo = $this->image($email, $brand, BrandAssetSlot::LOGO_LIGHT);
            $mark = $logo instanceof BrandedEmailImage ? null : $this->image($email, $brand, BrandAssetSlot::MARK);
        } catch (Throwable $e) {
            $this->logger?->warning('MailKitBundle: the brand could not be applied; the e-mail keeps the configured brand.', ['exception' => $e]);

            return $email;
        }

        foreach ([$logo, $mark] as $image) {
            if ($image instanceof BrandedEmailImage) {
                $this->attach($email, $image);
            }
        }
        $view['logo'] = $logo?->toView();
        $view['mark'] = $mark?->toView();

        return $email->context([self::CONTEXT_KEY => $view] + $email->getContext());
    }

    /**
     * Attaches the brand image of `$slot` (`logo_light`, `mark` or `extra.email_<id>`) to `$email` as an inline part
     * and returns its `cid:` reference for an `<img src>`, or null when there is no brand, the slot is not allowed or
     * has no image, or the image is unavailable or refused (logged). Attaching the same slot twice reuses the part.
     */
    public function embed(Email $email, string $slot): ?string
    {
        $brand = $this->brand();
        if (!$brand instanceof Brand) {
            return null;
        }

        try {
            $image = $this->image($email, $brand, $slot);
        } catch (Throwable $e) {
            $this->logger?->warning('MailKitBundle: a brand image could not be embedded.', ['slot' => $slot, 'exception' => $e]);

            return null;
        }
        if (!$image instanceof BrandedEmailImage) {
            return null;
        }
        $this->attach($email, $image);

        return $image->src();
    }

    /**
     * The view the layout prints: `$given` (the `nowo_mail_brand` context entry) checked again, or the configured
     * fallback when it is not an array. Every colour is `#rrggbb` (anything else becomes the fallback colour), the
     * name follows {@see BrandedEmailText}, and an image is kept only as a `cid:nowo-brand-…` reference with integer
     * dimensions.
     *
     * @return array{name: string|null, colors: array<string, string>, logo: array{src: string, width: int, height: int}|null, mark: array{src: string, width: int, height: int}|null}
     */
    public function view(mixed $given = null): array
    {
        if (!is_array($given)) {
            return $this->defaultView();
        }

        $colors = [];
        $source = is_array($given['colors'] ?? null) ? $given['colors'] : [];
        foreach (self::COLOR_KEYS as $key) {
            $value        = $source[$key] ?? null;
            $colors[$key] = is_string($value) && preg_match(self::HEX_PATTERN, $value) === 1 ? $value : $this->defaultColors[$key];
        }

        $name = $given['name'] ?? null;

        return [
            'name'   => BrandedEmailText::isSafeName($name) ? BrandedEmailText::plain($name) : $this->defaultName(),
            'colors' => $colors,
            'logo'   => $this->imageView($given['logo'] ?? null, self::LOGO_MAX_WIDTH, self::LOGO_HEIGHT),
            'mark'   => $this->imageView($given['mark'] ?? null, self::MARK_SIZE, self::MARK_SIZE),
        ];
    }

    /**
     * @return array{name: string|null, colors: array<string, string>, logo: null, mark: null}
     */
    private function defaultView(): array
    {
        return ['name' => $this->defaultName(), 'colors' => $this->defaultColors, 'logo' => null, 'mark' => null];
    }

    private function defaultName(): ?string
    {
        return BrandedEmailText::isSafeName($this->defaultName) ? BrandedEmailText::plain($this->defaultName) : null;
    }

    /**
     * @return array{name: string|null, colors: array<string, string>, logo: null, mark: null}
     */
    private function brandView(Brand $brand): array
    {
        $c = $brand->light;

        return [
            'name'   => BrandedEmailText::plain($brand->name),
            'colors' => [
                'primary'          => $c->primary,
                'primary_contrast' => $c->primaryContrast(),
                'text'             => $c->text,
                'muted'            => $c->mix('text', 60, 'surface'),
                'paper'            => $c->paper,
                'surface'          => $c->surface,
                'border'           => $c->mix('text', ColorMath::DEFAULT_BORDER_PERCENT, 'paper'),
            ],
            'logo' => null,
            'mark' => null,
        ];
    }

    /**
     * @return array{src: string, width: int, height: int}|null
     */
    private function imageView(mixed $image, int $maxWidth, int $maxHeight): ?array
    {
        if (!is_array($image)) {
            return null;
        }
        $src    = $image['src'] ?? null;
        $width  = $image['width'] ?? null;
        $height = $image['height'] ?? null;
        if (!is_string($src) || preg_match(self::SRC_PATTERN, $src) !== 1 || !is_int($width) || !is_int($height)
            || $width < 1 || $height < 1 || $width > $maxWidth || $height > $maxHeight) {
            return null;
        }

        return ['src' => $src, 'width' => $width, 'height' => $height];
    }

    /**
     * The checked image of `$slot`, or null (see {@see self::embed()}).
     */
    private function image(Email $email, Brand $brand, string $slot): ?BrandedEmailImage
    {
        if (!$this->isAllowedSlot($slot)) {
            $this->logger?->notice('MailKitBundle: brand slot not allowed in e-mails.', ['slot' => $slot]);

            return null;
        }
        if (!$this->reader instanceof BrandAssetReaderInterface) {
            return null;
        }
        $asset = $brand->asset($slot);
        if (!$asset instanceof BrandAsset) {
            return null;
        }

        $context = ['slot' => $slot, 'asset_slot' => $asset->slot, 'sha256' => substr($asset->sha256, 0, 12)];
        $base    = self::PART_PREFIX . str_replace(['.', '_'], '-', $slot);
        foreach ($email->getAttachments() as $part) {
            $filename = $part->getFilename();
            if ($part instanceof BrandedEmailPart && is_string($filename) && preg_match('/^' . preg_quote($base, '/') . '(?:-[0-9a-f]{12})?\.(?:png|jpg|webp)\z/', $filename) === 1) {
                return $this->checked($filename, $part->getBody(), $slot, $context, false);
            }
        }

        try {
            $bytes = $this->reader->read($asset);
        } catch (Throwable $e) {
            $this->logger?->warning('MailKitBundle: the brand image could not be read; the e-mail is sent without it.', $context + ['exception' => $e]);

            return null;
        }

        $size = strlen($bytes);
        if ($size === 0 || $size > $this->maxImageBytes) {
            $this->logger?->warning('MailKitBundle: the brand image is empty or larger than brand.max_image_bytes; not attached.', $context + ['bytes' => $size]);

            return null;
        }
        $total = $this->attachedBrandBytes($email) + $size;
        if ($total > $this->maxTotalBytes) {
            $this->logger?->warning('MailKitBundle: the brand images would exceed brand.max_total_bytes; not attached.', $context + ['bytes' => $total]);

            return null;
        }

        $type = BrandAssetDataUri::sniffRaster($bytes);
        if ($type === null) {
            $this->logger?->warning('MailKitBundle: the brand image is not PNG, JPEG or WebP; not attached (SVG and other types are never sent).', $context);

            return null;
        }

        return $this->checked($this->partName($email, $base, self::IMAGE_TYPES[$type][1]), $bytes, $slot, $context, true);
    }

    /**
     * @param array<string, mixed> $context log context
     * @param bool $log whether to log a refusal (a part already attached was checked when it was added)
     */
    private function checked(string $name, string $bytes, string $slot, array $context, bool $log): ?BrandedEmailImage
    {
        $type = BrandAssetDataUri::sniffRaster($bytes);
        $info = $type === null ? false : @getimagesizefromstring($bytes);
        if ($type === null || !is_array($info) || $info[2] !== self::IMAGE_TYPES[$type][0]
            || $info[0] < 1 || $info[1] < 1 || $info[0] > $this->maxImageSide || $info[1] > $this->maxImageSide) {
            if ($log) {
                $this->logger?->warning('MailKitBundle: the brand image header does not match its type or exceeds brand.max_image_side; not attached.', $context);
            }

            return null;
        }

        [$width, $height] = $this->displaySize($slot, $info[0], $info[1]);

        return new BrandedEmailImage($name, $type, $bytes, $width, $height);
    }

    /**
     * Display size: the logo at {@see self::LOGO_HEIGHT} high, at most {@see self::LOGO_MAX_WIDTH} wide; the mark in
     * a {@see self::MARK_SIZE} square; extra images at their size, at most {@see self::EXTRA_MAX_WIDTH} on either side.
     *
     * @return array{int, int}
     */
    private function displaySize(string $slot, int $width, int $height): array
    {
        $scale = match ($slot) {
            BrandAssetSlot::LOGO_LIGHT => min(self::LOGO_MAX_WIDTH / $width, self::LOGO_HEIGHT / $height),
            BrandAssetSlot::MARK       => min(self::MARK_SIZE / $width, self::MARK_SIZE / $height),
            default                    => min(1.0, self::EXTRA_MAX_WIDTH / $width, self::EXTRA_MAX_WIDTH / $height),
        };

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    private function isAllowedSlot(string $slot): bool
    {
        if (in_array($slot, [BrandAssetSlot::LOGO_LIGHT, BrandAssetSlot::MARK], true)) {
            return true;
        }

        return str_starts_with($slot, self::EXTRA_EMAIL_PREFIX) && BrandAssetSlot::isExtra($slot);
    }

    /**
     * `<base>.<ext>`, or `<base>-<12 random hex>.<ext>` when the application already attached a part with that name:
     * Symfony resolves a `cid:` reference to the first part with the name, so the brand part must not share it.
     */
    private function partName(Email $email, string $base, string $extension): string
    {
        $name = $base . '.' . $extension;
        foreach ($email->getAttachments() as $part) {
            if ($part->getFilename() === $name) {
                return $base . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
            }
        }

        return $name;
    }

    private function attachedBrandBytes(Email $email): int
    {
        $total = 0;
        foreach ($email->getAttachments() as $part) {
            if ($part instanceof BrandedEmailPart) {
                $total += strlen($part->getBody());
            }
        }

        return $total;
    }

    private function attach(Email $email, BrandedEmailImage $image): void
    {
        foreach ($email->getAttachments() as $part) {
            if ($part instanceof BrandedEmailPart && $part->getFilename() === $image->name) {
                return;
            }
        }
        $email->addPart((new BrandedEmailPart($image->bytes, $image->name, $image->mimeType))->asInline());
    }
}
