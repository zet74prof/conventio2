<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Valide qu'une adresse e-mail se termine par le domaine configuré pour le
 * profil de l'utilisateur (étudiant ou professeur).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class EmailDomain extends Constraint
{
    public string $message = 'L\'adresse e-mail « {{ value }} » n\'est pas au format attendu : elle doit se terminer par @{{ domain }}.';

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }

    public function validatedBy(): string
    {
        return EmailDomainValidator::class;
    }
}
