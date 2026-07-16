<?php

namespace App\Platform\DataSecurity\Contracts;

interface DataSecurityServiceContract
{
    public function classify(string $entityType, int $entityId, string $classification, int $userId, ?string $notes = null): object;

    public function getClassification(string $entityType, int $entityId): ?object;

    public function canExport(int $userId, string $classification): bool;

    public function mask(string $value, string $type): string;
}
