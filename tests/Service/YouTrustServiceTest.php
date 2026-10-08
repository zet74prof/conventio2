<?php

namespace App\Tests\Service;

use App\Dto\DocumentUpload;
use App\Dto\SignatureRequestConfig;
use App\Dto\SignerInfo;
use App\Dto\YouTrustConfig;
use App\Exception\YouTrustApiException;
use App\Service\YouTrustService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Test cases for YouTrustService.
 */
class YouTrustServiceTest extends KernelTestCase
{
    private YouTrustService $service;
    private HttpClientInterface $httpClientMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientMock = $this->createMock(HttpClientInterface::class);
        $config = new YouTrustConfig(
            apiUrl: 'https://api-sandbox.yousign.app',
            apiKey: 'test-api-key',
            apiVersion: 'v3',
            timeout: 30
        );

        $this->service = new YouTrustService($this->httpClientMock, $config);
    }

    // ========================================================================
    // Configuration Tests
    // ========================================================================

    public function testIsConfiguredWithValidKey(): void
    {
        $this->assertTrue($this->service->isConfigured());
    }

    public function testIsConfiguredWithoutKey(): void
    {
        $config = new YouTrustConfig(apiKey: '');
        $service = new YouTrustService($this->httpClientMock, $config);
        
        $this->assertFalse($service->isConfigured());
    }

    public function testGetConfig(): void
    {
        $config = $this->service->getConfig();
        
        $this->assertInstanceOf(YouTrustConfig::class, $config);
        $this->assertEquals('https://api-sandbox.yousign.app', $config->apiUrl);
        $this->assertEquals('test-api-key', $config->apiKey);
        $this->assertEquals('v3', $config->apiVersion);
        $this->assertEquals(30, $config->timeout);
    }

    public function testConfigGetBaseUrl(): void
    {
        $config = $this->service->getConfig();
        
        $this->assertEquals('https://api-sandbox.yousign.app/v3', $config->getBaseUrl());
    }

    // ========================================================================
    // DTO Tests
    // ========================================================================

    public function testSignatureRequestConfigToArray(): void
    {
        $config = new SignatureRequestConfig(
            name: 'Test Request',
            externalId: 'test-123',
            customName: 'Test Company',
            requestSubject: 'Test Subject',
            requestBody: 'Test Body',
            reminderSubject: 'Reminder Subject',
            reminderBody: 'Reminder Body',
        );

        $array = $config->toArray();

        $this->assertEquals('Test Request', $array['name']);
        $this->assertEquals('test-123', $array['external_id']);
        $this->assertEquals('Test Company', $array['email_notification']['sender']['custom_name']);
        $this->assertEquals('Test Subject', $array['email_notification']['custom_text']['request_subject']);
        $this->assertEquals('Europe/Paris', $array['timezone']);
        $this->assertEquals('fr', $array['audit_trail_locale']);
    }

    public function testSignerInfoToArray(): void
    {
        $signer = new SignerInfo(
            firstName: 'John',
            lastName: 'Doe',
            email: 'john@example.com',
            phoneNumber: '+33612345678'
        );

        $array = $signer->toArray();

        $this->assertEquals('John', $array['info']['first_name']);
        $this->assertEquals('Doe', $array['info']['last_name']);
        $this->assertEquals('john@example.com', $array['info']['email']);
        $this->assertEquals('+33612345678', $array['info']['phone_number']);
        $this->assertArrayNotHasKey('signing_order', $array['info']);
        $this->assertEquals('electronic_signature', $array['signature_level']);
        $this->assertEquals('otp_sms', $array['signature_authentication_mode']);
    }

    // ========================================================================
    // API Method Tests (Mocked)
    // ========================================================================

    public function testInitiateSignatureRequestSuccess(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(201);
        $responseMock->method('getContent')->willReturn(json_encode([
            'id' => 'sr-123',
            'name' => 'Test Request',
            'status' => 'draft',
        ]));

        $this->httpClientMock
            ->method('request')
            ->with(
                'POST',
                'https://api-sandbox.yousign.app/v3/signature_requests',
                $this->callback(function ($options) {
                    return isset($options['headers']['Authorization']) &&
                           str_contains($options['headers']['Authorization'], 'Bearer test-api-key') &&
                           isset($options['headers']['Content-Type']) &&
                           $options['headers']['Content-Type'] === 'application/json';
                })
            )
            ->willReturn($responseMock);

        $config = new SignatureRequestConfig(
            name: 'Test Request',
            externalId: 'test-123',
            customName: 'Test Company',
            requestSubject: 'Test Subject',
            requestBody: 'Test Body',
            reminderSubject: 'Reminder',
            reminderBody: 'Reminder Body'
        );

        $result = $this->service->initiateSignatureRequest($config);

        $this->assertEquals('sr-123', $result['id']);
        $this->assertEquals('Test Request', $result['name']);
        $this->assertEquals('draft', $result['status']);
    }

    public function testInitiateSignatureRequestFailure(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(400);
        $responseMock->method('getContent')->willReturn(json_encode([
            'message' => 'Invalid request body',
            'code' => 'invalid_body',
        ]));

        $this->httpClientMock
            ->method('request')
            ->willReturn($responseMock);

        $config = new SignatureRequestConfig(
            name: 'Test',
            externalId: 'test',
            customName: 'Test',
            requestSubject: 'Test',
            requestBody: 'Test',
            reminderSubject: 'Test',
            reminderBody: 'Test'
        );

        $this->expectException(YouTrustApiException::class);
        $this->expectExceptionMessageMatches('/YouTrust API Error \[400\]/');

        $this->service->initiateSignatureRequest($config);
    }

    public function testListSignatureRequestsSuccess(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('getContent')->willReturn(json_encode([
            'signature_requests' => [
                ['id' => 'sr-123', 'name' => 'Request 1'],
                ['id' => 'sr-456', 'name' => 'Request 2'],
            ],
        ]));

        $this->httpClientMock
            ->method('request')
            ->willReturn($responseMock);

        $result = $this->service->listSignatureRequests();

        $this->assertCount(2, $result['signature_requests']);
        $this->assertEquals('sr-123', $result['signature_requests'][0]['id']);
    }

    public function testGetSignatureRequestSuccess(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('getContent')->willReturn(json_encode([
            'id' => 'sr-123',
            'name' => 'Test Request',
            'status' => 'draft',
        ]));

        $this->httpClientMock
            ->method('request')
            ->willReturn($responseMock);

        $result = $this->service->getSignatureRequest('sr-123');

        $this->assertEquals('sr-123', $result['id']);
    }

    public function testCancelSignatureRequestSuccess(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('getContent')->willReturn(json_encode([
            'id' => 'sr-123',
            'status' => 'cancelled',
        ]));

        $this->httpClientMock
            ->method('request')
            ->willReturn($responseMock);

        $result = $this->service->cancelSignatureRequest('sr-123', 'Test reason');

        $this->assertEquals('cancelled', $result['status']);
    }

    public function testActivateSignatureRequestSuccess(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('getContent')->willReturn(json_encode([
            'id' => 'sr-123',
            'status' => 'activated',
        ]));

        $this->httpClientMock
            ->method('request')
            ->willReturn($responseMock);

        $result = $this->service->activateSignatureRequest('sr-123');

        $this->assertEquals('activated', $result['status']);
    }

    public function testDeleteSignatureRequestSuccess(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(204);
        $responseMock->method('getContent')->willReturn('');

        $this->httpClientMock
            ->method('request')
            ->with('DELETE', 'https://api-sandbox.yousign.app/v3/signature_requests/sr-123')
            ->willReturn($responseMock);

        $result = $this->service->deleteSignatureRequest('sr-123');

        $this->assertEquals([], $result);
    }

    public function testDeleteSignatureRequestFailure(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(400);
        $responseMock->method('getContent')->willReturn(json_encode([
            'message' => 'Cannot delete an ongoing signature request',
        ]));

        $this->httpClientMock
            ->method('request')
            ->willReturn($responseMock);

        $this->expectException(YouTrustApiException::class);
        $this->expectExceptionMessageMatches('/YouTrust API Error \[400\]/');

        $this->service->deleteSignatureRequest('sr-123');
    }

    public function testUploadDocumentBase64Success(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(201);
        $responseMock->method('getContent')->willReturn(json_encode([
            'id' => 'doc-123',
            'name' => 'test.pdf',
            'nature' => 'signable_document',
        ]));

        $this->httpClientMock
            ->method('request')
            ->willReturn($responseMock);

        $result = $this->service->uploadDocumentBase64(
            'sr-123',
            'test.pdf',
            base64_encode('test content'),
            'application/pdf'
        );

        $this->assertEquals('doc-123', $result['id']);
        $this->assertEquals('test.pdf', $result['name']);
    }

    public function testCreateSignerSuccess(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(201);
        $responseMock->method('getContent')->willReturn(json_encode([
            'id' => 'signer-123',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
        ]));

        $this->httpClientMock
            ->method('request')
            ->willReturn($responseMock);

        $signer = new SignerInfo(
            firstName: 'John',
            lastName: 'Doe',
            email: 'john@example.com',
            phoneNumber: '+33612345678'
        );

        $result = $this->service->createSigner('sr-123', $signer);

        $this->assertEquals('signer-123', $result['id']);
        $this->assertEquals('John', $result['first_name']);
    }

    public function testListSignersSuccess(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('getContent')->willReturn(json_encode([
            'signers' => [
                ['id' => 'signer-1', 'email' => 'john@example.com'],
                ['id' => 'signer-2', 'email' => 'jane@example.com'],
            ],
        ]));

        $this->httpClientMock
            ->method('request')
            ->willReturn($responseMock);

        $result = $this->service->listSigners('sr-123');

        $this->assertCount(2, $result['signers']);
    }

    public function testDeleteSignerSuccess(): void
    {
        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn(200);
        $responseMock->method('getContent')->willReturn(json_encode([
            'id' => 'signer-123',
            'deleted' => true,
        ]));

        $this->httpClientMock
            ->method('request')
            ->willReturn($responseMock);

        $result = $this->service->deleteSigner('sr-123', 'signer-123');

        $this->assertTrue($result['deleted']);
    }

    // ========================================================================
    // Complete Workflow Tests
    // ========================================================================

    public function testCreateCompleteSignatureRequestBase64Success(): void
    {
        // Mock responses for each step
        $initiateResponse = $this->createMock(ResponseInterface::class);
        $initiateResponse->method('getStatusCode')->willReturn(201);
        $initiateResponse->method('getContent')->willReturn(json_encode([
            'id' => 'sr-123',
            'name' => 'Test Request',
            'status' => 'draft',
        ]));

        $uploadResponse = $this->createMock(ResponseInterface::class);
        $uploadResponse->method('getStatusCode')->willReturn(201);
        $uploadResponse->method('getContent')->willReturn(json_encode([
            'id' => 'doc-123',
            'name' => 'test.pdf',
        ]));

        $signerResponse = $this->createMock(ResponseInterface::class);
        $signerResponse->method('getStatusCode')->willReturn(201);
        $signerResponse->method('getContent')->willReturn(json_encode([
            'id' => 'signer-1',
            'email' => 'john@example.com',
        ]));

        $activateResponse = $this->createMock(ResponseInterface::class);
        $activateResponse->method('getStatusCode')->willReturn(200);
        $activateResponse->method('getContent')->willReturn(json_encode([
            'id' => 'sr-123',
            'status' => 'activated',
        ]));

        $getRequestResponse = $this->createMock(ResponseInterface::class);
        $getRequestResponse->method('getStatusCode')->willReturn(200);
        $getRequestResponse->method('getContent')->willReturn(json_encode([
            'id' => 'sr-123',
            'name' => 'Test Request',
            'status' => 'activated',
            'documents' => [['id' => 'doc-123']],
            'signers' => [['id' => 'signer-1']],
        ]));

        $this->httpClientMock
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                $initiateResponse,
                $uploadResponse,
                $signerResponse,
                $activateResponse,
                $getRequestResponse
            );

        $signatureConfig = new SignatureRequestConfig(
            name: 'Test Request',
            externalId: 'test-123',
            customName: 'Test Company',
            requestSubject: 'Test',
            requestBody: 'Test',
            reminderSubject: 'Test',
            reminderBody: 'Test'
        );

        $signers = [
            new SignerInfo(
                firstName: 'John',
                lastName: 'Doe',
                email: 'john@example.com',
                phoneNumber: '+33612345678'
            ),
        ];

        $result = $this->service->createCompleteSignatureRequestBase64(
            $signatureConfig,
            'test.pdf',
            base64_encode('test content'),
            'application/pdf',
            $signers,
            true
        );

        $this->assertEquals('sr-123', $result['id']);
        $this->assertEquals('activated', $result['status']);
    }

    // ========================================================================
    // Exception Tests
    // ========================================================================

    public function testYouTrustApiExceptionFromResponse(): void
    {
        $exception = YouTrustApiException::fromResponse(
            404,
            json_encode(['message' => 'Not found', 'code' => 'not_found'])
        );

        $this->assertInstanceOf(YouTrustApiException::class, $exception);
        $this->assertEquals(404, $exception->getCode());
        $this->assertStringContainsString('YouTrust API Error [404]', $exception->getMessage());
        
        $errorDetails = $exception->getErrorDetails();
        $this->assertNotNull($errorDetails);
        $this->assertEquals('Not found', $errorDetails['message']);
        $this->assertEquals('not_found', $errorDetails['code']);
    }

    public function testYouTrustApiExceptionFromPlainText(): void
    {
        $exception = YouTrustApiException::fromResponse(
            500,
            'Internal Server Error'
        );

        $this->assertInstanceOf(YouTrustApiException::class, $exception);
        $this->assertEquals(500, $exception->getCode());
        $this->assertStringContainsString('YouTrust API Error [500]', $exception->getMessage());
        $this->assertNull($exception->getErrorDetails());
    }

    // ========================================================================
    // Configuration DTO Tests
    // ========================================================================

    public function testYouTrustConfigDefaults(): void
    {
        $config = new YouTrustConfig();
        
        $this->assertEquals('https://api-sandbox.yousign.app', $config->apiUrl);
        $this->assertEquals('', $config->apiKey);
        $this->assertEquals('v3', $config->apiVersion);
        $this->assertEquals(30, $config->timeout);
        $this->assertEquals('https://api-sandbox.yousign.app/v3', $config->getBaseUrl());
    }

    public function testYouTrustConfigCustomValues(): void
    {
        $config = new YouTrustConfig(
            apiUrl: 'https://api.yousign.app',
            apiKey: 'my-api-key',
            apiVersion: 'v2',
            timeout: 60
        );
        
        $this->assertEquals('https://api.yousign.app', $config->apiUrl);
        $this->assertEquals('my-api-key', $config->apiKey);
        $this->assertEquals('v2', $config->apiVersion);
        $this->assertEquals(60, $config->timeout);
        $this->assertEquals('https://api.yousign.app/v2', $config->getBaseUrl());
    }

    public function testYouTrustConfigFromEnv(): void
    {
        // Note: This test depends on environment variables
        // In a real test, you would set these up
        $originalEnv = getenv();
        
        putenv('YOU_TRUST_API_URL=https://custom-api.yousign.app');
        putenv('YOU_TRUST_API_KEY=custom-key');
        putenv('YOU_TRUST_API_VERSION=v4');
        putenv('YOU_TRUST_TIMEOUT=120');

        try {
            $_ENV['YOU_TRUST_API_URL'] = 'https://custom-api.yousign.app';
            $_ENV['YOU_TRUST_API_KEY'] = 'custom-key';
            $_ENV['YOU_TRUST_API_VERSION'] = 'v4';
            $_ENV['YOU_TRUST_TIMEOUT'] = '120';

            $config = YouTrustConfig::fromEnv();
            
            $this->assertEquals('https://custom-api.yousign.app', $config->apiUrl);
            $this->assertEquals('custom-key', $config->apiKey);
            $this->assertEquals('v4', $config->apiVersion);
            $this->assertEquals(120, $config->timeout);
        } finally {
            // Restore original environment
            foreach ($originalEnv as $key => $value) {
                putenv($key . '=' . $value);
            }
        }
    }
}
