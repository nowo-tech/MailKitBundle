<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Exception;

use Throwable;

/**
 * Marker of the template store failures (2.7.0). Messages are fixed text plus a field name: never template content,
 * a placeholder value or another person's reference.
 */
interface TemplateException extends Throwable
{
}
