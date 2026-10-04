<?php

namespace App\Platform\Exceptions\Contracts;

use App\Platform\Exceptions\ExceptionReport;

interface ExceptionServiceContract
{
    public function log(string $module, string $exceptionType, string $severity, string $message, ?array $context = null): object;

    /**
     * Persist a structured, typed business failure (Issue #902).
     */
    public function raise(ExceptionReport $report): object;

    /**
     * Best-effort raise that never breaks the operation that already finished.
     */
    public function report(ExceptionReport $report): ?object;

    public function assign(string $exceptionId, int $userId): object;

    public function resolve(string $exceptionId, int $userId, ?string $resolution = null): object;

    public function retry(string $exceptionId): object;

    public function getStats(): array;
}
