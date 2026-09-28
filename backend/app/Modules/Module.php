<?php

namespace App\Modules;

final readonly class Module
{
    /**
     * @param  array<int, class-string>  $providers
     * @param  array<int, string>  $dependencies  Module keys this module depends on
     * @param  bool  $deprecated  FR-ARC-01 archive flag (module.json "deprecated") —
     *                            parsed on the live discovery path so governance
     *                            consumers (catalog UI, guards, tooling) can read it.
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $type,
        public string $basePath,
        public array $providers,
        public array $dependencies = [],
        public bool $deprecated = false,
    ) {}

    public function isCore(): bool
    {
        return $this->type === 'core';
    }

    public function isDeprecated(): bool
    {
        return $this->deprecated;
    }

    /**
     * Check if this module depends on another module
     */
    public function dependsOn(string $moduleKey): bool
    {
        return in_array($moduleKey, $this->dependencies, true);
    }

    /**
     * Get all direct dependencies
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    public function tenantApiRoutesPath(): ?string
    {
        $path = $this->basePath.'/routes/api.php';

        return file_exists($path) ? $path : null;
    }

    public function tenantMigrationsPath(): ?string
    {
        $path = $this->basePath.'/database/migrations/tenant';

        return is_dir($path) ? $path : null;
    }

    public function viewsPath(): ?string
    {
        $path = $this->basePath.'/resources/views';

        return is_dir($path) ? $path : null;
    }

    public function translationsPath(): ?string
    {
        $path = $this->basePath.'/resources/lang';

        return is_dir($path) ? $path : null;
    }

    public function configPath(): ?string
    {
        $path = $this->basePath.'/config/'.$this->key.'.php';

        return file_exists($path) ? $path : null;
    }
}
