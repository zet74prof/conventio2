<?php

namespace App\Dto;

/**
 * DTO for uploading a document to a Signature Request.
 */
readonly class DocumentUpload
{
    /**
     * @param string $filePath Path to the file to upload
     * @param string $nature Nature of the document (default: 'signable_document')
     * @param bool $parseAnchors Whether to parse anchors (default: true)
     */
    public function __construct(
        public string $filePath,
        public string $nature = 'signable_document',
        public bool $parseAnchors = true,
    ) {
    }

    /**
     * Get the file content as base64.
     */
    public function getFileContent(): string
    {
        if (!file_exists($this->filePath)) {
            throw new \RuntimeException(sprintf('File not found: %s', $this->filePath));
        }

        $content = file_get_contents($this->filePath);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Failed to read file: %s', $this->filePath));
        }

        return base64_encode($content);
    }

    /**
     * Get the file name.
     */
    public function getFileName(): string
    {
        return basename($this->filePath);
    }

    /**
     * Get the MIME type based on file extension.
     */
    public function getMimeType(): string
    {
        $extension = strtolower(pathinfo($this->filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
    }

    /**
     * Generate the data URI for the file.
     */
    public function getDataUri(): string
    {
        $mimeType = $this->getMimeType();
        $fileName = $this->getFileName();
        $content = $this->getFileContent();

        return sprintf(
            'data:%s;name=%s;base64,%s',
            $mimeType,
            rawurlencode($fileName),
            $content
        );
    }
}
