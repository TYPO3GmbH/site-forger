<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Form;

use App\Entity\SprintBoardCategory;
use App\Service\IconCatalog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Choice;

/**
 * @extends AbstractType<SprintBoardCategory>
 */
class SprintBoardCategoryType extends AbstractType
{
    public function __construct(
        private readonly IconCatalog $icons,
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
            ->add('name', TextType::class, ['required' => true])
            ->add(
                'identifier',
                TextType::class,
                [
                    'required' => true,
                    'label' => 'Identifier (only allowed lower case and -)',
                    'attr' => ['pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$'],
                ]
            )
            // Shown as a picker of the icons, see templates/sprintcategory/form.html.twig
            ->add(
                'icon',
                TextType::class,
                [
                    'required' => true,
                    'label' => 'Icon',
                    'constraints' => [
                        new Choice(choices: $this->icons->identifiers(), message: 'Pick one of the icons.'),
                    ],
                ]
            )
            ->add(
                'submit',
                SubmitType::class,
                [
                    'label' => 'Save',
                    'attr' => [
                        'class' => 'btn btn-primary pull-right',
                    ],
                ]
            );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SprintBoardCategory::class,
        ]);
    }
}
