<?php

namespace App\Controller;

use App\Dto\DocumentUpload;
use App\Dto\SignatureRequestConfig;
use App\Dto\SignerInfo;
use App\Exception\YouTrustApiException;
use App\Service\YouTrustService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Test controller for YouTrust API service.
 * Provides web interface for testing signature request creation.
 */
#[Route('/test/you-trust', name: 'test_you_trust_')]
class YouTrustTestController extends AbstractController
{
    public function __construct(
        private YouTrustService $youTrustService,
    ) {
    }

    /**
     * Main test page for YouTrust service.
     * Displays form for creating a complete signature request.
     */
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->youTrustService->isConfigured()) {
            $this->addFlash('error', 'Le service YouTrust n\'est pas configuré. Définissez YOU_TRUST_API_KEY dans votre .env');
        }

        // Create default signer entries (3 signers as requested)
        $defaultSigners = [
            [
                'first_name' => 'Etienne',
                'last_name' => 'Buffet',
                'email' => 'conventio-test-01@lycee-faure.fr',
                'phone_number' => '+33667039604',
            ],
            [
                'first_name' => 'Jean',
                'last_name' => 'Dupont',
                'email' => 'conventio-test-02@lycee-faure.fr',
                'phone_number' => '+33667039604',
            ],
            [
                'first_name' => 'Marie',
                'last_name' => 'Martin',
                'email' => 'conventio-test-03@lycee-faure.fr',
                'phone_number' => '+33667039604',
            ],
        ];

        return $this->render('you_trust_test/index.html.twig', [
            'defaultSigners' => $defaultSigners,
            'isConfigured' => $this->youTrustService->isConfigured(),
        ]);
    }

    /**
     * Create a complete signature request from form submission.
     * Handles file upload and multiple signers.
     */
    #[Route('/create', name: 'create_signature_request', methods: ['POST'])]
    public function createSignatureRequest(Request $request): Response
    {
        if (!$this->youTrustService->isConfigured()) {
            $this->addFlash('error', 'Le service YouTrust n\'est pas configuré. Définissez YOU_TRUST_API_KEY dans votre .env');
            return $this->redirectToRoute('test_you_trust_index');
        }

        try {
            // Get form data
            $name = $request->request->get('name', 'Test Signature Request');
            $externalId = $request->request->get('external_id', uniqid('conv_'));
            $customName = $request->request->get('custom_name', 'Lycée Gabriel Fauré');
            $requestSubject = $request->request->get('request_subject', 'Convention de stage à signer');
            $requestBody = $request->request->get('request_body', 'Merci de signer cette convention de stage');
            $reminderSubject = $request->request->get('reminder_subject', '[Rappel]: Convention de stage à signer');
            $reminderBody = $request->request->get('reminder_body', 'Vous n\'avez pas eu le temps de signer?');
            $orderedSigners = $request->request->getBoolean('ordered_signers', true);
            $signersAllowedToDecline = $request->request->getBoolean('signers_allowed_to_decline', false);
            $activateImmediately = $request->request->getBoolean('activate_immediately', true);

            // Get document file
            /** @var UploadedFile|null $documentFile */
            $documentFile = $request->files->get('document');

            if (!$documentFile || !$documentFile->isValid()) {
                $this->addFlash('error', 'Veuillez téléverser un document PDF valide');
                return $this->redirectToRoute('test_you_trust_index');
            }

            // Save the uploaded file temporarily
            $tempPath = sys_get_temp_dir() . '/' . uniqid('you_trust_') . '.pdf';
            $movedFile = $documentFile->move(sys_get_temp_dir(), basename($tempPath));

            // Verify the file was moved successfully
            if (!$movedFile || !file_exists($tempPath)) {
                $this->addFlash('error', 'Échec de l\'enregistrement du document téléversé');
                return $this->redirectToRoute('test_you_trust_index');
            }

            // Create document DTO
            $document = new DocumentUpload(
                filePath: $tempPath,
                nature: 'signable_document',
                parseAnchors: $request->request->getBoolean('parse_anchors', true)
            );

            // Create signature request config
            $signatureConfig = new SignatureRequestConfig(
                name: $name,
                externalId: $externalId,
                customName: $customName,
                requestSubject: $requestSubject,
                requestBody: $requestBody,
                reminderSubject: $reminderSubject,
                reminderBody: $reminderBody,
                timezone: $request->request->get('timezone', 'Europe/Paris'),
                orderedSigners: $orderedSigners,
                signersAllowedToDecline: $signersAllowedToDecline,
                deliveryMode: $request->request->get('delivery_mode', 'email'),
                smartAnchorsResolution: $request->request->get('smart_anchors_resolution', 'on_activation'),
                auditTrailLocale: $request->request->get('audit_trail_locale', 'fr'),
                reminderIntervalInDays: (int)$request->request->get('reminder_interval_in_days', 1),
                maxReminderOccurrences: (int)$request->request->get('max_reminder_occurrences', 5),
            );

            // Create signers from form data
            $signers = [];
            $signerCount = (int)$request->request->get('signer_count', 3);

            for ($i = 0; $i < $signerCount; $i++) {
                $prefix = 'signer_' . $i . '_';

                $firstName = $request->request->get($prefix . 'first_name');
                $lastName = $request->request->get($prefix . 'last_name');
                $email = $request->request->get($prefix . 'email');
                $phoneNumber = $request->request->get($prefix . 'phone_number');

                if (empty($firstName) || empty($lastName) || empty($email)) {
                    continue; // Skip incomplete signers
                }

                $signers[] = new SignerInfo(
                    firstName: $firstName,
                    lastName: $lastName,
                    email: $email,
                    phoneNumber: $phoneNumber,
                    locale: $request->request->get($prefix . 'locale', 'fr'),
                    signatureLevel: $request->request->get($prefix . 'signature_level', 'electronic_signature'),
                    deliveryMode: $request->request->get($prefix . 'delivery_mode', 'email'),
                    signatureAuthenticationMode: $request->request->get($prefix . 'signature_authentication_mode', 'otp_sms'),
                );
            }

            if (empty($signers)) {
                $this->addFlash('error', 'Au moins un signataire est requis');
                return $this->redirectToRoute('test_you_trust_index');
            }

            // Create the complete signature request
            $result = $this->youTrustService->createCompleteSignatureRequest(
                $signatureConfig,
                $document,
                $signers,
                $activateImmediately
            );

            // Clean up temp file
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }

            // Store result in session for display
            $request->getSession()->set('you_trust_last_result', [
                'success' => true,
                'result' => $result,
                'config' => [
                    'name' => $name,
                    'external_id' => $externalId,
                    'signer_count' => count($signers),
                    'activate_immediately' => $activateImmediately,
                ],
            ]);

            $this->addFlash('success', 'Demande de signature créée avec succès !');
            return $this->redirectToRoute('test_you_trust_result');

        } catch (YouTrustApiException $e) {
            $errorDetails = $e->getErrorDetails();
            $errorMessage = $e->getMessage();
            if ($errorDetails) {
                $errorMessage .= ' - ' . ($errorDetails['message'] ?? json_encode($errorDetails));
            }
            $this->addFlash('error', $errorMessage);
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('test_you_trust_index');
    }

    /**
     * Display the result of the signature request creation.
     */
    #[Route('/result', name: 'result', methods: ['GET'])]
    public function result(Request $request): Response
    {
        $result = $request->getSession()->get('you_trust_last_result');

        if (!$result || !isset($result['success']) || !$result['success']) {
            $this->addFlash('error', 'Aucun résultat à afficher');
            return $this->redirectToRoute('test_you_trust_index');
        }

        return $this->render('you_trust_test/result.html.twig', [
            'result' => $result['result'],
            'config' => $result['config'],
        ]);
    }

    /**
     * Simple upload test endpoint for testing file upload separately.
     */
    #[Route('/upload-test', name: 'upload_test', methods: ['GET', 'POST'])]
    public function uploadTest(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            /** @var UploadedFile|null $file */
            $file = $request->files->get('test_file');

            if ($file && $file->isValid()) {
                // Get file info BEFORE moving it
                $fileName = $file->getClientOriginalName();
                $fileSize = $file->getSize();
                $fileMime = $file->getClientMimeType();

                $tempPath = sys_get_temp_dir() . '/' . uniqid('test_') . '.' . $file->guessExtension();
                $movedFile = $file->move(sys_get_temp_dir(), basename($tempPath));

                // Verify the file was moved successfully
                if (!$movedFile || !file_exists($tempPath)) {
                    $this->addFlash('error', 'Échec de l\'enregistrement du fichier téléversé');
                } else {
                    $this->addFlash('success', sprintf(
                        'Fichier téléversé avec succès ! Nom : %s, taille : %s, MIME : %s',
                        $fileName,
                        $this->formatBytes($fileSize),
                        $fileMime
                    ));
                }

                return $this->redirectToRoute('test_you_trust_upload_test');
            }
        }

        return $this->render('you_trust_test/upload_test.html.twig');
    }

    /**
     * Test API connectivity.
     */
    #[Route('/ping', name: 'ping', methods: ['GET'])]
    public function ping(): Response
    {
        try {
            $config = $this->youTrustService->getConfig();

            return $this->render('you_trust_test/ping.html.twig', [
                'isConfigured' => $this->youTrustService->isConfigured(),
                'config' => $config,
            ]);
        } catch (\Exception $e) {
            return $this->render('you_trust_test/ping.html.twig', [
                'isConfigured' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * List existing signature requests.
     */
    #[Route('/list', name: 'list', methods: ['GET'])]
    public function listSignatureRequests(Request $request): Response
    {
        if (!$this->youTrustService->isConfigured()) {
            $this->addFlash('error', 'Le service YouTrust n\'est pas configuré');
            return $this->redirectToRoute('test_you_trust_index');
        }

        try {
            $signatureRequests = $this->youTrustService->listSignatureRequests();

            return $this->render('you_trust_test/list.html.twig', [
                'signatureRequests' => $signatureRequests['signature_requests'] ?? [],
            ]);
        } catch (YouTrustApiException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('test_you_trust_index');
        }
    }

    /**
     * Activate a signature request from the test list page.
     */
    #[Route('/activate/{signatureRequestId}', name: 'activate_signature_request', methods: ['POST'])]
    public function activateSignatureRequest(string $signatureRequestId): Response
    {
        try {
            $this->youTrustService->activateSignatureRequest($signatureRequestId);
            $this->addFlash('success', sprintf('Demande de signature %s activée', $signatureRequestId));
        } catch (YouTrustApiException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('test_you_trust_list');
    }

    /**
     * Cancel a signature request from the test list page.
     */
    #[Route('/cancel/{signatureRequestId}', name: 'cancel_signature_request', methods: ['POST'])]
    public function cancelSignatureRequest(string $signatureRequestId): Response
    {
        try {
            $this->youTrustService->cancelSignatureRequest($signatureRequestId);
            $this->addFlash('success', sprintf('Demande de signature %s annulée', $signatureRequestId));
        } catch (YouTrustApiException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('test_you_trust_list');
    }

    /**
     * Delete a signature request from the test list page.
     */
    #[Route('/delete/{signatureRequestId}', name: 'delete_signature_request', methods: ['POST'])]
    public function deleteSignatureRequest(string $signatureRequestId): Response
    {
        try {
            $this->youTrustService->deleteSignatureRequest($signatureRequestId);
            $this->addFlash('success', sprintf('Demande de signature %s supprimée', $signatureRequestId));
        } catch (YouTrustApiException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('test_you_trust_list');
    }

    /**
     * Helper function to format bytes.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
