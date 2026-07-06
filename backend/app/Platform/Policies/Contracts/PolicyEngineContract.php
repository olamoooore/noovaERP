<?php

namespace App\Platform\Policies\Contracts;

use App\Platform\Policies\PolicyResult;

interface PolicyEngineContract
{
    public function register(string $entityType, string $action, array $rules, int $priority = 0): object;
    public function evaluate(string $entityType, string $action, array $context): PolicyResult;
    public function discover(string $entityType): array;
    public function deactivate($ruleId): bool;
}
