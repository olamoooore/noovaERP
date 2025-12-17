<?php

namespace App\Modules;

final readonly class Module
{
    /**
     * @param array<int, class-string> $providers
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $type,
        public string $basePath,
        public array $providers,
    ) {
    }

    public function isCore(): bool
    {
        return $this->type === 'core';
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
