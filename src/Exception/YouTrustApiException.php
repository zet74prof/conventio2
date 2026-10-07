<?php

namespace App\Exception;

/**
 * Exception thrown when YouTrust API returns an error.
 */
class YouTrustApiException extends \RuntimeException
{
    private ?array $errorDetails;

    /**
     * @param string $message Error message
     * @param int $code HTTP status code
     * @param array|null $errorDetails Additional error details from API response
     */
    public function __construct(
        string $message,
        int $code = 0,
        ?array $errorDetails = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->errorDetails = $errorDetails;
    }

    /**
     * Get additional error details from API response.
     */
    public function getErrorDetails(): ?array
    {
        return $this->errorDetails;
    }

    /**
     * Create exception from API response.
     */
    public static function fromResponse(
        int $statusCode,
        string $responseBody,
        ?\Throwable $previous = null
    ): self {
        $errorDetails = null;
        $decoded = json_decode($responseBody, true);
        
        if (is_array($decoded)) {
            $errorDetails = $decoded;
            $message = $decoded['message'] ?? $decoded['error'] ?? $responseBody;
        } else {
            $message = $responseBody;
        }

        return new self(
            message: sprintf('YouTrust API Error [%d]: %s', $statusCode, $message),
            code: $statusCode,
            errorDetails: $errorDetails,
            previous: $previous
        );
    }
}
