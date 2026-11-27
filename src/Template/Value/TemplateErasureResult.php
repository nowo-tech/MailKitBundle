<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Value;

/**
 * Outcome of a GDPR erasure of one owner reference (2.7.0).
 */
final readonly class TemplateErasureResult
{
    /**
     * @param int $deleted personal templates and snippets deleted
     * @param int $anonymised shared rows whose author or last author was cleared
     */
    public function __construct(
        public int $deleted,
        public int $anonymised,
    ) {
    }
}
