<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\Log;
use Exception;

/**
 * DependencyCheckingService
 * 
 * Service for checking and validating module dependencies.
 */
class DependencyCheckingService
{
    /**
     * Check dependencies for the module
     * 
     * @return array
     */
    public function checkDependencies(): array
    {
        try {
            $requirements = [
                'php' => '>=8.1',
                'extensions' => ['json', 'pdo', 'redis'],
                'modules' => [] // Add required modules here
            ];

            $results = [
                'success' => true,
                'php_version' => [
                    'required' => $requirements['php'],
                    'current' => PHP_VERSION,
                    'satisfied' => version_compare(PHP_VERSION, '8.1', '>=')
                ],
                'extensions' => [],
                'missing_extensions' => []
            ];

            // Check extensions
            foreach ($requirements['extensions'] as $extension) {
                $isLoaded = extension_loaded($extension);
                $results['extensions'][$extension] = $isLoaded;

                if (!$isLoaded) {
                    $results['success'] = false;
                    $results['missing_extensions'][] = $extension;
                }
            }

            return $results;

        } catch (Exception $e) {
            Log::error('Dependency check failed', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Validate Composer dependencies
     */
    public function validateComposerDependencies(string $composerJsonPath): array
    {
        if (!file_exists($composerJsonPath)) {
            return [
                'success' => false,
                'error' => 'composer.json not found'
            ];
        }

        $content = json_decode(file_get_contents($composerJsonPath), true);
        $requirements = $content['require'] ?? [];

        return [
            'success' => true,
            'requirements' => $requirements
        ];
    }
}