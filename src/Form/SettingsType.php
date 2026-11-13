<?php

namespace App\Form;

use App\Service\SettingsService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class SettingsType extends AbstractType
{
    private const string DOMAIN_PATTERN = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email_domain_student', TextType::class, [
                'label' => 'Domaine e-mail des étudiants',
                'help' => 'Format : lycee-faure.fr — les comptes étudiants devront utiliser une adresse en @lycee-faure.fr.',
                'attr' => ['placeholder' => ' '],
                'constraints' => [
                    new NotBlank(message: 'Veuillez saisir le domaine e-mail des étudiants.'),
                    new Regex(
                        pattern: self::DOMAIN_PATTERN,
                        message: '« {{ value }} » n\'est pas un nom de domaine valide (ex. lycee-faure.fr).'
                    ),
                ],
            ])
            ->add('email_domain_teacher', TextType::class, [
                'label' => 'Domaine e-mail des professeurs',
                'help' => 'Format : ac-grenoble.fr — les comptes professeurs devront utiliser une adresse en @ac-grenoble.fr.',
                'attr' => ['placeholder' => ' '],
                'constraints' => [
                    new NotBlank(message: 'Veuillez saisir le domaine e-mail des professeurs.'),
                    new Regex(
                        pattern: self::DOMAIN_PATTERN,
                        message: '« {{ value }} » n\'est pas un nom de domaine valide (ex. ac-grenoble.fr).'
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'translation_domain' => false,
        ]);

        $resolver->setAllowedTypes('data_class', 'null');
    }
}
