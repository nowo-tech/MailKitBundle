<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Event;

use Nowo\MailKitBundle\Template\Service\TemplateLibraryInterface;
use Nowo\MailKitBundle\Template\Value\TemplateKind;
use Nowo\MailKitBundle\Template\Value\TemplateScope;

/**
 * A template or snippet was deleted (2.7.0): by {@see TemplateLibraryInterface::delete()}
 * or by a GDPR erasure of its owner. Dispatched synchronously right after the statement: inside an open host
 * transaction the listener's own writes join it (a host side table keyed by the template id, such as CrmKit's consent
 * purpose, is cleaned up in the same transaction).
 */
final readonly class TemplateDeletedEvent
{
    /**
     * @param string $templateId the deleted id
     * @param string $tenant its tenant reference
     */
    public function __construct(
        public string $templateId,
        public string $tenant,
        public TemplateKind $kind,
        public TemplateScope $scope,
    ) {
    }
}
