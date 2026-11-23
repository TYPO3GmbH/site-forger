<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Form;

use App\Enum\ActivityEnum;
use App\Enum\ReviewSizeEnum;
use App\Sprint\BoardMembers;
use App\Sprint\ReviewBoardFilter;
use App\Sprint\ReviewFilterChoices;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The filters of a sprint board of reviews, in its form, see ReviewBoardFilter: the code the changes touch, their
 * tags, branches, releases, sizes, topics and owners, how long ago they were updated, whether Gerrit can merge
 * them, whether work in progress shows, and the filters of the issues they name.
 *
 * @extends AbstractType<ReviewBoardFilter>
 */
class ReviewBoardFilterType extends AbstractType
{
    public const string NO_FILTER = 'Choose at least one filter of the changes or of their issues,'
        . ' otherwise the board shows every open change.';

    public function __construct(
        private readonly ReviewFilterChoices $choices,
        private readonly BoardMembers $people,
    ) {
    }

    /**
     * @template T
     *
     * @param FormBuilderInterface<T> $builder
     * @param array<mixed>            $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('paths', TextType::class, [
                'required' => false,
                'label' => 'Folders and files',
                'help' => 'Separate several with commas, like "typo3/sysext/extbase/, *.rst".'
                    . ' A folder counts with everything in it. A pattern with "*" finds files by their name.',
                'attr' => ['class' => 'form-control', 'placeholder' => 'typo3/sysext/extbase/'],
            ])
            // Optional here: a board of reviews may leave the issues of its changes open
            ->add('issueQuery', SprintBoardQueryType::class, ['allow_empty' => true])
            // The keys of the search of people or usernames, see assets/ts/sprint/board-members.ts
            ->add('owners', TextType::class, [
                'required' => false,
                'label' => 'Owners',
                'help' => 'Usernames of TYPO3 accounts, separated by commas.',
            ])
            ->add('sizes', ChoiceType::class, [
                'label' => 'Sizes',
                'help' => 'By the lines a change adds and removes, from Easy (up to 50) to Impossible.',
                'choices' => self::labels(ReviewSizeEnum::cases()),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('activities', ChoiceType::class, [
                'label' => 'Last update',
                'help' => 'Fresh: within 14 days. Quiet: up to 60 days. Stale: up to 180 days. Orphaned: longer.',
                'choices' => self::labels(ActivityEnum::cases()),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('workInProgress', ChoiceType::class, [
                'label' => 'Changes marked as work in progress',
                'choices' => ['Hide them' => false, 'Show them' => true],
                'attr' => ['class' => 'form-select'],
            ]);
        $builder->get('paths')->addModelTransformer(new CallbackTransformer(
            static fn (?array $paths): string => implode(', ', $paths ?? []),
            static fn (?string $text): array => ReviewBoardFilter::normalize(explode(',', (string) $text)),
        ));
        $builder->get('owners')->addModelTransformer(new CallbackTransformer(
            static fn (?array $owners): string => implode(', ', $owners ?? []),
            function (?string $text): array {
                $people = $this->people->resolve((string) $text);
                if ([] !== $people['unknown']) {
                    $exception = new TransformationFailedException('An owner is unknown.');
                    $exception->setInvalidMessage(
                        'Forger does not know the TYPO3 account of ' . implode(', ', $people['unknown']) . '.',
                    );

                    throw $exception;
                }

                return $people['usernames'];
            },
        ));
        $this->addLists($builder, new ReviewBoardFilter());

        // Values no open change has right now stay selectable on the boards that use them
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $filter = $event->getData();
            if ($filter instanceof ReviewBoardFilter) {
                $this->addLists($event->getForm(), $filter);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ReviewBoardFilter::class,
            'empty_data' => static fn (): ReviewBoardFilter => new ReviewBoardFilter(),
            // The board takes the filters through its setter, which writes its query too
            'by_reference' => false,
            'label' => false,
            'constraints' => [new Callback(self::needsFilter(...))],
        ]);
    }

    public static function needsFilter(mixed $filter, ExecutionContextInterface $context): void
    {
        if ($filter instanceof ReviewBoardFilter && !$filter->hasFilter()) {
            $context->addViolation(self::NO_FILTER);
        }
    }

    /**
     * @param list<ReviewSizeEnum|ActivityEnum> $cases
     *
     * @return array<string, string> the label of each case as the chip shows and the filter stores it
     */
    private static function labels(array $cases): array
    {
        $labels = array_map(static fn (ReviewSizeEnum|ActivityEnum $case): string => $case->label(), $cases);

        return array_combine($labels, $labels);
    }

    /**
     * @param FormBuilderInterface<mixed>|FormInterface<mixed> $form
     */
    private function addLists(FormBuilderInterface|FormInterface $form, ReviewBoardFilter $filter): void
    {
        $lists = [
            'tags' => ['label' => 'Types', 'chips' => true],
            'branches' => ['label' => 'Branches', 'chips' => true],
            'releases' => [
                'label' => 'Releases',
                'chips' => true,
                'help' => 'The versions the commit message names in "Releases:".',
            ],
            'mergeable' => [
                'label' => 'Mergeable',
                'chips' => true,
                'help' => '"no" for the changes with a merge conflict.',
            ],
            'topics' => ['label' => 'Topics', 'chips' => false],
        ];
        foreach ($lists as $property => $list) {
            $form->add($property, ChoiceType::class, [
                'label' => $list['label'],
                'help' => $list['help'] ?? null,
                'choices' => $this->choicesOf($property, $filter->{$property}, $list['chips']),
                'multiple' => true,
                'expanded' => $list['chips'],
                'required' => false,
                'attr' => $list['chips']
                    ? []
                    : ['class' => 'form-control', 'size' => 8, 'data-filter-select' => ''],
            ]);
        }
    }

    /**
     * The values of a filter, like SprintBoardQueryType::choicesOf(): a list to search names how many open changes
     * each value has, a chip does not.
     *
     * @param list<string> $selected
     *
     * @return array<string, string>
     */
    private function choicesOf(string $property, array $selected, bool $asChips): array
    {
        $choices = [];
        foreach ($this->choices->valuesOf($property) as $value => $count) {
            $choices[$asChips ? (string) $value : $value . ' (' . $count . ')'] = (string) $value;
        }
        foreach ($selected as $value) {
            if (!in_array($value, $choices, true)) {
                $choices[$asChips ? $value : $value . ' (no open changes)'] = $value;
            }
        }

        return $choices;
    }
}
