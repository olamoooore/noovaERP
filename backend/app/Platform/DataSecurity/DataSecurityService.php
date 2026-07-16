<?php

namespace App\Platform\DataSecurity;

use App\Platform\DataSecurity\Contracts\DataSecurityServiceContract;
use Illuminate\Support\Str;

class DataSecurityService implements DataSecurityServiceContract
{
    private const CLASSIFICATION_LEVELS = ['public', 'internal', 'confidential', 'restricted', 'secret'];

    private const EXPORT_PERMISSIONS = [
        'public' => ['public', 'internal', 'confidential', 'restricted', 'secret'],
        'internal' => ['internal', 'confidential', 'restricted', 'secret'],
        'confidential' => ['confidential', 'restricted', 'secret'],
        'restricted' => ['restricted', 'secret'],
        'secret' => ['secret'],
    ];

    public function classify(string $entityType, int $entityId, string $classification, int $userId, ?string $notes = null): object
    {
        if (! in_array($classification, self::CLASSIFICATION_LEVELS)) {
            throw new \InvalidArgumentException("Invalid classification: {$classification}");
        }

        $existing = DataClassification::where('tenant_id', tenant()->id)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->first();

        if ($existing) {
            $existing->update([
                'classification' => $classification,
                'classified_by' => $userId,
                'classified_at' => now(),
                'notes' => $notes,
            ]);
            $record = $existing->fresh();
        } else {
            $record = DataClassification::create([
                'id' => Str::uuid(),
                'tenant_id' => tenant()->id,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'classification' => $classification,
                'classified_by' => $userId,
                'classified_at' => now(),
                'notes' => $notes,
            ]);
        }

        return (object) $record->toArray();
    }

    public function getClassification(string $entityType, int $entityId): ?object
    {
        $record = DataClassification::where('tenant_id', tenant()->id)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->first();

        if (! $record) {
            return null;
        }

        return (object) $record->toArray();
    }

    public function canExport(int $userId, string $classification): bool
    {
        if (! in_array($classification, self::CLASSIFICATION_LEVELS)) {
            return false;
        }

        $userClassification = $this->getUserClassificationLevel($userId);
        $allowedLevels = self::EXPORT_PERMISSIONS[$userClassification] ?? [];

        return in_array($classification, $allowedLevels);
    }

    public function mask(string $value, string $type): string
    {
        return match ($type) {
            'email' => FieldMaskingService::maskEmail($value),
            'phone' => FieldMaskingService::maskPhone($value),
            'ssn' => FieldMaskingService::maskSSN($value),
            'card' => FieldMaskingService::maskCard($value),
            default => FieldMaskingService::maskDefault($value),
        };
    }

    private function getUserClassificationLevel(int $userId): string
    {
        return 'internal';
    }
}
