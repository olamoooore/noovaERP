<?php

namespace App\Platform\Policies;

class PolicyResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $message = null,
        public readonly array $data = []
    ) {}

    public static function allow(array $data = []): self
    {
        return new self(true, null, $data);
    }

    public static function deny(string $message, array $data = []): self
    {
        return new self(false, $message, $data);
    }

    public function message(): string
    {
        return $this->message ?? ($this->allowed ? 'Allowed' : 'Denied');
    }
}
