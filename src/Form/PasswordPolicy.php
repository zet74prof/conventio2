<?php

namespace App\Form;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PasswordStrength;

final class PasswordPolicy
{
    public const int MIN_LENGTH = 12;

    /**
     * Politique de mot de passe de l'application, conforme aux recommandations
     * de la CNIL : 12 caractères minimum et entropie suffisante mesurée par
     * la contrainte PasswordStrength de Symfony.
     *
     * @return list<Constraint>
     */
    public static function constraints(): array
    {
        return [
            new NotBlank(message: 'Veuillez choisir un mot de passe.'),
            new Length(
                min: self::MIN_LENGTH,
                max: 4096,
                minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères (recommandation CNIL).',
            ),
            new PasswordStrength(
                minScore: PasswordStrength::STRENGTH_MEDIUM,
                message: 'Le mot de passe est trop faible : utilisez au moins 12 caractères variés ou une phrase de passe (recommandation CNIL).',
            ),
        ];
    }
}
