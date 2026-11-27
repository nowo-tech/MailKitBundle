<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Value;

/**
 * What a stored row is (2.7.0): a template (subject + body) or a snippet (a reusable body fragment, no subject).
 */
enum TemplateKind: string
{
    case Template = 'template';
    case Snippet  = 'snippet';
}
