<?php

namespace App\Tests\Controller;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Tests\DatabaseSchemaTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ResetPasswordControllerTest extends WebTestCase
{
    use DatabaseSchemaTrait;
    use MailerAssertionsTrait;

    private const string OLD_PASSWORD = 'Ancien-Mot2Passe!';
    private const string NEW_PASSWORD = 'Nouv3au-Mot2Passe!';

    public function testRequestPageIsAccessible(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/reset-password');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mot de passe oublié');
        self::assertCount(1, $crawler->filter('input[name="reset_password_request_form[email]"]'));
        self::assertCount(1, $crawler->filter('button[type="submit"]'));
        self::assertCount(1, $crawler->filter('main a[href="/login"]'));
    }

    public function testCheckEmailPageIsAccessibleWithoutSessionToken(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/reset-password/check-email');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'E-mail envoyé');
        self::assertSelectorTextContains('body', 'expirera dans 1 heure');
    }

    public function testUnknownEmailRedirectsToCheckEmailWithoutSendingAnyEmail(): void
    {
        $client = static::createClient();
        $this->resetDatabaseSchema();

        $crawler = $client->request('GET', '/reset-password');
        $form = $crawler->selectButton('Envoyer le lien de réinitialisation')->form();
        $form['reset_password_request_form[email]'] = 'inconnu@lycee-faure.fr';
        $client->submit($form);

        self::assertResponseRedirects('/reset-password/check-email');
        self::assertEmailCount(0);
    }

    public function testFullPasswordResetFlow(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();
        $user = $this->createUser($entityManager, 'etudiant@lycee-faure.fr', self::OLD_PASSWORD);

        // 1. Demande de réinitialisation : e-mail envoyé, redirection vers la page « check-email »
        $crawler = $client->request('GET', '/reset-password');
        $form = $crawler->selectButton('Envoyer le lien de réinitialisation')->form();
        $form['reset_password_request_form[email]'] = 'etudiant@lycee-faure.fr';
        $client->submit($form);

        self::assertResponseRedirects('/reset-password/check-email');
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertEmailAddressContains($email, 'From', 'no-reply@conventio.fr');
        self::assertEmailAddressContains($email, 'To', 'etudiant@lycee-faure.fr');
        self::assertEmailSubjectContains($email, 'Réinitialisation de votre mot de passe');

        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('h1', 'E-mail envoyé');
        self::assertSelectorTextContains('body', 'expirera dans 1 heure');

        // 2. Lien signé extrait du corps HTML de l'e-mail
        $html = (string) $email->getHtmlBody();
        self::assertMatchesRegularExpression('#href="([^"]*/reset-password/reset/[A-Za-z0-9_-]+)"#', $html);
        preg_match('#href="([^"]*/reset-password/reset/[A-Za-z0-9_-]+)"#', $html, $matches);
        $resetUrl = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);

        // 3. Le lien stocke le jeton en session puis redirige vers le formulaire
        $client->request('GET', $resetUrl);
        self::assertResponseRedirects('/reset-password/reset');

        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Nouveau mot de passe');

        // 4. Saisie du nouveau mot de passe
        $form = $crawler->selectButton('Réinitialiser le mot de passe')->form();
        $form['reset_password_form[plainPassword][first]'] = self::NEW_PASSWORD;
        $form['reset_password_form[plainPassword][second]'] = self::NEW_PASSWORD;
        $client->submit($form);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'mot de passe a été réinitialisé');

        // 5. Le mot de passe a bien été changé et le jeton consommé
        $entityManager->clear();
        $user = $entityManager->getRepository(User::class)->find($user->getId());
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($passwordHasher->isPasswordValid($user, self::NEW_PASSWORD));
        self::assertFalse($passwordHasher->isPasswordValid($user, self::OLD_PASSWORD));
        self::assertCount(0, $entityManager->getRepository(ResetPasswordRequest::class)->findBy(['user' => $user]));
    }

    public function testWeakNewPasswordIsRejectedDuringReset(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();
        $user = $this->createUser($entityManager, 'etudiant@lycee-faure.fr', self::OLD_PASSWORD);

        $resetUrl = $this->requestResetLink($client, 'etudiant@lycee-faure.fr');

        $client->request('GET', $resetUrl);
        $crawler = $client->followRedirect();

        $form = $crawler->selectButton('Réinitialiser le mot de passe')->form();
        $form['reset_password_form[plainPassword][first]'] = 'court';
        $form['reset_password_form[plainPassword][second]'] = 'court';
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('body', 'au moins 12 caractères');

        $entityManager->clear();
        $user = $entityManager->getRepository(User::class)->find($user->getId());
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($passwordHasher->isPasswordValid($user, self::OLD_PASSWORD));
    }

    public function testMismatchedNewPasswordsAreRejectedDuringReset(): void
    {
        $client = static::createClient();
        $entityManager = $this->resetDatabaseSchema();
        $this->createUser($entityManager, 'etudiant@lycee-faure.fr', self::OLD_PASSWORD);

        $resetUrl = $this->requestResetLink($client, 'etudiant@lycee-faure.fr');

        $client->request('GET', $resetUrl);
        $crawler = $client->followRedirect();

        $form = $crawler->selectButton('Réinitialiser le mot de passe')->form();
        $form['reset_password_form[plainPassword][first]'] = self::NEW_PASSWORD;
        $form['reset_password_form[plainPassword][second]'] = 'Nouv3au-Mot2Passe-Different!';
        $client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('body', 'Les mots de passe ne correspondent pas');
    }

    public function testInvalidTokenRedirectsToRequestWithError(): void
    {
        $client = static::createClient();
        $this->resetDatabaseSchema();

        $client->request('GET', '/reset-password/reset/jeton-invalide-0123456789abcdef');
        self::assertResponseRedirects('/reset-password/reset');

        $client->followRedirect();
        self::assertResponseRedirects('/reset-password');

        $crawler = $client->followRedirect();
        self::assertSelectorExists('.bg-red-50');
        self::assertSelectorTextContains('body', 'Un problème est survenu');
    }

    public function testResetPageWithoutTokenIsNotFound(): void
    {
        $client = static::createClient();
        $this->resetDatabaseSchema();

        $client->request('GET', '/reset-password/reset');

        self::assertResponseStatusCodeSame(404);
    }

    private function requestResetLink(KernelBrowser $client, string $emailAddress): string
    {
        $crawler = $client->request('GET', '/reset-password');
        $form = $crawler->selectButton('Envoyer le lien de réinitialisation')->form();
        $form['reset_password_request_form[email]'] = $emailAddress;
        $client->submit($form);
        self::assertResponseRedirects('/reset-password/check-email');

        $email = self::getMailerMessage(0);
        $html = (string) $email->getHtmlBody();
        self::assertMatchesRegularExpression('#href="([^"]*/reset-password/reset/[A-Za-z0-9_-]+)"#', $html);
        preg_match('#href="([^"]*/reset-password/reset/[A-Za-z0-9_-]+)"#', $html, $matches);

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
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
