<?php

namespace Modules\EventManagement\Exceptions;

class FeatureNotEnabledException extends EventManagementException
{
    protected $feature;
    protected $tenantId;

    public function __construct(string $feature, ?string $tenantId = null, string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        $this->feature = $feature;
        $this->tenantId = $tenantId;

        if (empty($message)) {
            $message = "Feature '{$feature}' is not enabled";
            if ($tenantId) {
                $message .= " for tenant '{$tenantId}'";
            }
        }

        parent::__construct($message, $code, $previous);
    }

    public function getErrorCode(): string
    {
        return 'FEATURE_NOT_ENABLED';
    }

    public function getHttpStatusCode(): int
    {
        return 403;
    }

    public function getDetails(): ?array
    {
        $details = [
            'feature' => $this->feature
        ];

        if ($this->tenantId) {
            $details['tenant_id'] = $this->tenantId;
        }

        return $details;
    }
}