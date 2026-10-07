<?php

namespace App\Dto;

/**
 * Configuration DTO for YouTrust (ex-YouSign) API service.
 * Contains all configurable parameters for the API client.
 */
class YouTrustConfig
{
    public string $apiUrl;
    public string $apiKey;
    public string $apiVersion;
    public int $timeout;

    /**
     * @param string $apiUrl Base URL for YouTrust API (e.g., 'https://api-sandbox.yousign.app')
     * @param string $apiKey Bearer token for authentication
     * @param string $apiVersion API version (default: 'v3')
     * @param int|string $timeout Request timeout in seconds (default: 30)
     */
    public function __construct(
        string $apiUrl = 'https://api-sandbox.yousign.app',
        string $apiKey = '',
        string $apiVersion = 'v3',
        int|string $timeout = 30,
    ) {
        $this->apiUrl = $apiUrl;
        $this->apiKey = $apiKey;
        $this->apiVersion = $apiVersion;
        $this->timeout = (int) $timeout;
    }

    /**
     * Get the full base URL with version.
     */
    public function getBaseUrl(): string
    {
        return rtrim($this->apiUrl, '/') . '/' . ltrim($this->apiVersion, '/');
    }

    /**
     * Create config from environment variables.
     */
    public static function fromEnv(): self
    {
        return new self(
            apiUrl: $_ENV['YOU_TRUST_API_URL'] ?? 'https://api-sandbox.yousign.app',
            apiKey: $_ENV['YOU_TRUST_API_KEY'] ?? '',
            apiVersion: $_ENV['YOU_TRUST_API_VERSION'] ?? 'v3',
            timeout: (int)($_ENV['YOU_TRUST_TIMEOUT'] ?? 30),
        );
    }
}
