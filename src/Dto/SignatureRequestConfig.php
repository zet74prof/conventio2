<?php

namespace App\Dto;

/**
 * Configuration DTO for creating a new Signature Request.
 * Contains all variable parameters for signature request creation.
 */
readonly class SignatureRequestConfig
{
    /**
     * @param string $name Name of the signature request
     * @param string $externalId External ID for tracking (e.g., convention ID)
     * @param string $customName Custom sender name (e.g., "Lycée Gabriel Fauré")
     * @param string $requestSubject Email subject for signature request
     * @param string $requestBody Email body for signature request
     * @param string $reminderSubject Email subject for reminders
     * @param string $reminderBody Email body for reminders
     * @param string $timezone Timezone for the request (default: 'Europe/Paris')
     * @param bool $orderedSigners Whether signers must sign in order (default: true)
     * @param bool $signersAllowedToDecline Whether signers can decline (default: false)
     * @param string $deliveryMode Delivery mode (default: 'email')
     * @param string $smartAnchorsResolution When to resolve anchors (default: 'on_activation')
     * @param string $auditTrailLocale Locale for audit trail (default: 'fr')
     * @param int $reminderIntervalInDays Days between reminders (default: 1)
     * @param int $maxReminderOccurrences Maximum number of reminders (default: 5)
     */
    public function __construct(
        public string $name,
        public string $externalId,
        public string $customName,
        public string $requestSubject,
        public string $requestBody,
        public string $reminderSubject,
        public string $reminderBody,
        public string $timezone = 'Europe/Paris',
        public bool $orderedSigners = true,
        public bool $signersAllowedToDecline = false,
        public string $deliveryMode = 'email',
        public string $smartAnchorsResolution = 'on_activation',
        public string $auditTrailLocale = 'fr',
        public int $reminderIntervalInDays = 1,
        public int $maxReminderOccurrences = 5,
    ) {
    }

    /**
     * Convert to array for API request body.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'external_id' => $this->externalId,
            'delivery_mode' => $this->deliveryMode,
            'ordered_signers' => $this->orderedSigners,
            'smart_anchors_resolution' => $this->smartAnchorsResolution,
            'timezone' => $this->timezone,
            'audit_trail_locale' => $this->auditTrailLocale,
            'signers_allowed_to_decline' => $this->signersAllowedToDecline,
            'reminder_settings' => [
                'interval_in_days' => $this->reminderIntervalInDays,
                'max_occurrences' => $this->maxReminderOccurrences,
            ],
            'email_notification' => [
                'sender' => [
                    'type' => 'custom',
                    'custom_name' => $this->customName,
                ],
                'custom_text' => [
                    'request_subject' => $this->requestSubject,
                    'request_body' => $this->requestBody,
                    'reminder_subject' => $this->reminderSubject,
                    'reminder_body' => $this->reminderBody,
                ],
            ],
        ];
    }
}
