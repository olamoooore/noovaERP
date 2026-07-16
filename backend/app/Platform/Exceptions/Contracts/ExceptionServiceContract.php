<?php

namespace App\Platform\Exceptions\Contracts;

interface ExceptionServiceContract
{
    public function log(string $module, string $exceptionType, string $severity, string $message, ?array $context = null): object;

    public function assign(string $exceptionId, int $userId): object;

    public function resolve(string $exceptionId, int $userId, ?string $resolution = null): object;

    public function retry(string $exceptionId): object;

    public function getStats(): array;
}
