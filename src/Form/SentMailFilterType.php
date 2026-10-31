<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\MailKitBundle\Log\SentMailFilters;
use Nowo\MailKitBundle\Log\SentMailStatus;
use Nowo\MailKitBundle\NowoMailKitBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_keys;

/**
 * GET filters of the sent-mail log (REQ-TWIG-005 rules 6 and 11): search, status, period. No CSRF (idempotent,
 * bookmarkable); empty block prefix so the query keys stay `q`, `status`, `period`.
 *
 * @extends AbstractType<array<string, string>>
 */
#[FormKitConfig(NowoMailKitBundle::FORM_KIT_PROFILE)]
final class SentMailFilterType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Fields `q`, `status`, `period`.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $statuses = [];
        foreach (SentMailStatus::cases() as $status) {
            $statuses['log.status.' . $status->value] = $status->value;
        }
        $periods = [];
        foreach (array_keys(SentMailFilters::PERIODS) as $period) {
            $periods['log.filter.period.' . $period] = $period;
        }

        $this->addWithDefaults($builder, 'q', SearchType::class, [
            'label'    => 'log.filter.q',
            'required' => false,
            'attr'     => ['maxlength' => SentMailFilters::QUERY_MAX, 'autocomplete' => 'off'],
        ]);
        $this->addWithDefaults($builder, 'status', ChoiceType::class, [
            'label'       => 'log.filter.status',
            'required'    => false,
            'choices'     => $statuses,
            'placeholder' => 'log.filter.status_any',
        ]);
        $this->addWithDefaults($builder, 'period', ChoiceType::class, [
            'label'    => 'log.filter.period.label',
            'required' => true,
            'choices'  => $periods,
        ]);
    }

    /**
     * GET, no CSRF, bundle translation domain.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'GET',
            'csrf_protection'    => false,
            'data_class'         => null,
            'allow_extra_fields' => true,
            'translation_domain' => NowoMailKitBundle::TRANSLATION_DOMAIN,
        ]);
    }

    /**
     * Empty prefix: flat query keys.
     */
    public function getBlockPrefix(): string
    {
        return '';
    }
}
