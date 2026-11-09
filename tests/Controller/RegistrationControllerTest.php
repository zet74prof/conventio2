<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\DatabaseSchemaTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RegistrationControllerTest extends WebTestCase
{
    use DatabaseSchemaTrait;

    public function testRegisterPageIsAccessible(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', "S'enregistrer");
        self::assertCount(1, $crawler->filter('input[name="registration_form[email]"]'));
        self::assertCount(1, $crawler->filter('input[name="registration_form[plainPassword]"]'));
        self::assertCount(1, $crawler->filter('main a[href="/login"]'));
    }

    public function testRegisterCreatesUserAndLogsIn(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();

        $crawler = $client->request('GET', '/register');
        $form = $crawler->selectButton("S'enregistrer")->form();
        $form['registration_form[email]'] = 'nouveau@exemple.fr';
        $form['registration_form[plainPassword]'] = 'motdepasse123';
        $client->submit($form);

        self::assertResponseRedirects('/');

        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'nouveau@exemple.fr']);
        self::assertNotNull($user);
        self::assertNotSame('motdepasse123', $user->getPassword());
        self::assertContains('ROLE_USER', $user->getRoles());

        $client->followRedirect();
        self::assertSelectorTextContains('header', 'nouveau@exemple.fr');
    }

    public function testRegisterWithInvalidDataRedisplaysForm(): void
    {
        $client = static::createClient();
        $this->resetDatabaseSchema();

        $crawler = $client->request('GET', '/register');
        $form = $crawler->selectButton("S'enregistrer")->form();
        $form['registration_form[email]'] = 'pas-un-email';
        $form['registration_form[plainPassword]'] = 'abc';
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('h1', 'S\'enregistrer');
        self::assertNull(
            self::getContainer()->get('doctrine')->getManager()
                ->getRepository(User::class)->findOneBy(['email' => 'pas-un-email'])
        );
    }
}
