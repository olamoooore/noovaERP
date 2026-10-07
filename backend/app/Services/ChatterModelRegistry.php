<?php

namespace App\Services;

use Illuminate\Support\Str;
use LogicException;

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
 * To add a new chatter-enabled model, add it to config/chatter.php
 * (`allowed_models` plus its view/write permission maps) — issue #633.
 */
class ChatterModelRegistry
{
    /**
     * Issue #633 — Decision (7 Oct 2026, product owner): "Option B — keep and
     * fix ... repoint the aliases to real classes and derive the registry from
     * `config/chatter.php` so there is one subject list."
     *
     * The alias whitelist is no longer a hand-kept constant (which had drifted
     * to non-existent classes: Modules\Sales\Models\Order,
     * Modules\Accounting\Models\Invoice, App\Models\Contact). It is derived
     * from `chatter.allowed_models`: each configured model is exposed under
     * the snake_case of its class basename (Invoice → `invoice`,
     * JournalEntry → `journal_entry`), unless `chatter.subject_aliases` names
     * a different alias for it (SalesOrder → `order`, kept for API stability).
     *
     * Any model NOT in `chatter.allowed_models` cannot be referenced via
     * /api/communication/* — the middleware returns 422.
     *
     * @return array<string, class-string> alias => FQCN
     */
    protected function aliasMap(): array
    {
        $overrides = (array) config('chatter.subject_aliases', []);
        $map = [];

        foreach ((array) config('chatter.allowed_models', []) as $class) {
            $class = (string) $class;
            $alias = strtolower((string) ($overrides[$class] ?? Str::snake(class_basename($class))));

            if (isset($map[$alias]) && $map[$alias] !== $class) {
                throw new LogicException(
                    "Chatter alias '{$alias}' is ambiguous ({$map[$alias]} vs {$class}); set chatter.subject_aliases."
                );
            }

            $map[$alias] = $class;
        }

        return $map;
    }

    /**
     * Resolve a controlled alias to its FQCN. Returns null when the alias
     * is not in the registry.
     */
    public function resolveClass(string $alias): ?string
    {
        $key = strtolower(trim($alias));

        return $this->aliasMap()[$key] ?? null;
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
        return array_keys($this->aliasMap());
    }
}
