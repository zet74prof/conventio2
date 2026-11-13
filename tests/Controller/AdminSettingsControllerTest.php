<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Service\SettingsService;
use App\Tests\DatabaseSchemaTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AdminSettingsControllerTest extends WebTestCase
{
    use DatabaseSchemaTrait;

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabaseSchema();

        $client->request('GET', '/admin/parametres');

        self::assertResponseRedirects('/login');
    }

    public function testNonAdminUserIsForbidden(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();
        $user = $this->createUser($entityManager, 'etudiant@lycee-faure.fr', []);

        $client->loginUser($user);
        $client->request('GET', '/admin/parametres');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanSeeSettingsPageWithDefaultDomains(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();
        $admin = $this->createUser($entityManager, 'admin@ac-grenoble.fr', ['ROLE_ADMIN']);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/parametres');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Paramètres');
        $form = $crawler->selectButton('Enregistrer')->form();
        self::assertSame('lycee-faure.fr', $form['settings[email_domain_student]']->getValue());
        self::assertSame('ac-grenoble.fr', $form['settings[email_domain_teacher]']->getValue());
    }

    public function testAdminCanUpdateEmailDomainsAndTheyApplyToRegistration(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();
        $admin = $this->createUser($entityManager, 'admin@ac-grenoble.fr', ['ROLE_ADMIN']);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/parametres');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['settings[email_domain_student]'] = 'ecole-exemple.fr';
        $form['settings[email_domain_teacher]'] = 'ac-grenoble.fr';
        $client->submit($form);

        self::assertResponseRedirects('/admin/parametres');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'formats d\'e-mail ont été mis à jour');

        self::assertSame('ecole-exemple.fr', self::getContainer()->get(SettingsService::class)->getStudentEmailDomain());

        // Le nouveau domaine est accepté à l'inscription...
        $crawler = $client->request('GET', '/register');
        $form = $crawler->selectButton('Valider')->form();
        $form['registration_form[profile]'] = 'etudiant';
        $form['registration_form[email]'] = 'nouveau@ecole-exemple.fr';
        $form['registration_form[nom]'] = 'Dupont';
        $form['registration_form[prenom]'] = 'Jean';
        $form['registration_form[plainPassword][first]'] = 'motdepasse123';
        $form['registration_form[plainPassword][second]'] = 'motdepasse123';
        $client->submit($form);
        self::assertResponseRedirects('/login');
        self::assertNotNull($entityManager->getRepository(User::class)->findOneBy(['email' => 'nouveau@ecole-exemple.fr']));

        // ...et l'ancien domaine étudiant est refusé, avec l'alerte à jour
        $crawler = $client->request('GET', '/register');
        $form = $crawler->selectButton('Valider')->form();
        $form['registration_form[profile]'] = 'etudiant';
        $form['registration_form[email]'] = 'ancien@lycee-faure.fr';
        $form['registration_form[nom]'] = 'Durand';
        $form['registration_form[prenom]'] = 'Marie';
        $form['registration_form[plainPassword][first]'] = 'motdepasse123';
        $form['registration_form[plainPassword][second]'] = 'motdepasse123';
        $client->submit($form);
        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('[role="alert"]', '@ecole-exemple.fr');
        self::assertNull($entityManager->getRepository(User::class)->findOneBy(['email' => 'ancien@lycee-faure.fr']));
    }

    public function testAdminCannotSaveInvalidDomain(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();
        $admin = $this->createUser($entityManager, 'admin@ac-grenoble.fr', ['ROLE_ADMIN']);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/admin/parametres');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['settings[email_domain_student]'] = 'pas un domaine !';
        $form['settings[email_domain_teacher]'] = 'ac-grenoble.fr';
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSame('lycee-faure.fr', self::getContainer()->get(SettingsService::class)->getStudentEmailDomain());
    }

    private function createUser(EntityManagerInterface $entityManager, string $email, array $roles): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setNom('Admin');
        $user->setPrenom('Admin');
        $user->setProfile(User::PROFILE_TEACHER);
        $user->setRoles($roles);
        $user->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)
                ->hashPassword($user, 'motdepasse123')
        );
        $user->setIsVerified(true);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
