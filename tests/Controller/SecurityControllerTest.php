<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\DatabaseSchemaTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SecurityControllerTest extends WebTestCase
{
    use DatabaseSchemaTrait;

    public function testLoginPageIsAccessible(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Se connecter');
        self::assertCount(1, $crawler->filter('input[name="email"]'));
        self::assertCount(1, $crawler->filter('input[name="password"]'));
        self::assertCount(1, $crawler->filter('input[name="_csrf_token"]'));
        self::assertCount(1, $crawler->filter('main a[href="/register"]'));
    }

    public function testLoginWithValidCredentialsRedirectsToHomeAndAuthenticates(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();
        $this->createUser($entityManager, 'etudiant@exemple.fr', 'motdepasse123', verified: true);

        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form();
        $form['email'] = 'etudiant@exemple.fr';
        $form['password'] = 'motdepasse123';
        $client->submit($form);

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertSelectorTextContains('header', 'etudiant@exemple.fr');
    }

    public function testLoginWithInvalidCredentialsRedirectsBackToLogin(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();
        $this->createUser($entityManager, 'etudiant@exemple.fr', 'motdepasse123', verified: true);

        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form();
        $form['email'] = 'etudiant@exemple.fr';
        $form['password'] = 'mauvais-mot-de-passe';
        $client->submit($form);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorExists('div.bg-red-50');
    }

    public function testLoginIsRejectedWhileEmailIsNotVerified(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();
        $this->createUser($entityManager, 'etudiant@exemple.fr', 'motdepasse123', verified: false);

        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form();
        $form['email'] = 'etudiant@exemple.fr';
        $form['password'] = 'motdepasse123';
        $client->submit($form);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'pas encore été confirmée');
        self::assertSelectorTextNotContains('header', 'etudiant@exemple.fr');
    }

    private function createUser(EntityManagerInterface $entityManager, string $email, string $plainPassword, bool $verified): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)
                ->hashPassword($user, $plainPassword)
        );
        $user->setIsVerified($verified);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
