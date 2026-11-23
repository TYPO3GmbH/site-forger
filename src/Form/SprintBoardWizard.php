<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Form;

use App\Entity\SprintBoard;
use App\Entity\SprintBoardCategory;
use App\Enum\BoardTypeEnum;
use App\Enum\IssueSortEnum;
use App\Enum\ReviewSortEnum;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Creates and edits a sprint board by picking filters instead of writing its query: the filters of issues or of
 * reviews, by the type of the board, see BoardTypeEnum.
 *
 * @extends AbstractType<SprintBoard>
 */
class SprintBoardWizard extends AbstractType
{
    /**
     * @template T
     *
     * @param FormBuilderInterface<T> $builder
     * @param array<mixed>            $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Shown as the title of the page, see templates/sprint/wizard.html.twig
            ->add('name', TextType::class, ['required' => true])
            ->add('category', EntityType::class, [
                'class' => SprintBoardCategory::class,
                'query_builder' => fn (EntityRepository $er) => $er->createQueryBuilder('sb')
                    ->orderBy('sb.name', 'ASC'),
                'choice_label' => 'name',
                'required' => true,
                'label' => 'Category',
                'attr' => ['class' => 'form-control'],
            ]);
        // The type of the board is set before its form: it decides what the board filters and how it sorts
        $board = $options['data'] ?? null;
        if ($board instanceof SprintBoard && BoardTypeEnum::REVIEWS === $board->getType()) {
            $builder
                ->add('reviewFilter', ReviewBoardFilterType::class)
                ->add('reviewSort', EnumType::class, [
                    'class' => ReviewSortEnum::class,
                    'choices' => ReviewSortEnum::options(false),
                    'choice_label' => static fn (ReviewSortEnum $sort): string => $sort->label(),
                    'label' => 'Order of the changes',
                    'help' => 'How the board opens. Everybody can change the order on the board.',
                    'attr' => ['class' => 'form-select'],
                ]);
        } else {
            $builder
                ->add('query', SprintBoardQueryType::class)
                ->add('cardSort', EnumType::class, [
                    'class' => IssueSortEnum::class,
                    'choices' => IssueSortEnum::options(false),
                    'choice_label' => static fn (IssueSortEnum $sort): string => $sort->label(),
                    'label' => 'Order of the issues',
                    'help' => 'How the board opens. Everybody can change the order on the board.',
                    'attr' => ['class' => 'form-select'],
                ])
                ->add('firstView', EnumType::class, [
                    'class' => BoardTypeEnum::class,
                    'choice_label' => static fn (BoardTypeEnum $view): string => match ($view) {
                        BoardTypeEnum::ISSUES => 'Its issues',
                        BoardTypeEnum::REVIEWS => 'The reviews of its issues',
                    },
                    'label' => 'The board opens with',
                    'help' => 'The other view is one click away on the board.',
                    'attr' => ['class' => 'form-select'],
                ]);
        }
        BoardAccessFields::add($builder);
        $builder
            ->add('submit', SubmitType::class, [
                'label' => 'Save board',
                'attr' => ['class' => 'btn btn-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SprintBoard::class,
        ]);
    }
}
