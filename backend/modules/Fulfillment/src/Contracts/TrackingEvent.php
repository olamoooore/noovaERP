<?php

namespace Modules\Fulfillment\Contracts;

class TrackingEventResponse
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $statusCode,
        public readonly string $description,
        public readonly ?string $location = null,
        public readonly ?string $timestamp = null,
        public readonly ?string $signedBy = null,
    ) {}

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'status_code' => $this->statusCode,
            'description' => $this->description,
            'location' => $this->location,
            'timestamp' => $this->timestamp,
            'signed_by' => $this->signedBy,
        ];
    }
}
