<?php

namespace App\Controller\Api;

use App\Dto\DocumentUpload;
use App\Dto\SignatureRequestConfig;
use App\Dto\SignerInfo;
use App\Service\YouTrustService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * API Controller for YouTrust signature operations.
 * Provides HTTP endpoints for managing signature requests.
 */
#[Route('/api/you-trust', name: 'api_you_trust_')]
class YouTrustController extends AbstractController
{
    public function __construct(
        private YouTrustService $youTrustService,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    // ========================================================================
    // Signature Request Endpoints
    // ========================================================================

    /**
     * Create a new signature request.
     *
     * POST /api/you-trust/signature-requests
     *
     * Request body example:
     * {
     *   "name": "Convention de stage",
     *   "external_id": "conv_2024_001",
     *   "custom_name": "Lycée Gabriel Fauré",
     *   "request_subject": "Convention de stage à signer",
     *   "request_body": "Merci de signer cette convention",
     *   "reminder_subject": "[Rappel] Convention à signer",
     *   "reminder_body": "Vous n'avez pas encore signé"
     * }
     */
    #[Route('/signature-requests', name: 'create_signature_request', methods: ['POST'])]
    public function createSignatureRequest(Request $request): JsonResponse
    {
        if (!$this->youTrustService->isConfigured()) {
            return $this->json([
                'error' => 'YouTrust service is not configured. Please set YOU_TRUST_API_KEY in .env',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $data = json_decode($request->getContent(), true);
            
            $config = new SignatureRequestConfig(
                name: $data['name'] ?? 'Signature Request',
                externalId: $data['external_id'] ?? uniqid('conv_'),
                customName: $data['custom_name'] ?? 'Lycée Gabriel Fauré',
                requestSubject: $data['request_subject'] ?? 'Document à signer',
                requestBody: $data['request_body'] ?? 'Merci de signer ce document',
                reminderSubject: $data['reminder_subject'] ?? '[Rappel] Document à signer',
                reminderBody: $data['reminder_body'] ?? 'Vous n\'avez pas encore signé ce document',
                timezone: $data['timezone'] ?? 'Europe/Paris',
                orderedSigners: $data['ordered_signers'] ?? true,
                signersAllowedToDecline: $data['signers_allowed_to_decline'] ?? false,
                deliveryMode: $data['delivery_mode'] ?? 'email',
                smartAnchorsResolution: $data['smart_anchors_resolution'] ?? 'on_activation',
                auditTrailLocale: $data['audit_trail_locale'] ?? 'fr',
                reminderIntervalInDays: $data['reminder_interval_in_days'] ?? 1,
                maxReminderOccurrences: $data['max_reminder_occurrences'] ?? 5,
            );

            $signatureRequest = $this->youTrustService->initiateSignatureRequest($config);

            return $this->json([
                'success' => true,
                'signature_request' => $signatureRequest,
                'links' => [
                    'upload_document' => $this->urlGenerator->generate(
                        'api_you_trust_upload_document',
                        ['signatureRequestId' => $signatureRequest['id']],
                        UrlGeneratorInterface::ABSOLUTE_URL
                    ),
                    'add_signer' => $this->urlGenerator->generate(
                        'api_you_trust_add_signer',
                        ['signatureRequestId' => $signatureRequest['id']],
                        UrlGeneratorInterface::ABSOLUTE_URL
                    ),
                ],
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * List all signature requests.
     *
     * GET /api/you-trust/signature-requests
     */
    #[Route('/signature-requests', name: 'list_signature_requests', methods: ['GET'])]
    public function listSignatureRequests(Request $request): JsonResponse
    {
        try {
            $queryParameters = $request->query->all();
            $signatureRequests = $this->youTrustService->listSignatureRequests($queryParameters);

            return $this->json([
                'success' => true,
                'signature_requests' => $signatureRequests,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Get a specific signature request.
     *
     * GET /api/you-trust/signature-requests/{signatureRequestId}
     */
    #[Route('/signature-requests/{signatureRequestId}', name: 'get_signature_request', methods: ['GET'])]
    public function getSignatureRequest(string $signatureRequestId): JsonResponse
    {
        try {
            $signatureRequest = $this->youTrustService->getSignatureRequest($signatureRequestId);

            return $this->json([
                'success' => true,
                'signature_request' => $signatureRequest,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Cancel a signature request.
     *
     * POST /api/you-trust/signature-requests/{signatureRequestId}/cancel
     */
    #[Route('/signature-requests/{signatureRequestId}/cancel', name: 'cancel_signature_request', methods: ['POST'])]
    public function cancelSignatureRequest(string $signatureRequestId, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $reason = $data['reason'] ?? null;

            $result = $this->youTrustService->cancelSignatureRequest($signatureRequestId, $reason);

            return $this->json([
                'success' => true,
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Activate a signature request.
     *
     * POST /api/you-trust/signature-requests/{signatureRequestId}/activate
     */
    #[Route('/signature-requests/{signatureRequestId}/activate', name: 'activate_signature_request', methods: ['POST'])]
    public function activateSignatureRequest(string $signatureRequestId): JsonResponse
    {
        try {
            $result = $this->youTrustService->activateSignatureRequest($signatureRequestId);

            return $this->json([
                'success' => true,
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    // ========================================================================
    // Document Endpoints
    // ========================================================================

    /**
     * Upload a document to a signature request.
     *
     * POST /api/you-trust/signature-requests/{signatureRequestId}/documents
     *
     * Multipart form data with:
     * - file: The document file
     * - nature: Document nature (default: signable_document)
     * - parse_anchors: Whether to parse anchors (default: true)
     */
    #[Route('/signature-requests/{signatureRequestId}/documents', name: 'upload_document', methods: ['POST'])]
    public function uploadDocument(string $signatureRequestId, Request $request): JsonResponse
    {
        try {
            $file = $request->files->get('file');
            $nature = $request->request->get('nature', 'signable_document');
            $parseAnchors = $request->request->get('parse_anchors', 'true') === 'true';

            if (!$file) {
                return $this->json([
                    'success' => false,
                    'error' => 'No file uploaded',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Save the file temporarily
            $tempPath = sys_get_temp_dir() . '/' . uniqid('yousign_') . '.' . $file->guessExtension();
            $file->move(sys_get_temp_dir(), basename($tempPath));

            $document = new DocumentUpload($tempPath, $nature, $parseAnchors);
            
            try {
                $result = $this->youTrustService->uploadDocument($signatureRequestId, $document);
            } finally {
                // Clean up temp file
                if (file_exists($tempPath)) {
                    unlink($tempPath);
                }
            }

            return $this->json([
                'success' => true,
                'document' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Upload a document from base64 content.
     *
     * POST /api/you-trust/signature-requests/{signatureRequestId}/documents/base64
     *
     * Request body example:
     * {
     *   "file_name": "document.pdf",
     *   "base64_content": "JVBERi0xLjQK...",
     *   "mime_type": "application/pdf"
     * }
     */
    #[Route('/signature-requests/{signatureRequestId}/documents/base64', name: 'upload_document_base64', methods: ['POST'])]
    public function uploadDocumentBase64(string $signatureRequestId, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (empty($data['base64_content']) || empty($data['file_name'])) {
                return $this->json([
                    'success' => false,
                    'error' => 'Missing file_name or base64_content',
                ], Response::HTTP_BAD_REQUEST);
            }

            $result = $this->youTrustService->uploadDocumentBase64(
                $signatureRequestId,
                $data['file_name'],
                $data['base64_content'],
                $data['mime_type'] ?? 'application/pdf',
                $data['nature'] ?? 'signable_document',
                $data['parse_anchors'] ?? true
            );

            return $this->json([
                'success' => true,
                'document' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * List documents of a signature request.
     *
     * GET /api/you-trust/signature-requests/{signatureRequestId}/documents
     */
    #[Route('/signature-requests/{signatureRequestId}/documents', name: 'list_documents', methods: ['GET'])]
    public function listDocuments(string $signatureRequestId): JsonResponse
    {
        try {
            $documents = $this->youTrustService->listDocuments($signatureRequestId);

            return $this->json([
                'success' => true,
                'documents' => $documents,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Get a specific document.
     *
     * GET /api/you-trust/signature-requests/{signatureRequestId}/documents/{documentId}
     */
    #[Route('/signature-requests/{signatureRequestId}/documents/{documentId}', name: 'get_document', methods: ['GET'])]
    public function getDocument(string $signatureRequestId, string $documentId): JsonResponse
    {
        try {
            $document = $this->youTrustService->getDocument($signatureRequestId, $documentId);

            return $this->json([
                'success' => true,
                'document' => $document,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Delete a document.
     *
     * DELETE /api/you-trust/signature-requests/{signatureRequestId}/documents/{documentId}
     */
    #[Route('/signature-requests/{signatureRequestId}/documents/{documentId}', name: 'delete_document', methods: ['DELETE'])]
    public function deleteDocument(string $signatureRequestId, string $documentId): JsonResponse
    {
        try {
            $result = $this->youTrustService->deleteDocument($signatureRequestId, $documentId);

            return $this->json([
                'success' => true,
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    // ========================================================================
    // Signer Endpoints
    // ========================================================================

    /**
     * Add a signer to a signature request.
     *
     * POST /api/you-trust/signature-requests/{signatureRequestId}/signers
     *
     * Request body example:
     * {
     *   "first_name": "Etienne",
     *   "last_name": "Buffet",
     *   "email": "etienne@example.com",
     *   "phone_number": "+33612345678",
     *   "locale": "fr",
     *   "signature_level": "electronic_signature",
     *   "delivery_mode": "email",
     *   "signature_authentication_mode": "otp_sms"
     * }
     */
    #[Route('/signature-requests/{signatureRequestId}/signers', name: 'add_signer', methods: ['POST'])]
    public function addSigner(string $signatureRequestId, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            $signer = new SignerInfo(
                firstName: $data['first_name'] ?? '',
                lastName: $data['last_name'] ?? '',
                email: $data['email'] ?? '',
                phoneNumber: $data['phone_number'] ?? '',
                locale: $data['locale'] ?? 'fr',
                signatureLevel: $data['signature_level'] ?? 'electronic_signature',
                deliveryMode: $data['delivery_mode'] ?? 'email',
                signatureAuthenticationMode: $data['signature_authentication_mode'] ?? 'otp_sms',
            );

            $result = $this->youTrustService->createSigner($signatureRequestId, $signer);

            return $this->json([
                'success' => true,
                'signer' => $result,
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * List signers of a signature request.
     *
     * GET /api/you-trust/signature-requests/{signatureRequestId}/signers
     */
    #[Route('/signature-requests/{signatureRequestId}/signers', name: 'list_signers', methods: ['GET'])]
    public function listSigners(string $signatureRequestId): JsonResponse
    {
        try {
            $signers = $this->youTrustService->listSigners($signatureRequestId);

            return $this->json([
                'success' => true,
                'signers' => $signers,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Get a specific signer.
     *
     * GET /api/you-trust/signature-requests/{signatureRequestId}/signers/{signerId}
     */
    #[Route('/signature-requests/{signatureRequestId}/signers/{signerId}', name: 'get_signer', methods: ['GET'])]
    public function getSigner(string $signatureRequestId, string $signerId): JsonResponse
    {
        try {
            $signer = $this->youTrustService->getSigner($signatureRequestId, $signerId);

            return $this->json([
                'success' => true,
                'signer' => $signer,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Delete a signer.
     *
     * DELETE /api/you-trust/signature-requests/{signatureRequestId}/signers/{signerId}
     */
    #[Route('/signature-requests/{signatureRequestId}/signers/{signerId}', name: 'delete_signer', methods: ['DELETE'])]
    public function deleteSigner(string $signatureRequestId, string $signerId): JsonResponse
    {
        try {
            $result = $this->youTrustService->deleteSigner($signatureRequestId, $signerId);

            return $this->json([
                'success' => true,
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    // ========================================================================
    // Complete Workflow Endpoints
    // ========================================================================

    /**
     * Create a complete signature request with document and signers.
     * This is the main endpoint that orchestrates the entire signature process.
     *
     * POST /api/you-trust/complete
     *
     * Request body example:
     * {
     *   "signature_config": {
     *     "name": "Convention de stage",
     *     "external_id": "conv_2024_001",
     *     "custom_name": "Lycée Gabriel Fauré",
     *     "request_subject": "Convention à signer",
     *     "request_body": "Merci de signer",
     *     "reminder_subject": "[Rappel] Convention",
     *     "reminder_body": "Rappel signature"
     *   },
     *   "document": {
     *     "base64_content": "JVBERi0xLjQK...",
     *     "file_name": "convention.pdf",
     *     "mime_type": "application/pdf"
     *   },
     *   "signers": [
     *     {
     *       "first_name": "Etienne",
     *       "last_name": "Buffet",
     *       "email": "etienne@example.com",
     *       "phone_number": "+33612345678"
     *     },
     *     {
     *       "first_name": "Jean",
     *       "last_name": "Dupont",
     *       "email": "jean@example.com",
     *       "phone_number": "+33623456789"
     *     }
     *   ],
     *   "activate_immediately": true
     * }
     */
    #[Route('/complete', name: 'create_complete_signature_request', methods: ['POST'])]
    public function createCompleteSignatureRequest(Request $request): JsonResponse
    {
        if (!$this->youTrustService->isConfigured()) {
            return $this->json([
                'error' => 'YouTrust service is not configured. Please set YOU_TRUST_API_KEY in .env',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $data = json_decode($request->getContent(), true);

            // Validate required fields
            if (empty($data['signature_config']) || empty($data['document']) || empty($data['signers'])) {
                return $this->json([
                    'success' => false,
                    'error' => 'Missing required fields: signature_config, document, or signers',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Create signature request config
            $signatureConfig = new SignatureRequestConfig(
                name: $data['signature_config']['name'] ?? 'Signature Request',
                externalId: $data['signature_config']['external_id'] ?? uniqid('conv_'),
                customName: $data['signature_config']['custom_name'] ?? 'Lycée Gabriel Fauré',
                requestSubject: $data['signature_config']['request_subject'] ?? 'Document à signer',
                requestBody: $data['signature_config']['request_body'] ?? 'Merci de signer ce document',
                reminderSubject: $data['signature_config']['reminder_subject'] ?? '[Rappel] Document à signer',
                reminderBody: $data['signature_config']['reminder_body'] ?? 'Vous n\'avez pas encore signé',
                timezone: $data['signature_config']['timezone'] ?? 'Europe/Paris',
                orderedSigners: $data['signature_config']['ordered_signers'] ?? true,
                signersAllowedToDecline: $data['signature_config']['signers_allowed_to_decline'] ?? false,
                deliveryMode: $data['signature_config']['delivery_mode'] ?? 'email',
                smartAnchorsResolution: $data['signature_config']['smart_anchors_resolution'] ?? 'on_activation',
                auditTrailLocale: $data['signature_config']['audit_trail_locale'] ?? 'fr',
                reminderIntervalInDays: $data['signature_config']['reminder_interval_in_days'] ?? 1,
                maxReminderOccurrences: $data['signature_config']['max_reminder_occurrences'] ?? 5,
            );

            // Create signers
            $signers = [];
            foreach ($data['signers'] as $signerData) {
                $signers[] = new SignerInfo(
                    firstName: $signerData['first_name'] ?? '',
                    lastName: $signerData['last_name'] ?? '',
                    email: $signerData['email'] ?? '',
                    phoneNumber: $signerData['phone_number'] ?? '',
                    locale: $signerData['locale'] ?? 'fr',
                    signatureLevel: $signerData['signature_level'] ?? 'electronic_signature',
                    deliveryMode: $signerData['delivery_mode'] ?? 'email',
                    signatureAuthenticationMode: $signerData['signature_authentication_mode'] ?? 'otp_sms',
                );
            }

            // Create the complete signature request
            $activateImmediately = $data['activate_immediately'] ?? true;
            
            $result = $this->youTrustService->createCompleteSignatureRequestBase64(
                $signatureConfig,
                $data['document']['file_name'],
                $data['document']['base64_content'],
                $data['document']['mime_type'] ?? 'application/pdf',
                $signers,
                $activateImmediately
            );

            return $this->json([
                'success' => true,
                'signature_request' => $result,
                'message' => 'Signature request created successfully',
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $this->getParameter('kernel.debug') ? $e->getTrace() : null,
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Get service configuration.
     *
     * GET /api/you-trust/config
     */
    #[Route('/config', name: 'get_config', methods: ['GET'])]
    public function getConfig(): JsonResponse
    {
        $config = $this->youTrustService->getConfig();

        return $this->json([
            'configured' => $this->youTrustService->isConfigured(),
            'api_url' => $config->apiUrl,
            'api_version' => $config->apiVersion,
            'timeout' => $config->timeout,
        ]);
    }
}
