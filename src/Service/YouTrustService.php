<?php

namespace App\Service;

use App\Dto\DocumentUpload;
use App\Dto\SignatureRequestConfig;
use App\Dto\SignerInfo;
use App\Dto\YouTrustConfig;
use App\Exception\YouTrustApiException;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Service for interacting with YouTrust (ex-YouSign) API.
 * Provides methods for managing signature requests, documents, and signers.
 */
class YouTrustService
{
    private HttpClientInterface $httpClient;
    private YouTrustConfig $config;

    /**
     * @param HttpClientInterface $httpClient Symfony HTTP client
     * @param YouTrustConfig $config YouTrust API configuration
     */
    public function __construct(
        HttpClientInterface $httpClient,
        YouTrustConfig $config
    ) {
        $this->httpClient = $httpClient;
        $this->config = $config;
    }

    // ========================================================================
    // Signature Request Methods
    // ========================================================================

    /**
     * Initiate a new Signature Request.
     * Handles YouTrust response structure: {meta: {...}, data: {...}}
     *
     * @param SignatureRequestConfig $config Configuration for the signature request
     * @return array Normalized response containing signature request data
     * @throws YouTrustApiException When API request fails
     */
    public function initiateSignatureRequest(SignatureRequestConfig $config): array
    {
        $url = $this->config->getBaseUrl() . '/signature_requests';

        $response = $this->request('POST', $url, $config->toArray());
        $result = $this->handleResponse($response);

        // YouTrust returns data in a 'data' field
        return $result['data'] ?? $result;
    }

    /**
     * List all Signature Requests.
     * Handles YouTrust response structure: {meta: {...}, data: [...]}
     *
     * @param array $queryParameters Optional query parameters (page, per_page, etc.)
     * @return array Normalized response with 'signature_requests' and 'meta'
     * @throws YouTrustApiException When API request fails
     */
    public function listSignatureRequests(array $queryParameters = []): array
    {
        $url = $this->config->getBaseUrl() . '/signature_requests';

        if (!empty($queryParameters)) {
            $url .= '?' . http_build_query($queryParameters);
        }

        $response = $this->request('GET', $url);
        $result = $this->handleResponse($response);

        // YouTrust returns data in a 'data' field: {meta: {...}, data: [...]}
        return [
            'signature_requests' => $result['data'] ?? $result,
            'meta' => $result['meta'] ?? null,
        ];
    }

    /**
     * Get a specific Signature Request by ID.
     * Handles YouTrust response structure: {meta: {...}, data: {...}}
     *
     * @param string $signatureRequestId ID of the signature request
     * @return array Normalized response containing signature request data
     * @throws YouTrustApiException When API request fails
     */
    public function getSignatureRequest(string $signatureRequestId): array
    {
        $url = $this->config->getBaseUrl() . '/signature_requests/' . $signatureRequestId;

        $response = $this->request('GET', $url);
        $result = $this->handleResponse($response);

        // YouTrust returns data in a 'data' field for single requests too
        return $result['data'] ?? $result;
    }

    /**
     * Cancel a Signature Request.
     * Handles YouTrust response structure: {meta: {...}, data: {...}}
     *
     * @param string $signatureRequestId ID of the signature request to cancel
     * @param string|null $reason Optional reason for cancellation
     * @return array Normalized response
     * @throws YouTrustApiException When API request fails
     */
    public function cancelSignatureRequest(string $signatureRequestId, ?string $reason = null): array
    {
        $url = $this->config->getBaseUrl() . '/signature_requests/' . $signatureRequestId . '/cancel';

        $body = [];
        if (null !== $reason) {
            $body['reason'] = $reason;
        }

        $response = $this->request('POST', $url, $body);
        $result = $this->handleResponse($response);

        // YouTrust returns data in a 'data' field
        return $result['data'] ?? $result;
    }

    /**
     * Activate a Signature Request.
     * Handles YouTrust response structure: {meta: {...}, data: {...}}
     *
     * @param string $signatureRequestId ID of the signature request to activate
     * @return array Normalized response
     * @throws YouTrustApiException When API request fails
     */
    public function activateSignatureRequest(string $signatureRequestId): array
    {
        $url = $this->config->getBaseUrl() . '/signature_requests/' . $signatureRequestId . '/activate';

        $response = $this->request('POST', $url);
        $result = $this->handleResponse($response);

        // YouTrust returns data in a 'data' field
        return $result['data'] ?? $result;
    }

