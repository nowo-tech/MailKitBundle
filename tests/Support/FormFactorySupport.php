<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\MailKitBundle\DependencyInjection\NowoMailKitExtension;
use Nowo\MailKitBundle\Form\SentMailFilterType;
use Nowo\MailKitBundle\NowoMailKitBundle;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\PreloadedExtension;

/**
 * Form factory with the bundle FormKit types (merger injected), without a kernel.
 */
final class FormFactorySupport
{
    public static function merger(): FormOptionsMerger
    {
        $profile = NowoMailKitExtension::formKitProfile();
        unset($profile['alias']);
        /** @var array<string, array{translation_domain: string, defaults: array{attr: array<string, mixed>, row_attr: array<string, mixed>}, field_types: array<string, array<string, mixed>>}> $profiles */
        $profiles = [NowoMailKitBundle::FORM_KIT_PROFILE => $profile + ['by_form' => []]];

        return new FormOptionsMerger($profiles, NowoMailKitBundle::FORM_KIT_PROFILE, new ConstraintDefinitionFactory());
    }

    public static function factory(): FormFactoryInterface
    {
        $type = new SentMailFilterType();
        $type->setFormOptionsMerger(self::merger());

        return Forms::createFormFactoryBuilder()
            ->addExtension(new PreloadedExtension([$type], []))
            ->addExtension(new HttpFoundationExtension())
            ->getFormFactory();
    }
}
