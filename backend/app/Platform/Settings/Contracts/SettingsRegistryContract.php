<?php

namespace App\Platform\Settings\Contracts;

use App\Platform\Settings\SettingDefinition;

/**
 * Issue #921 (SS5): the platform-owned registry of tenant settings.
 */
interface SettingsRegistryContract
{
    /** @return list<SettingDefinition> */
    public function all(): array;

    /** @return list<string> every declared `Setting` store key */
    public function keys(): array;

    public function isDeclared(string $key): bool;

    /** @return array<string, SettingDefinition> screen field => definition */
    public function forScreen(string $screen): array;

    /**
     * Screen field => store key, for the fields persisted as their own
     * `Setting` row (JSON-block fields are merged by their owner instead).
     *
     * @return array<string, string>
     */
    public function storeKeysForScreen(string $screen): array;

    /**
     * Read-only "where used" view (#555) for a settings screen.
     *
     * @return array<string, array<string, mixed>>
     */
    public function whereUsed(string $screen): array;

    /**
     * The methods that render or save a screen; they never count as a reader.
     *
     * @return list<string>
     */
    public function screenMethods(string $screen): array;
}
