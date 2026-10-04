<?php

namespace App\Platform\Exceptions;

use Illuminate\Support\Facades\Log;

/**
 * Issue #902: the registry that turns a queued exception's `retry_handler`
 * key into the operation that must run again.
 *
 * Each domain registers its own handlers (Sales registers its own in the
 * SalesServiceProvider, the compliance workflow in
 * CompliancePluginServiceProvider) so the platform does not need to know how
 * a delivery order, an e-invoice or a payment is retried. A handler receives
 * the queued ExceptionQueue row and must THROW when the retry fails, so the
 * queue can record the retry error instead of pretending success.
 */
class ExceptionRetryRegistry
{
    /** @var array<string, callable(ExceptionQueue): void> */
    private array $handlers = [];

    /**
     * @param  callable(ExceptionQueue): void  $handler
     */
    public function register(string $key, callable $handler): void
    {
        $this->handlers[$key] = $handler;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->handlers);
    }

    /**
     * @throws \RuntimeException when no handler is registered for the key
     */
    public function run(string $key, ExceptionQueue $exception): void
    {
        $handler = $this->handlers[$key] ?? null;

        if ($handler === null) {
            throw new \RuntimeException("No retry handler registered for '{$key}'.");
        }

        try {
            $handler($exception);
        } catch (\Throwable $e) {
            Log::error('Platform exception retry handler failed', [
                'exception_id' => $exception->id,
                'retry_handler' => $key,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
