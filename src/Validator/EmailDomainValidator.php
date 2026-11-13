<?php

namespace App\Validator;

use App\Entity\User;
use App\Service\SettingsService;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class EmailDomainValidator extends ConstraintValidator
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof EmailDomain) {
            throw new UnexpectedTypeException($constraint, EmailDomain::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof User) {
            throw new UnexpectedTypeException($value, User::class);
        }

        $email = trim((string) $value->getEmail());

        if ('' === $email) {
            return; // géré par Assert\NotBlank
        }

        $profileDomain = $this->settings->getEmailDomainForProfile($value->getProfile());
        $domains = null !== $profileDomain
            ? [$profileDomain]
            : array_filter([$this->settings->getStudentEmailDomain(), $this->settings->getTeacherEmailDomain()]);

        foreach ($domains as $domain) {
            if (str_ends_with(strtolower($email), '@'.strtolower($domain))) {
                return;
            }
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter('{{ value }}', $email)
            ->setParameter('{{ domain }}', implode(' ou ', $domains))
            ->atPath('email')
            ->addViolation();
    }
}
