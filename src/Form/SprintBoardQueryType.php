<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Form;

use App\Sprint\BoardFilter;
use App\Sprint\IssueFilterChoices;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The filters of a sprint board, edited as {@see BoardFilter} and stored as the YAML query of the board.
 *
 * @extends AbstractType<BoardFilter>
 */
class SprintBoardQueryType extends AbstractType
{
    private const array FILTERS = [
        'trackers' => ['label' => 'Trackers', 'expanded' => true],
        'categories' => ['label' => 'Categories', 'expanded' => false],
        'fixedVersions' => ['label' => 'Target versions', 'expanded' => false],
        'focuses' => ['label' => 'Focus', 'expanded' => false],
        'tags' => ['label' => 'Tags', 'expanded' => false],
        'typo3Versions' => ['label' => 'TYPO3 versions', 'expanded' => false],
        'statuses' => ['label' => 'Statuses', 'expanded' => true],
    ];

    private const array CLOSED_VISIBLE_DAYS = [
        'Hide them right away' => 0,
        'Show them for 7 days' => 7,
        'Show them for 14 days' => 14,
        'Show them for 30 days' => 30,
        'Show them for 90 days' => 90,
        'Always show them' => BoardFilter::CLOSED_ALWAYS_VISIBLE,
    ];

    public function __construct(private readonly IssueFilterChoices $issueFilterChoices)
    {
    }

    /**
     * @template T
     *
     * @param FormBuilderInterface<T> $builder
     * @param array<mixed>            $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addFields($builder, new BoardFilter());

        // Values the index no longer holds, e.g. of a released version, stay selectable on boards using them
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $filter = BoardFilter::fromYaml($event->getData());
            if (null !== $filter) {
                $this->addFields($event->getForm(), $filter);
            }
        });

        $builder->addModelTransformer(new CallbackTransformer(
            static function (?string $yaml): BoardFilter {
                return BoardFilter::fromYaml($yaml)
                    ?? throw new TransformationFailedException('The query of this board was written by hand.');
            },
            static function (?BoardFilter $filter) use ($options): string {
                if (true === $options['allow_empty'] && (null === $filter || !$filter->hasFilter())) {
                    return '';
                }
                if (null === $filter || !$filter->hasFilter()) {
                    $exception = new TransformationFailedException('A board needs at least one filter.');
                    $exception->setInvalidMessage('Choose at least one tracker, category, version, focus or tag, otherwise the board shows every issue.');

                    throw $exception;
                }

                return $filter->toYaml();
            }
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BoardFilter::class,
            'empty_data' => static fn (): BoardFilter => new BoardFilter(),
            'label' => false,
            // Whether no filter at all is fine: it gives no query then, see ReviewBoardFilter::$issueQuery
            'allow_empty' => false,
        ]);
        $resolver->setAllowedTypes('allow_empty', 'bool');
    }

    /**
     * @param FormBuilderInterface<mixed>|FormInterface<mixed> $form
     */
    private function addFields(FormBuilderInterface|FormInterface $form, BoardFilter $filter): void
    {
        foreach (self::FILTERS as $property => $config) {
            $form->add($property, ChoiceType::class, [
                'label' => $config['label'],
                'choices' => $this->choicesOf(
                    BoardFilter::FIELDS[$property],
                    $filter->{$property},
                    $config['expanded'],
                ),
                'multiple' => true,
                'expanded' => $config['expanded'],
                'required' => false,
                'attr' => $config['expanded'] ? [] : ['class' => 'form-control', 'size' => 8, 'data-filter-select' => ''],
            ]);
        }
        $form->add('excludedTrackers', ChoiceType::class, [
            'label' => 'Leave out these trackers',
            'help' => 'For example epics, which only group the issues of a board.',
            'choices' => $this->choicesOf('tracker.name', $filter->excludedTrackers, true),
            'multiple' => true,
            'expanded' => true,
            'required' => false,
        ]);

        $form->add('excludedStatuses', ChoiceType::class, [
            'label' => 'Leave out these statuses',
            'help' => 'For example "Closed" and "Rejected", to keep "Resolved" on the board.',
            'choices' => $this->choicesOf('status.name', $filter->excludedStatuses, true),
            'multiple' => true,
            'expanded' => true,
            'required' => false,
        ]);

        $days = self::CLOSED_VISIBLE_DAYS;
        if (!in_array($filter->closedVisibleDays, $days, true)) {
            $days['Show them for ' . $filter->closedVisibleDays . ' days'] = $filter->closedVisibleDays;
            asort($days);
        }
        $form->add('closedVisibleDays', ChoiceType::class, [
            'label' => 'Closed, resolved and rejected issues',
            'help' => 'Counted from their last update.',
            'choices' => $days,
            'attr' => ['class' => 'form-control'],
        ]);
    }

    /**
     * The values of a filter. A list to search names how many issues of the index each value has. A chip does
     * not: the board has fewer, like the closed issues of a board that shows them for some days only.
     *
     * @param list<string> $selected
     *
     * @return array<string, string>
     */
    private function choicesOf(string $field, array $selected, bool $asChips): array
    {
        $choices = [];
        foreach ($this->issueFilterChoices->valuesOf($field) as $value => $count) {
            $choices[$asChips ? (string) $value : $value . ' (' . $count . ')'] = (string) $value;
        }
        foreach ($selected as $value) {
            if (!in_array($value, $choices, true)) {
                $choices[$value . ' (no issues)'] = $value;
            }
        }

        return $choices;
    }
}
