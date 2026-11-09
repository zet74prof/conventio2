<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\DatabaseSchemaTrait;
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

        $user = new User();
        $user->setEmail('etudiant@exemple.fr');
        $user->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)
                ->hashPassword($user, 'motdepasse123')
        );
        $entityManager->persist($user);
        $entityManager->flush();

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

        $user = new User();
        $user->setEmail('etudiant@exemple.fr');
        $user->setPassword(
            self::getContainer()->get(UserPasswordHasherInterface::class)
                ->hashPassword($user, 'motdepasse123')
        );
        $entityManager->persist($user);
        $entityManager->flush();

        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form();
        $form['email'] = 'etudiant@exemple.fr';
        $form['password'] = 'mauvais-mot-de-passe';
        $client->submit($form);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorExists('div.bg-red-50');
    }
}
