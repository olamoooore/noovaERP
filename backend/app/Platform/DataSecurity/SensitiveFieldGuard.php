<?php

namespace App\Platform\DataSecurity;

use App\Http\Middleware\RequirePermission;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Request-scoped guard for sensitive model fields (Decision on #919,
 * 2 Oct 2026, ADR-007 v0.3 §16A; first applied by #567).
 *
 * - Reads: a field is masked when the model is converted to JSON unless the
 *   viewer holds the field's read permission (resolved through the same
 *   RequirePermission/TenantRoleService path as route middleware). No
 *   authenticated viewer means no access (fail closed).
 * - Writes: a viewer who can't read a field can't write it; a masked value
 *   echoed back is dropped so it is never saved.
 * - Audit: unmasked reads are collected during the request and written as
 *   one summary entry by flushAudit().
 */
class SensitiveFieldGuard
{
    /** @var array<string, bool> */
    private array $permissionCache = [];

    /** @var array<string, array<int|string, true>> reference type => record ids */
    private array $revealedRecords = [];

    /** @var array<string, true> */
    private array $revealedFields = [];

    public function canRead(string $permission): bool
    {
        $request = request();
        $user = $request?->user();

        if ($user === null) {
            return false;
        }

        $key = spl_object_id($request).'|'.$user->getAuthIdentifier().'|'.(RequirePermission::resolveTenantId($request) ?? '').'|'.$permission;

        return $this->permissionCache[$key] ??= RequirePermission::allows($request, $permission);
    }

    public static function mask(string $value, string $type): string
    {
        return match ($type) {
            'name' => FieldMaskingService::maskName($value),
            'email' => FieldMaskingService::maskEmail($value),
            'phone' => FieldMaskingService::maskPhone($value),
            default => FieldMaskingService::maskDefault($value),
        };
    }

    /**
     * Mask (or record the reveal of) the model's sensitive fields in its
     * serialized attributes.
     *
     * @param  array<string, array{permission: string, mask: string}>  $fields
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function applyToAttributes(Model $model, array $fields, string $referenceType, array $attributes): array
    {
        foreach ($fields as $field => $rule) {
            $value = $attributes[$field] ?? null;

            if (! is_string($value) || $value === '') {
                continue;
            }

            if ($this->canRead($rule['permission'])) {
                $this->revealedRecords[$referenceType][$model->getKey()] = true;
                $this->revealedFields[$field] = true;

                continue;
            }

            $attributes[$field] = self::mask($value, $rule['mask']);
        }

        return $attributes;
    }

    /**
     * Enforce "can't read → can't write" on incoming input for the model.
     * A masked value echoed back unchanged is removed so it is never saved;
     * any other value for a field the viewer can't read is refused.
     *
     * @param  array<string, array{permission: string, mask: string}>  $fields
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function filterWrite(Model $model, array $fields, array $input): array
    {
        foreach ($fields as $field => $rule) {
            if (! array_key_exists($field, $input) || $this->canRead($rule['permission'])) {
                continue;
            }

            $submitted = $input[$field];
            $current = $model->exists ? $model->getAttribute($field) : null;
            $currentIsEmpty = $current === null || $current === '';

            $isEcho = ($submitted === null || $submitted === '')
                ? $currentIsEmpty
                : (! $currentIsEmpty && is_string($submitted) && $submitted === self::mask((string) $current, $rule['mask']));

            if (! $isEcho) {
                throw new HttpException(403, "You do not have permission to change {$field}.");
            }

            unset($input[$field]);
        }

        return $input;
    }

    /**
     * Write one audit summary of the sensitive fields read in this request,
     * then reset the collector. No-op when nothing was revealed.
     */
    public function flushAudit(): void
    {
        if ($this->revealedRecords === []) {
            return;
        }

        $records = array_map(fn (array $ids) => array_keys($ids), $this->revealedRecords);
        $referenceType = array_key_first($records);
        $fields = array_keys($this->revealedFields);

        $this->revealedRecords = [];
        $this->revealedFields = [];

        app(AuditLogger::class)->log(
            operation: 'sensitive_fields.read',
            referenceType: $referenceType,
            referenceId: (int) $records[$referenceType][0],
            metadata: [
                'fields' => $fields,
                'records' => $records,
            ],
        );
    }
}
