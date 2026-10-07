<?php

namespace App\Platform\Settings;

/**
 * Issue #921 (SS5): one declared tenant setting.
 *
 * A definition says where the value is stored, what it looks like, who may
 * edit it, and — the part that was missing — which code paths read it, so a
 * saved value that nothing reads can be caught by CI instead of by a tenant.
 */
final class SettingDefinition
{
    public const SCOPES = ['tenant', 'entity', 'user'];

    /**
     * @param  string  $field  The screen payload path (e.g. "invoice.approval_expiry_days").
     * @param  string  $key  The `Setting` store key the value is persisted under.
     * @param  string|null  $path  For a JSON block key (e.g. `tax`), the field inside the block; null = the whole value.
     * @param  list<string>  $readers  "Class::method" code paths that read the value and act on it.
     * @param  list<string>  $via  "Class::method" accessors a reader goes through to reach the key (e.g. SalesSettings).
     * @param  list<string>  $keyParts  For keys a reader composes at runtime (prefix + suffix), the literals it composes them from.
     */
    public function __construct(
        public readonly string $screen,
        public readonly string $field,
        public readonly string $key,
        public readonly string $type,
        public readonly mixed $default,
        public readonly string $scope,
        public readonly string $editPermission,
        public readonly array $readers,
        public readonly array $via = [],
        public readonly ?string $path = null,
        public readonly array $keyParts = [],
        public readonly ?string $note = null,
    ) {
        if (! in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException("Setting {$field}: unknown scope '{$scope}'.");
        }
    }

    /** Written as its own `Setting` row (not merged into a JSON block). */
    public function isWholeValue(): bool
    {
        return $this->path === null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'path' => $this->path,
            'type' => $this->type,
            'default' => $this->default,
            'scope' => $this->scope,
            'edit_permission' => $this->editPermission,
            'readers' => $this->readers,
            'note' => $this->note,
        ];
    }
}
