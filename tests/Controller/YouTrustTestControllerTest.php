<?php

namespace App\Tests\Controller;

use App\Dto\YouTrustConfig;
use App\Service\YouTrustService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

class YouTrustTestControllerTest extends WebTestCase
{
    private function mockYouTrustService(KernelBrowser $client, bool $configured = true, array $signatureRequests = []): void
    {
        $service = $this->createStub(YouTrustService::class);
        $service->method('isConfigured')->willReturn($configured);
        $service->method('getConfig')->willReturn(new YouTrustConfig(apiKey: $configured ? 'sk-test' : ''));
        $service->method('listSignatureRequests')->willReturn(['signature_requests' => $signatureRequests]);
        static::getContainer()->set(YouTrustService::class, $service);
    }

    public function testIndexPageRendersFormWithTailwindAndStimulus(): void
    {
        $client = static::createClient();
        $this->mockYouTrustService($client);
        $crawler = $client->request('GET', '/test/you-trust/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'YouTrust');

        // Navigation commune aux pages de test (nav.mb-8 = nav interne de la page, hors navbar globale)
        self::assertCount(4, $crawler->filter('nav.mb-8 a'));

        // Formulaire de création
        self::assertCount(1, $crawler->filter('form[action="/test/you-trust/create"]'));
        self::assertCount(1, $crawler->filter('input[name="document"]'));

        // 3 signataires par défaut dans le conteneur, pilotés par le contrôleur Stimulus "signers"
        // (le <template> contient une 4e ligne avec placeholders, exclue ici)
        self::assertCount(1, $crawler->filter('[data-controller~="signers"]'));
        self::assertCount(3, $crawler->filter('[data-signers-target~="container"] [data-signers-target~="signer"]'));
        self::assertCount(1, $crawler->filter('template [data-signers-target~="signer"]'));
        self::assertCount(1, $crawler->filter('input[name="signer_0_first_name"][value="Etienne"]'));

        // Le <template> Stimulus embarque les placeholders __INDEX__ / __NUM__
        self::assertStringContainsString('__INDEX__', $client->getResponse()->getContent());
        self::assertStringContainsString('__NUM__', $client->getResponse()->getContent());
    }

    public function testIndexPageShowsWarningWhenServiceNotConfigured(): void
    {
        $client = static::createClient();
        $this->mockYouTrustService($client, configured: false);
        $client->request('GET', '/test/you-trust/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('YOU_TRUST_API_KEY', $client->getResponse()->getContent());
    }

    public function testPingPageDisplaysApiConfiguration(): void
    {
        $client = static::createClient();
        $this->mockYouTrustService($client);
        $client->request('GET', '/test/you-trust/ping');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'connectivité');
        self::assertStringContainsString('Configurée (masquée)', $client->getResponse()->getContent());
    }

    public function testUploadTestPageUsesStimulusDropzone(): void
    {
        $client = static::createClient();
        $this->mockYouTrustService($client);
        $crawler = $client->request('GET', '/test/you-trust/upload-test');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller~="upload"]'));
        self::assertCount(1, $crawler->filter('input[name="test_file"]'));
    }

    public function testListPageRendersSignatureRequests(): void
    {
        $client = static::createClient();
        $this->mockYouTrustService($client, signatureRequests: [
            ['id' => 'sr_1', 'name' => 'Demande A', 'external_id' => 'ext-1', 'status' => 'draft', 'created_at' => '2026-11-05T10:00:00+00:00', 'documents' => [[]], 'signers' => [[], []]],
            ['id' => 'sr_2', 'name' => 'Demande B', 'external_id' => null, 'status' => 'activated', 'created_at' => '2026-11-04T09:00:00+00:00', 'documents' => [], 'signers' => []],
        ]);
        $crawler = $client->request('GET', '/test/you-trust/list');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Demandes de signature');
        self::assertStringContainsString('Demande A', $client->getResponse()->getContent());

        // Un brouillon = un bouton "Activer" ; la demande activée n'en a pas
        self::assertCount(1, $crawler->filter('button:contains("Activer")'));
    }

    public function testListPageShowsEmptyState(): void
    {
        $client = static::createClient();
        $this->mockYouTrustService($client, signatureRequests: []);
        $client->request('GET', '/test/you-trust/list');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aucune demande de signature trouvée');
    }

    public function testResultPageRedirectsWithoutStoredResult(): void
    {
        $client = static::createClient();
        $this->mockYouTrustService($client);
        $client->request('GET', '/test/you-trust/result');

        self::assertResponseRedirects('/test/you-trust/');
    }

    public function testCreateWithoutDocumentRedirectsWithErrorFlash(): void
    {
        $client = static::createClient();
        $this->mockYouTrustService($client);
        $client->request('POST', '/test/you-trust/create', ['name' => 'Test']);

        self::assertResponseRedirects('/test/you-trust/');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Veuillez téléverser un document PDF valide');
    }

    public function testResultTemplateRendersSummaryAndClipboardController(): void
    {
        self::bootKernel();
        self::getContainer()->get('request_stack')->push(Request::create('/test/you-trust/result'));

        $twig = self::getContainer()->get('twig');
        $html = $twig->render('you_trust_test/result.html.twig', [
            'result' => [
                'id' => 'sr_123',
                'name' => 'Convention de stage',
                'external_id' => 'conv_1',
                'status' => 'draft',
                'documents' => [
                    ['id' => 'doc_1', 'name' => 'conv.pdf', 'nature' => 'signable_document', 'status' => 'active'],
                ],
                'signers' => [
                    ['id' => 'si_1', 'first_name' => 'Jean', 'last_name' => 'Dupont', 'email' => 'jean@example.fr', 'phone_number' => '+33600000000', 'status' => 'pending', 'info' => ['signing_order' => 1]],
                ],
            ],
            'config' => ['signer_count' => 1],
        ]);

        self::assertStringContainsString('data-controller="clipboard"', $html);
        self::assertStringContainsString('sr_123', $html);
        self::assertStringContainsString('conv.pdf', $html);
        self::assertStringContainsString('Jean Dupont', $html);
    }
}
