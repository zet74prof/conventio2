<?php

namespace App\Service;

use App\Entity\AppSetting;
use App\Entity\User;
use App\Repository\AppSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

class SettingsService
{
    public const string KEY_EMAIL_DOMAIN_STUDENT = 'email_domain_student';
    public const string KEY_EMAIL_DOMAIN_TEACHER = 'email_domain_teacher';

    private const array DEFAULTS = [
        self::KEY_EMAIL_DOMAIN_STUDENT => 'lycee-faure.fr',
        self::KEY_EMAIL_DOMAIN_TEACHER => 'ac-grenoble.fr',
    ];

    public function __construct(
        private readonly AppSettingRepository $settingRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function get(string $key): string
    {
        $setting = $this->settingRepository->findOneBy(['key' => $key]);

        return $setting?->getValue() ?? self::DEFAULTS[$key] ?? throw new \InvalidArgumentException(sprintf('Paramètre inconnu "%s".', $key));
    }

    public function set(string $key, string $value): void
    {
        if (!\array_key_exists($key, self::DEFAULTS)) {
            throw new \InvalidArgumentException(sprintf('Paramètre inconnu "%s".', $key));
        }

        $setting = $this->settingRepository->findOneBy(['key' => $key]) ?? new AppSetting();
        $setting->setKey($key);
        $setting->setValue($value);

        $this->entityManager->persist($setting);
        $this->entityManager->flush();
    }

    public function getStudentEmailDomain(): string
    {
        return $this->get(self::KEY_EMAIL_DOMAIN_STUDENT);
    }

    public function getTeacherEmailDomain(): string
    {
        return $this->get(self::KEY_EMAIL_DOMAIN_TEACHER);
    }

    public function getEmailDomainForProfile(?string $profile): ?string
    {
        return match ($profile) {
            User::PROFILE_STUDENT => $this->getStudentEmailDomain(),
            User::PROFILE_TEACHER => $this->getTeacherEmailDomain(),
            default => null,
        };
    }

    /**
     * Règle affichée en alerte quand le format de l'e-mail est invalide.
     */
    public function getEmailRuleMessage(): string
    {
        return sprintf(
            'Étudiant : l\'e-mail doit être au format @%s. Professeur : l\'e-mail doit être au format @%s.',
            $this->getStudentEmailDomain(),
            $this->getTeacherEmailDomain(),
        );
    }
}
