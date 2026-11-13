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
        self::assertCount(1, $crawler->filter('select[name="registration_form[profile]"]'));
        self::assertCount(1, $crawler->filter('input[name="registration_form[email]"]'));
        self::assertCount(1, $crawler->filter('input[name="registration_form[nom]"]'));
        self::assertCount(1, $crawler->filter('input[name="registration_form[prenom]"]'));
        self::assertCount(1, $crawler->filter('input[name="registration_form[plainPassword][first]"]'));
        self::assertCount(1, $crawler->filter('input[name="registration_form[plainPassword][second]"]'));
        self::assertCount(1, $crawler->filter('button[type="submit"]'));
        self::assertCount(1, $crawler->filter('main a[href="/login"]'));

        // Filigrane d'exemple et règle des formats transmis au contrôleur Stimulus (contrôle front)
        self::assertSame('email@lycee-faure.fr', $crawler->filter('input[name="registration_form[email]"]')->attr('placeholder'));
        self::assertSame('lycee-faure.fr', $crawler->filter('form[data-controller="registration"]')->attr('data-registration-student-domain-value'));
        self::assertSame('ac-grenoble.fr', $crawler->filter('form[data-controller="registration"]')->attr('data-registration-teacher-domain-value'));
    }

    public function testRegisterWithInvalidEmailFormatRedisplaysFormWithAlert(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();

        $crawler = $client->request('GET', '/register');
        $form = $crawler->selectButton('Valider')->form();
        $form['registration_form[profile]'] = 'etudiant';
        $form['registration_form[email]'] = 'pas-un-email';
        $form['registration_form[nom]'] = 'Dupont';
        $form['registration_form[prenom]'] = 'Jean';
        $form['registration_form[plainPassword][first]'] = 'motdepasse123';
        $form['registration_form[plainPassword][second]'] = 'motdepasse123';
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('h1', 'S\'enregistrer');
        // Alerte en haut de page redonnant la règle des formats d'e-mail
        self::assertSelectorTextContains('[role="alert"]', '@lycee-faure.fr');
        self::assertSelectorTextContains('[role="alert"]', '@ac-grenoble.fr');
        self::assertNull(
            $entityManager->getRepository(User::class)->findOneBy(['email' => 'pas-un-email'])
        );
    }

    public function testRegisterWithWrongEmailDomainForProfileIsRejected(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();

        $crawler = $client->request('GET', '/register');
        $form = $crawler->selectButton('Valider')->form();
        $form['registration_form[profile]'] = 'etudiant';
        $form['registration_form[email]'] = 'jean@free.fr';
        $form['registration_form[nom]'] = 'Dupont';
        $form['registration_form[prenom]'] = 'Jean';
        $form['registration_form[plainPassword][first]'] = 'motdepasse123';
        $form['registration_form[plainPassword][second]'] = 'motdepasse123';
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('[role="alert"]', 'doit être au format @lycee-faure.fr');
        self::assertNull(
            $entityManager->getRepository(User::class)->findOneBy(['email' => 'jean@free.fr'])
        );
    }

    public function testStudentCannotRegisterWithTeacherDomainAndViceVersa(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();

        $crawler = $client->request('GET', '/register');
        $form = $crawler->selectButton('Valider')->form();
        $form['registration_form[profile]'] = 'etudiant';
        $form['registration_form[email]'] = 'prof@ac-grenoble.fr';
        $form['registration_form[nom]'] = 'Durand';
        $form['registration_form[prenom]'] = 'Marie';
        $form['registration_form[plainPassword][first]'] = 'motdepasse123';
        $form['registration_form[plainPassword][second]'] = 'motdepasse123';
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertNull(
            $entityManager->getRepository(User::class)->findOneBy(['email' => 'prof@ac-grenoble.fr'])
        );
    }

    public function testRegisterWithMismatchedPasswordsIsRejected(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();

        $crawler = $client->request('GET', '/register');
        $form = $crawler->selectButton('Valider')->form();
        $form['registration_form[profile]'] = 'etudiant';
        $form['registration_form[email]'] = 'jean@lycee-faure.fr';
        $form['registration_form[nom]'] = 'Dupont';
        $form['registration_form[prenom]'] = 'Jean';
        $form['registration_form[plainPassword][first]'] = 'motdepasse123';
        $form['registration_form[plainPassword][second]'] = 'autre-chose';
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('body', 'Les mots de passe ne correspondent pas');
        self::assertNull(
            $entityManager->getRepository(User::class)->findOneBy(['email' => 'jean@lycee-faure.fr'])
        );
    }

    public function testFullRegistrationAndEmailVerificationFlow(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();

        // 1. Inscription : compte créé non vérifié, e-mail de confirmation envoyé, pas de connexion auto
        $crawler = $client->request('GET', '/register');
        $form = $crawler->selectButton('Valider')->form();
        $form['registration_form[profile]'] = 'etudiant';
        $form['registration_form[email]'] = 'nouveau@lycee-faure.fr';
        $form['registration_form[nom]'] = 'Dupont';
        $form['registration_form[prenom]'] = 'Jean';
        $form['registration_form[plainPassword][first]'] = 'motdepasse123';
        $form['registration_form[plainPassword][second]'] = 'motdepasse123';
        $client->submit($form);

        self::assertResponseRedirects('/login');

        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'nouveau@lycee-faure.fr']);
        self::assertNotNull($user);
        self::assertFalse($user->isVerified());
        self::assertNotSame('motdepasse123', $user->getPassword());
        self::assertSame('Dupont', $user->getNom());
        self::assertSame('Jean', $user->getPrenom());
        self::assertSame('etudiant', $user->getProfile());
        self::assertContains('ROLE_USER', $user->getRoles());
        self::assertContains('ROLE_STUDENT', $user->getRoles());

        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertEmailAddressContains($email, 'To', 'nouveau@lycee-faure.fr');
        self::assertEmailSubjectContains($email, 'Confirmez votre adresse e-mail');
        self::assertEmailHtmlBodyContains($email, 'Confirmer mon e-mail');

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Un e-mail de confirmation vous a été envoyé');
        self::assertSelectorTextNotContains('header', 'nouveau@lycee-faure.fr');

        // 2. Lien signé extrait du corps HTML de l'e-mail
        $html = (string) $email->getHtmlBody();
        self::assertMatchesRegularExpression('#href="([^"]*/verify/email[^"]*)"#', $html);
        preg_match('#href="([^"]*/verify/email[^"]*)"#', $html, $matches);
        $signedUrl = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);

        // 3. Tant que l'e-mail n'est pas vérifié, le login est refusé même avec le bon mot de passe
        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form();
        $form['email'] = 'nouveau@lycee-faure.fr';
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
        $form['email'] = 'nouveau@lycee-faure.fr';
        $form['password'] = 'motdepasse123';
        $client->submit($form);

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertSelectorTextContains('header', 'nouveau@lycee-faure.fr');
    }

    public function testTeacherRegistrationGetsTeacherRole(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();

        $crawler = $client->request('GET', '/register');
        $form = $crawler->selectButton('Valider')->form();
        $form['registration_form[profile]'] = 'professeur';
        $form['registration_form[email]'] = 'prof@ac-grenoble.fr';
        $form['registration_form[nom]'] = 'Martin';
        $form['registration_form[prenom]'] = 'Sophie';
        $form['registration_form[plainPassword][first]'] = 'motdepasse123';
        $form['registration_form[plainPassword][second]'] = 'motdepasse123';
        $client->submit($form);

        self::assertResponseRedirects('/login');

        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => 'prof@ac-grenoble.fr']);
        self::assertNotNull($user);
        self::assertSame('professeur', $user->getProfile());
        self::assertContains('ROLE_TEACHER', $user->getRoles());
    }

    public function testVerifyEmailWithInvalidSignatureIsRejected(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();

        $user = new User();
        $user->setEmail('etudiant@lycee-faure.fr');
        $user->setNom('Dupont');
        $user->setPrenom('Jean');
        $user->setProfile('etudiant');
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
