<?php

namespace App\Dto;

/**
 * DTO for creating a new Signer in a Signature Request.
 */
readonly class SignerInfo
{
    /**
     * @param string $firstName First name of the signer
     * @param string $lastName Last name of the signer
     * @param string $email Email address of the signer
     * @param string $phoneNumber Phone number for OTP authentication (format: +33612345678)
     * @param string $locale Locale for the signer (default: 'fr')
     * @param string $signatureLevel Signature level (default: 'electronic_signature')
     * @param string $deliveryMode Delivery mode (default: 'email')
     * @param string $signatureAuthenticationMode Authentication mode (default: 'otp_sms')
     */
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $phoneNumber,
        public string $locale = 'fr',
        public string $signatureLevel = 'electronic_signature',
        public string $deliveryMode = 'email',
        public string $signatureAuthenticationMode = 'otp_sms',
    ) {
    }

    /**
     * Convert to array for API request body.
     */
    public function toArray(): array
    {
        return [
            'info' => [
                'first_name' => $this->firstName,
                'last_name' => $this->lastName,
                'email' => $this->email,
                'phone_number' => $this->phoneNumber,
                'locale' => $this->locale,
            ],
            'signature_level' => $this->signatureLevel,
            'delivery_mode' => $this->deliveryMode,
            'signature_authentication_mode' => $this->signatureAuthenticationMode,
        ];
    }
}