    /**
     * Delete a Signature Request.
     * Only possible when the Signature Request is not in approval or ongoing status.
     *
     * @param string $signatureRequestId ID of the signature request to delete
     * @return array Normalized response
     * @throws YouTrustApiException When API request fails
     */
    public function deleteSignatureRequest(string $signatureRequestId): array
    {
        $url = $this->config->getBaseUrl() . '/signature_requests/' . $signatureRequestId;

        $response = $this->request('DELETE', $url);
        $result = $this->handleResponse($response);

        // YouTrust returns data in a 'data' field
        return $result['data'] ?? $result;
    }

    // ========================================================================
    // Document Methods
    // ========================================================================

    /**
     * Upload a document to a Signature Request.
     * Handles YouTrust response structure: {meta: {...}, data: {...}}
     *
     * @param string $signatureRequestId ID of the signature request
     * @param DocumentUpload $document Document to upload
     * @return array Normalized response containing document data
     * @throws YouTrustApiException When API request fails
     */
    public function uploadDocument(string $signatureRequestId, DocumentUpload $document): array
    {
        $url = $this->config->getBaseUrl() . '/signature_requests/' . $signatureRequestId . '/documents';
        $fileContent = base64_decode(explode(',', $document->getDataUri())[1]);

        $formData = new FormDataPart([
            'file' => new DataPart($fileContent, $document->getFileName(), $document->getMimeType()),
            'nature' => $document->nature,
            'parse_anchors' => $document->parseAnchors ? 'true' : 'false',
        ]);

        $response = $this->httpClient->request('POST', $url, [
            'headers' => array_merge(
                $formData->getPreparedHeaders()->toArray(),
                [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->config->apiKey,
                ]
            ),
            'timeout' => $this->config->timeout,
            'body' => $formData->bodyToIterable(),
        ]);
        $result = $this->handleResponse($response);

        return $result['data'] ?? $result;
    }

    /**
     * Upload a document from raw base64 content.
     * Handles YouTrust response structure: {meta: {...}, data: {...}}
     *
     * @param string $signatureRequestId ID of the signature request
     * @param string $fileName Name of the file
     * @param string $base64Content Base64 encoded file content
     * @param string $mimeType MIME type of the file
     * @param string $nature Nature of the document (default: 'signable_document')
     * @param bool $parseAnchors Whether to parse anchors (default: true)
     * @return array Normalized response containing document data
     * @throws YouTrustApiException When API request fails
     */
    public function uploadDocumentBase64(
        string $signatureRequestId,
        string $fileName,
        string $base64Content,
        string $mimeType = 'application/pdf',
        string $nature = 'signable_document',
        bool $parseAnchors = true
    ): array {
        $url = $this->config->getBaseUrl() . '/signature_requests/' . $signatureRequestId . '/documents';
        $fileContent = base64_decode($base64Content);

        $formData = new FormDataPart([
            'file' => new DataPart($fileContent, $fileName, $mimeType),
            'nature' => $nature,
            'parse_anchors' => $parseAnchors ? 'true' : 'false',
        ]);

        $response = $this->httpClient->request('POST', $url, [
            'headers' => array_merge(
                $formData->getPreparedHeaders()->toArray(),
                [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->config->apiKey,
                ]
            ),
            'timeout' => $this->config->timeout,
            'body' => $formData->bodyToIterable(),
        ]);
        $result = $this->handleResponse($response);

        return $result['data'] ?? $result;
    }

    /**
     * List documents of a Signature Request.
     * Handles YouTrust response structure: {meta: {...}, data: [...]}
     *
     * @param string $signatureRequestId ID of the signature request
     * @return array Normalized response with 'documents' and 'meta'
     * @throws YouTrustApiException When API request fails
     */
    public function listDocuments(string $signatureRequestId): array
    {
        $url = $this->config->getBaseUrl() . '/signature_requests/' . $signatureRequestId . '/documents';

        $response = $this->request('GET', $url);
        $result = $this->handleResponse($response);

        // YouTrust returns data in a 'data' field
        return [
            'documents' => $result['data'] ?? $result,
            'meta' => $result['meta'] ?? null,
        ];
    }

    /**
     * Get a specific document from a Signature Request.
     * Handles YouTrust response structure: {meta: {...}, data: {...}}
     *
     * @param string $signatureRequestId ID of the signature request
     * @param string $documentId ID of the document
     * @return array Normalized response containing document data
     * @throws YouTrustApiException When API request fails
     */
    public function getDocument(string $signatureRequestId, string $documentId): array
    {
        $url = $this->config->getBaseUrl() . '/signature_requests/' . $signatureRequestId . '/documents/' . $documentId;

        $response = $this->request('GET', $url);
        $result = $this->handleResponse($response);

        // YouTrust returns data in a 'data' field
        return $result['data'] ?? $result;
    }

