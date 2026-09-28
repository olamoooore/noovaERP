<?php

namespace Modules\Storefront\Shortcodes;

use InvalidArgumentException;

/**
 * Central shortcode registry — the single source of truth (FR-BLD-03).
 * The editor block list, settings panels, server renderer, function
 * reference and tests all read from this registry; none define functions
 * locally.
 *
 * Extension point for other modules (Appendix B §B3.7):
 *   app(StorefrontShortcodeRegistry::class)->register(Definition::make(...))
 * from a module provider. Malformed definitions fail fast at registration.
 */
final class StorefrontShortcodeRegistry
{
    /** Namespaced function key: `storefront.<function>` (decision D1), lowercase dot-separated. */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){1,2}$/';

    /** @var array<string,Definition> */
    private array $definitions = [];

    public function register(Definition $definition): void
    {
        if (! preg_match(self::KEY_PATTERN, $definition->key)) {
            throw new InvalidArgumentException(
                "Invalid shortcode key '{$definition->key}'. Expected namespaces like 'storefront.products'."
            );
        }

        if (isset($this->definitions[$definition->key])) {
            throw new InvalidArgumentException("Shortcode key '{$definition->key}' is already registered.");
        }

        $this->definitions[$definition->key] = $definition;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    public function get(string $key): ?Definition
    {
        return $this->definitions[$key] ?? null;
    }

    /**
     * @return array<string,Definition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * Editor-facing schema for every registered function (blocks endpoint).
     *
     * @return array<int,array<string,mixed>>
     */
    public function schema(): array
    {
        return array_map(
            static fn (Definition $d) => $d->toSchema(),
            array_values($this->definitions)
        );
    }
}
