<?php

namespace App\Platform\Evidence\Contracts;

interface EvidencePackServiceContract
{
    public function createPack(string $name, string $type, ?string $description = null): object;

    public function addItem($packId, string $itemType, string $content, ?array $metadata = null): object;

    public function finalize($packId): object;

    public function verify($packId): bool;

    public function getPack($packId): ?object;
}
