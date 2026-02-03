<?php

namespace Modules\IoT\Services\Drivers;

interface PrinterDriverInterface
{
    /**
     * Connect to the printer.
     */
    public function connect(): bool;

    /**
     * Print a document.
     */
    public function print(array $data): array;

    /**
     * Get printer status.
     */
    public function getStatus(): array;

    /**
     * Disconnect from the printer.
     */
    public function disconnect(): void;
}