<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Exception;

use RuntimeException;

/**
 * Another row of the same tenant, kind, scope, owner (personal) and locale has that key (2.7.0; caught unique key,
 * also when two people save at the same time). Nothing was written.
 */
final class TemplateKeyTaken extends RuntimeException implements TemplateException
{
    /**
     * Fixed message.
     */
    public function __construct()
    {
        parent::__construct('A template with this key and locale exists already.');
    }
}