    /**
     * Delete a document from a Signature Request.
     * Handles YouTrust response structure: {meta: {...}, data: {...}}
     *
     * @param string $signatureRequestId ID of the signature request
     * @param string $documentId ID of the document to delete
     * @return array Normalized response
     * @throws YouTrustApiException When API request fails
     */
    public function deleteDocument(string $signatureRequestId, string $documentId): array
    {
        $url = $this->config->getBaseUrl() . '/signature_requests/' . $signatureRequestId . '/documents/' . $documentId;

        $response = $this->request('DELETE', $url);
        $result = $this->handleResponse($response);

        // YouTrust returns data in a 'data' field
        return $result['data'] ?? $result;
    }

    // ========================================================================
    // Signer Methods
    // ========================================================================

    /**
     * Create a new Signer in a Signature Request.
     * Handles YouTrust response structure: {meta: {...}, data: {...}}
     *
     * @param string $signatureRequestId ID of the signature request
     * @param SignerInfo $signer Signer information
     * @return array Normalized response containing signer data
     * @throws YouTrustApiException When API request fails
     */
    public function createSigner(string $signatureRequestId, SignerInfo $signer): array
    {
        $url = $this->config->getBaseUrl() . '/signature_requests/' . $signatureRequestId . '/signers';

        $response = $this->request('POST', $url, $signer->toArray());
        $result = $this->handleResponse($response);

        // YouTrust returns data in a 'data' field
        return $result['data'] ?? $result;
    }

    /**
     * List all Signers of a Signature Request.
     * Handles YouTrust response structure: {meta: {...}, data: [...]}
     *
     * @param string $signatureRequestId ID of the signature request
     * @return array Normalized response with 'signers' and 'meta'
     * @throws YouTrustApiException When API request fails
     */
    public function listSigners(string $signatureRequestId): array
    {
        $url = $this->config->getBaseUrl() . '/signature_requests/' . $signatureRequestId . '/signers';

        $response = $this->request('GET', $url);
        $result = $this->handleResponse($response);

        // YouTrust returns data in a 'data' field
        return [
            'signers' => $result['data'] ?? $result,
            'meta' => $result['meta'] ?? null,
        ];
    }

    /**
     * Get a specific Signer from a Signature Request.
     * Handles YouTrust response structure: {meta: {...}, data: {...}}
     *
     * @param string $signatureRequestId ID of the signature request
     * @param string $signerId ID of the signer
     * @return array Normalized response containing signer data
     * @throws YouTrustApiException When API request fails
     */
    public function getSigner(string $signatureRequestId, string $signerId): array
    {
        $url = $this->config->getBaseUrl() . '/signature_requests/' . $signatureRequestId . '/signers/' . $signerId;

        $response = $this->request('GET', $url);
        $result = $this->handleResponse($response);

        // YouTrust returns data in a 'data' field
        return $result['data'] ?? $result;
    }

    /**
     * Delete a Signer from a Signature Request.
     * Handles YouTrust response structure: {meta: {...}, data: {...}}
     *
     * @param string $signatureRequestId ID of the signature request
     * @param string $signerId ID of the signer to delete
     * @return array Normalized response
     * @throws YouTrustApiException When API request fails
     */
    public function deleteSigner(string $signatureRequestId, string $signerId): array
    {
        $url = $this->config->getBaseUrl() . '/signature_requests/' . $signatureRequestId . '/signers/' . $signerId;

        $response = $this->request('DELETE', $url);
        $result = $this->handleResponse($response);

        // YouTrust returns data in a 'data' field
        return $result['data'] ?? $result;
    }

    // ========================================================================
    // Complete Workflow Methods
    // ========================================================================

