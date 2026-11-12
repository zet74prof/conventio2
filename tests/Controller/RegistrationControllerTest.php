<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\DatabaseSchemaTrait;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RegistrationControllerTest extends WebTestCase
{
    use DatabaseSchemaTrait;
    use MailerAssertionsTrait;

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

    public function testFullRegistrationAndEmailVerificationFlow(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();

        // 1. Inscription : compte créé non vérifié, e-mail de confirmation envoyé, pas de connexion auto
        $crawler = $client->request('GET', '/register');
        $form = $crawler->selectButton("S'enregistrer")->form();
        $form['registration_form[email]'] = 'nouveau@exemple.fr';
        $form['registration_form[plainPassword]'] = 'motdepasse123';
        $client->submit($form);

        self::assertResponseRedirects('/login');

        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'nouveau@exemple.fr']);
        self::assertNotNull($user);
        self::assertFalse($user->isVerified());
        self::assertNotSame('motdepasse123', $user->getPassword());
        self::assertContains('ROLE_USER', $user->getRoles());

        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertEmailAddressContains($email, 'To', 'nouveau@exemple.fr');
        self::assertEmailSubjectContains($email, 'Confirmez votre adresse e-mail');
        self::assertEmailHtmlBodyContains($email, 'Confirmer mon e-mail');

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Un e-mail de confirmation vous a été envoyé');
        self::assertSelectorTextNotContains('header', 'nouveau@exemple.fr');

        // 2. Lien signé extrait du corps HTML de l'e-mail
        $html = (string) $email->getHtmlBody();
        self::assertMatchesRegularExpression('#href="([^"]*/verify/email[^"]*)"#', $html);
        preg_match('#href="([^"]*/verify/email[^"]*)"#', $html, $matches);
        $signedUrl = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);

        // 3. Tant que l'e-mail n'est pas vérifié, le login est refusé même avec le bon mot de passe
        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form();
        $form['email'] = 'nouveau@exemple.fr';
        $form['password'] = 'motdepasse123';
        $client->submit($form);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'pas encore été confirmée');

        // 4. Clic sur le lien de vérification (anonyme, depuis l'e-mail)
        $client->request('GET', $signedUrl);
        self::assertResponseRedirects('/login');

        $entityManager->clear();
        $user = $entityManager->getRepository(User::class)->find($user->getId());
        self::assertTrue($user->isVerified());

        // 5. Le login fonctionne maintenant
        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form();
        $form['email'] = 'nouveau@exemple.fr';
        $form['password'] = 'motdepasse123';
        $client->submit($form);

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertSelectorTextContains('header', 'nouveau@exemple.fr');
    }

    public function testVerifyEmailWithInvalidSignatureIsRejected(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();

        $user = new User();
        $user->setEmail('etudiant@exemple.fr');
        $user->setPassword('hash-invalide');
        $entityManager->persist($user);
        $entityManager->flush();
        $userId = $user->getId();

        $client->request('GET', '/verify/email?id='.$userId.'&expires='.(time() + 3600).'&token=bidon&signature=bidon');

        self::assertResponseRedirects('/register');

        $entityManager->clear();
        self::assertFalse($entityManager->getRepository(User::class)->find($userId)->isVerified());
    }
}
