<?php

namespace App\Platform\SystemLog\Contracts;

interface SystemLogServiceContract
{
    public function log(string $level, string $category, string $message, ?array $context = null, ?string $correlationId = null): object;
    public function query(array $filters): array;
    public function getByCorrelationId(string $correlationId): array;
    public function getRecent(int $limit = 50): array;
}