    /**
     * Create a complete signature request with document, signers, and activation.
     * This is the main method that orchestrates the entire signature process.
     * Handles YouTrust response structure: {meta: {...}, data: {...}}
     *
     * @param SignatureRequestConfig $signatureConfig Configuration for the signature request
     * @param DocumentUpload $document Document to upload
     * @param array<SignerInfo> $signers Array of signers (up to 3 typically)
     * @param bool $activateImmediately Whether to activate the request immediately (default: true)
     * @return array Normalized response containing the complete signature request
     * @throws YouTrustApiException When any step in the process fails
     */
    public function createCompleteSignatureRequest(
        SignatureRequestConfig $signatureConfig,
        DocumentUpload $document,
        array $signers = [],
        bool $activateImmediately = true
    ): array {
        // Step 1: Initiate the signature request
        $signatureRequest = $this->initiateSignatureRequest($signatureConfig);
        $signatureRequestId = $signatureRequest['id'] ?? null;

        if (null === $signatureRequestId) {
            throw new YouTrustApiException('Failed to create signature request: missing ID in response');
        }

        // Step 2: Upload the document
        $response = $this->uploadDocument($signatureRequestId, $document);

        // Step 3: Add all signers (signing order is handled by ordered_signers on the signature request)
        foreach ($signers as $signer) {
            $this->createSigner($signatureRequestId, $signer);
        }

        // Step 4: Activate the signature request if requested
        if ($activateImmediately) {
            $this->activateSignatureRequest($signatureRequestId);
        }

        // Return the complete signature request
        return $this->getSignatureRequest($signatureRequestId);
    }

    /**
     * Create a complete signature request with base64 document.
     * Handles YouTrust response structure: {meta: {...}, data: {...}}
     *
     * @param SignatureRequestConfig $signatureConfig Configuration for the signature request
     * @param string $fileName Name of the file
     * @param string $base64Content Base64 encoded file content
     * @param string $mimeType MIME type of the file
     * @param array<SignerInfo> $signers Array of signers
     * @param bool $activateImmediately Whether to activate immediately
     * @return array Normalized response containing the complete signature request
     * @throws YouTrustApiException When any step fails
     */
    public function createCompleteSignatureRequestBase64(
        SignatureRequestConfig $signatureConfig,
        string $fileName,
        string $base64Content,
        string $mimeType = 'application/pdf',
        array $signers = [],
        bool $activateImmediately = true
    ): array {
        // Step 1: Initiate the signature request
        $signatureRequest = $this->initiateSignatureRequest($signatureConfig);
        $signatureRequestId = $signatureRequest['id'] ?? null;

        if (null === $signatureRequestId) {
            throw new YouTrustApiException('Failed to create signature request: missing ID in response');
        }

        // Step 2: Upload the document from base64
        $this->uploadDocumentBase64(
            $signatureRequestId,
            $fileName,
            $base64Content,
            $mimeType
        );

        // Step 3: Add all signers (signing order is handled by ordered_signers on the signature request)
        foreach ($signers as $signer) {
            $this->createSigner($signatureRequestId, $signer);
        }

        // Step 4: Activate if requested
        if ($activateImmediately) {
            $this->activateSignatureRequest($signatureRequestId);
        }

        return $this->getSignatureRequest($signatureRequestId);
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    /**
     * Make an HTTP request to the YouTrust API.
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param string $url Full URL for the request
     * @param array|null $body Request body (sent as JSON)
     * @param array $options Additional options for the HTTP client
     * @return ResponseInterface The HTTP response
     */
    private function request(
        string $method,
        string $url,
        ?array $body = null,
        array $options = []
    ): ResponseInterface {
        $defaultOptions = [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->config->apiKey,
            ],
            'timeout' => $this->config->timeout,
        ];

        if (null !== $body) {
            $defaultOptions['headers']['Content-Type'] = 'application/json';
            $defaultOptions['body'] = json_encode($body);
        }

        // Merge custom headers into default headers (preserving Authorization)
        if (isset($options['headers'])) {
            $defaultOptions['headers'] = array_merge($defaultOptions['headers'], $options['headers']);
        }

        // Merge other options (but not headers, as we've already handled them)
        unset($options['headers']);
        $allOptions = array_merge($defaultOptions, $options);

        return $this->httpClient->request($method, $url, $allOptions);
    }

    /**
     * Handle the API response.
     *
     * @param ResponseInterface $response The HTTP response
     * @return array Decoded JSON response
     * @throws YouTrustApiException When response indicates an error
     */
    private function handleResponse(ResponseInterface $response): array
    {
        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);

        if ($statusCode >= 200 && $statusCode < 300) {
            return json_decode($content, true) ?: [];
        }

        throw YouTrustApiException::fromResponse($statusCode, $content);
    }

    /**
     * Get the current configuration.
     */
    public function getConfig(): YouTrustConfig
    {
        return $this->config;
    }

    /**
     * Check if the service is properly configured (has API key).
     */
    public function isConfigured(): bool
    {
        return !empty($this->config->apiKey);
    }
}
