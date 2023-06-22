<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Form;

use App\Repository\CompanyRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EmploymentTime extends AbstractType
{
    public function __construct(private readonly CompanyRepository $companyRepository)
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
        $builder
            ->add('company', ChoiceType::class, [
                'required' => true,
                'choices' => $this->getCompanies(),
                'choice_value' => 'id',
                'choice_label' => 'name',
                'attr' => [
                    'class' => 'form-control',
                ],
            ])
            ->add('employed_from', DateType::class, [
                'required' => true,
                'widget' => 'single_text',
                'attr' => [
                    'class' => 'form-control',
                ],
            ])
            ->add('employed_to', DateType::class, [
                'required' => false,
                'widget' => 'single_text',
                'attr' => [
                    'class' => 'form-control',
                ],
            ])
            ->add(
                'submit',
                SubmitType::class,
                [
                    'label' => 'Save',
                    'attr' => ['class' => 'btn btn-primary float-right'],
                ]
            );

        $builder->get('employed_from')->addModelTransformer(new CallbackTransformer(
            static fn ($employedFrom) => $employedFrom,
            static function ($employedFrom) {
                if ($employedFrom instanceof \DateTimeInterface) {
                    $employedFrom->setTime(0, 0);
                }

                return $employedFrom;
            }
        ));
        $builder->get('employed_to')->addModelTransformer(new CallbackTransformer(
            static fn ($employedTo) => $employedTo,
            static function ($employedTo) {
                if ($employedTo instanceof \DateTimeInterface) {
                    $employedTo->setTime(23, 59, 59);
                }

                return $employedTo;
            }
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => \App\Entity\EmploymentTime::class,
            'csrf_protection' => 'test' !== getenv('APP_ENV'),
            'entity_manager' => null,
        ]);
    }

    private function getCompanies(): array
    {
        return $this->companyRepository->findAll();
    }
}
