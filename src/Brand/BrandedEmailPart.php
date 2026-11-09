<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Brand;

use Symfony\Component\Mime\Part\DataPart;

/**
 * An inline image part attached by {@see BrandedEmailHelper}. The helper reuses and counts only parts of this class,
 * never an attachment the application added itself, whatever its name (audit IW-L2). The class survives Messenger
 * serialisation, so a queued e-mail keeps the distinction.
 */
final class BrandedEmailPart extends DataPart
{
}
