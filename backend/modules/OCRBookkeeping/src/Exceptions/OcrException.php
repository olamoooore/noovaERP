<?php

namespace Modules\OCRBookkeeping\Exceptions;

use Exception;

/**
 * Base OCR Exception
 */
class OcrException extends Exception
{
    protected string $errorCode;

    protected array $context;

    public function __construct(string $message, string $errorCode = 'OCR_ERROR', array $context = [], int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errorCode = $errorCode;
        $this->context = $context;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function toArray(): array
    {
        return [
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
            'context' => $this->context,
        ];
    }
}

/**
 * OCR Processing Exception
 */
class OcrProcessingException extends OcrException
{
    public function __construct(string $message, array $context = [])
    {
        parent::__construct($message, 'OCR_PROCESSING_FAILED', $context);
    }
}

/**
 * OCR Validation Exception
 */
class OcrValidationException extends OcrException
{
    public function __construct(string $message, array $context = [])
    {
        parent::__construct($message, 'OCR_VALIDATION_FAILED', $context);
    }
}

/**
 * OCR Storage Exception
 */
class OcrStorageException extends OcrException
{
    public function __construct(string $message, array $context = [])
    {
        parent::__construct($message, 'OCR_STORAGE_FAILED', $context);
    }
}

/**
 * OCR Posting Exception
 */
class OcrPostingException extends OcrException
{
    public function __construct(string $message, array $context = [])
    {
        parent::__construct($message, 'OCR_POSTING_FAILED', $context);
    }
}

/**
 * OCR Concurrent Modification Exception
 */
class OcrConcurrentModificationException extends OcrException
{
    public function __construct(string $message = 'Document was modified by another user', array $context = [])
    {
        parent::__construct($message, 'OCR_CONCURRENT_MODIFICATION', $context, 409);
    }
}

/**
 * OCR Service Unavailable Exception
 */
class OcrServiceUnavailableException extends OcrException
{
    public function __construct(string $message = 'OCR service is temporarily unavailable', array $context = [])
    {
        parent::__construct($message, 'OCR_SERVICE_UNAVAILABLE', $context, 503);
    }
}
