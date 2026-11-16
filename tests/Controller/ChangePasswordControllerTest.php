<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\DatabaseSchemaTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ChangePasswordControllerTest extends WebTestCase
{
    use DatabaseSchemaTrait;

    private const string OLD_PASSWORD = 'Ancien-Mot2Passe!';
    private const string NEW_PASSWORD = 'Nouv3au-Mot2Passe!';

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabaseSchema();

        $client->request('GET', '/change-password');

        self::assertResponseRedirects('/login');
    }

    public function testPageIsAccessibleWhenLoggedIn(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();
        $user = $this->createUser($entityManager, 'etudiant@exemple.fr', self::OLD_PASSWORD);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/change-password');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Changer de mot de passe');
        self::assertCount(1, $crawler->filter('input[name="change_password_form[currentPassword]"]'));
        self::assertCount(1, $crawler->filter('input[name="change_password_form[newPassword][first]"]'));
        self::assertCount(1, $crawler->filter('input[name="change_password_form[newPassword][second]"]'));
    }

    public function testWrongCurrentPasswordIsRejected(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();
        $user = $this->createUser($entityManager, 'etudiant@exemple.fr', self::OLD_PASSWORD);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/change-password');
        $form = $crawler->selectButton('Modifier le mot de passe')->form();
        $form['change_password_form[currentPassword]'] = 'mauvais-mot-de-passe';
        $form['change_password_form[newPassword][first]'] = self::NEW_PASSWORD;
        $form['change_password_form[newPassword][second]'] = self::NEW_PASSWORD;
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('body', 'Le mot de passe actuel est incorrect');

        $entityManager->clear();
        $user = $entityManager->getRepository(User::class)->find($user->getId());
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($passwordHasher->isPasswordValid($user, self::OLD_PASSWORD));
        self::assertFalse($passwordHasher->isPasswordValid($user, self::NEW_PASSWORD));
    }

    public function testWeakNewPasswordIsRejected(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();
        $user = $this->createUser($entityManager, 'etudiant@exemple.fr', self::OLD_PASSWORD);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/change-password');
        $form = $crawler->selectButton('Modifier le mot de passe')->form();
        $form['change_password_form[currentPassword]'] = self::OLD_PASSWORD;
        $form['change_password_form[newPassword][first]'] = 'court';
        $form['change_password_form[newPassword][second]'] = 'court';
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('body', 'au moins 12 caractères');

        $entityManager->clear();
        $user = $entityManager->getRepository(User::class)->find($user->getId());
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($passwordHasher->isPasswordValid($user, self::OLD_PASSWORD));
    }

    public function testMismatchedNewPasswordsAreRejected(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();
        $user = $this->createUser($entityManager, 'etudiant@exemple.fr', self::OLD_PASSWORD);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/change-password');
        $form = $crawler->selectButton('Modifier le mot de passe')->form();
        $form['change_password_form[currentPassword]'] = self::OLD_PASSWORD;
        $form['change_password_form[newPassword][first]'] = self::NEW_PASSWORD;
        $form['change_password_form[newPassword][second]'] = 'Nouv3au-Mot2Passe-Different!';
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('body', 'Les mots de passe ne correspondent pas');
    }

    public function testSuccessfulChangeUpdatesPasswordAndAllowsLogin(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();
        $user = $this->createUser($entityManager, 'etudiant@exemple.fr', self::OLD_PASSWORD);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/change-password');
        $form = $crawler->selectButton('Modifier le mot de passe')->form();
        $form['change_password_form[currentPassword]'] = self::OLD_PASSWORD;
        $form['change_password_form[newPassword][first]'] = self::NEW_PASSWORD;
        $form['change_password_form[newPassword][second]'] = self::NEW_PASSWORD;
        $client->submit($form);

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'mot de passe a été modifié avec succès');

        $entityManager->clear();
        $user = $entityManager->getRepository(User::class)->find($user->getId());
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($passwordHasher->isPasswordValid($user, self::NEW_PASSWORD));
        self::assertFalse($passwordHasher->isPasswordValid($user, self::OLD_PASSWORD));

        // Le nouveau mot de passe permet de se connecter
        $client->request('GET', '/logout');
        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form();
        $form['email'] = 'etudiant@exemple.fr';
        $form['password'] = self::NEW_PASSWORD;
        $client->submit($form);

        self::assertResponseRedirects('/');
    }

    private function createUser(EntityManagerInterface $entityManager, string $email, string $plainPassword): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setNom('Dupont');
        $user->setPrenom('Jean');
        $user->setProfile(User::PROFILE_STUDENT);
        $user->setPassword(
            static::getContainer()->get(UserPasswordHasherInterface::class)
                ->hashPassword($user, $plainPassword)
        );
        $user->setIsVerified(true);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
