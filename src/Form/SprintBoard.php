<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Form;

use App\Entity\SprintBoardCategory;
use App\Repository\SprintBoardCategoryRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SprintBoard extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $categoryRepository = $options['entity_manager']->getRepository(SprintBoardCategory::class);
        $builder
            ->add('name', null, ['required' => true, 'attr' => ['class' => 'form-control']])
            ->add(
                'categoryId',
                ChoiceType::class,
                [
                    'choices' => $this->buildCategoriesArray($categoryRepository),
                    'required' => true,
                    'mapped' => false,
                    'label' => 'Category',
                    'attr' => ['class' => 'form-control']
                ]
            )
            ->add(
                'query',
                TextareaType::class,
                [
                    'attr' => ['class' => 'form-control']
                ]
            )
            ->add(
                'submit',
                SubmitType::class,
                [
                    'label' => 'Save',
                    'attr' => ['class' => 'btn btn-primary pull-right'
                ],
            ]
            );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => \App\Entity\SprintBoard::class,
            'csrf_protection' => getenv('APP_ENV') !== 'test',
            'validation_groups' => false,
            'entity_manager' => null,
        ]);
    }

    private function buildCategoriesArray(SprintBoardCategoryRepository $categoryRepository): array
    {
        $categories = [];

        /** @var SprintBoardCategory $category */
        foreach ($categoryRepository->findAll() as $category) {
            $categories[$category->getId()] = $category->getName();
        }

        asort($categories);
        $categories = array_flip($categories);

        return $categories;
    }
}
