<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Event;

use Nowo\MailKitBundle\Template\Service\TemplateLibraryInterface;
use Nowo\MailKitBundle\Template\Value\TemplateKind;
use Nowo\MailKitBundle\Template\Value\TemplateScope;

/**
 * A template or snippet was deleted (2.7.0): by {@see TemplateLibraryInterface::delete()}
 * or by a GDPR erasure of its owner. Dispatched synchronously inside the transaction of the delete (a transaction-doctrine
 * unit on the template connection, a savepoint inside an open host transaction): a listener's writes on the same
 * connection commit with the delete, and a listener that throws rolls the delete back (the caller gets the exception
 * and a retry dispatches the event again).
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
