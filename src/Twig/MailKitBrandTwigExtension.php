<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Twig;

use Nowo\MailKitBundle\Brand\BrandedEmailHelper;
use Nowo\MailKitBundle\Brand\BrandedEmailText;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig helpers of the branded e-mail layout (spec 220 S7): `nowo_mail_kit_brand(given = null)` (the sanitised brand
 * view, {@see BrandedEmailHelper::view()}) and the `nowo_mail_kit_plain_text` filter ({@see BrandedEmailText::plain()},
 * for the plain-text part). Neither output is marked safe: HTML templates keep auto-escaping.
 */
final class MailKitBrandTwigExtension extends AbstractExtension
{
    /**
     * @param BrandedEmailHelper $helper the brand helper
     */
    public function __construct(
        private readonly BrandedEmailHelper $helper,
    ) {
    }

    /**
     * `nowo_mail_kit_brand()`.
     *
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [new TwigFunction('nowo_mail_kit_brand', $this->helper->view(...))];
    }

    /**
     * `nowo_mail_kit_plain_text`.
     *
     * @return list<TwigFilter>
     */
    public function getFilters(): array
    {
        return [new TwigFilter('nowo_mail_kit_plain_text', BrandedEmailText::plain(...))];
    }
}
