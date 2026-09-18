<?php

namespace App\Services;

/**
 * Issue #76 (CRITICAL, SEC-1): ChatterModelRegistry
 *
 * Maps a short, controlled alias (e.g. 'order', 'invoice') to a fully-qualified
 * Eloquent model class. The Communication module's REST API MUST resolve the
 * `model` parameter through this registry — never accept a raw class string
 * from the client. This prevents an attacker from supplying an arbitrary
 * `Modules\Sales\Models\Order` style string and reading messages/subscribers
 * for records they don't own.
 *
 * To add a new chatter-enabled model, add an entry here AND wire the
 * authorization policy / Gate check on that model.
 */
class ChatterModelRegistry
{
    /**
     * Canonical registry of chatter-enabled model aliases.
     * Key = short, lowercase, controllable alias exposed to the API.
     * Value = fully-qualified Eloquent model class.
     *
     * Issue #76: this is the whitelist. Any model NOT in this list
     * cannot be referenced via /api/communication/* — the middleware
     * returns 422 (model not chatter-enabled) instead of letting the
     * request reach the controller.
     */
    protected const ALLOWED = [
        'order' => 'Modules\\Sales\\Models\\Order',
        'invoice' => 'Modules\\Accounting\\Models\\Invoice',
        'customer' => 'App\\Models\\Customer',
        'vendor' => 'App\\Models\\Vendor',
        'contact' => 'App\\Models\\Contact',
        'journal_entry' => 'App\\Models\\JournalEntry',
        'bill' => 'App\\Models\\Bill',
    ];

    /**
     * Resolve a controlled alias to its FQCN. Returns null when the alias
     * is not in the registry.
     */
    public function resolveClass(string $alias): ?string
    {
        $key = strtolower(trim($alias));

        return self::ALLOWED[$key] ?? null;
    }

    /**
     * Resolve and load a specific record. Returns null if:
     *   - the alias is not whitelisted, OR
     *   - the class cannot be loaded, OR
     *   - the record_id does not exist.
     */
    public function resolveRecord(string $alias, int|string $recordId)
    {
        $class = $this->resolveClass($alias);
        if ($class === null || ! class_exists($class)) {
            return null;
        }

        $model = $class::find($recordId);

        return $model;
    }

    /**
     * @return string[] list of allowed aliases (for error messages and UI).
     */
    public function allowedAliases(): array
    {
        return array_keys(self::ALLOWED);
    }
}
