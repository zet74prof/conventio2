<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'attr' => [
                    'autocomplete' => 'email',
                    'placeholder' => $options['email_placeholder'],
                ],
            ])
            ->add('nom', TextType::class, [
                'label' => 'Nom',
                'attr' => [
                    'autocomplete' => 'family-name',
                    'placeholder' => ' ',
                ],
            ])
            ->add('prenom', TextType::class, [
                'label' => 'Prénom',
                'attr' => [
                    'autocomplete' => 'given-name',
                    'placeholder' => ' ',
                ],
            ])
            ->add('profile', ChoiceType::class, [
                'label' => 'Je suis',
                'choices' => [
                    'Étudiant' => User::PROFILE_STUDENT,
                    'Professeur' => User::PROFILE_TEACHER,
                ],
                'placeholder' => 'Choisir un profil',
                'constraints' => [
                    new NotBlank(message: 'Veuillez choisir un profil (étudiant ou professeur).'),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'Les mots de passe ne correspondent pas.',
                'first_options' => [
                    'label' => 'Mot de passe',
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'placeholder' => ' ',
                    ],
                ],
                'second_options' => [
                    'label' => 'Confirmation du mot de passe',
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'placeholder' => ' ',
                    ],
                ],
                'constraints' => [
                    new NotBlank(message: 'Veuillez choisir un mot de passe.'),
                    new Length(
                        min: 6,
                        max: 4096,
                        minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.'
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'email_placeholder' => 'email@lycee-faure.fr',
        ]);

        $resolver->setAllowedTypes('email_placeholder', 'string');
    }
}
