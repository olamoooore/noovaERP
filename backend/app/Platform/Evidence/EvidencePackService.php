<?php

namespace App\Platform\Evidence;

use App\Platform\Evidence\Contracts\EvidencePackServiceContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EvidencePackService implements EvidencePackServiceContract
{
    public function createPack(string $name, string $type, ?string $description = null): object
    {
        $pack = EvidencePack::create([
            'id' => Str::uuid(),
            'tenant_id' => tenant()->id,
            'name' => $name,
            'type' => $type,
            'description' => $description,
            'status' => 'draft',
        ]);

        return (object) $pack->toArray();
    }

    public function addItem($packId, string $itemType, string $content, ?array $metadata = null): object
    {
        $pack = EvidencePack::where('tenant_id', tenant()->id)
            ->findOrFail((string) $packId);

        $item = EvidenceItem::create([
            'id' => Str::uuid(),
            'tenant_id' => tenant()->id,
            'pack_id' => $pack->id,
            'item_type' => $itemType,
            'content' => $content,
            'metadata' => $metadata,
        ]);

        return (object) $item->toArray();
    }

    public function finalize($packId): object
    {
        $pack = EvidencePack::where('tenant_id', tenant()->id)
            ->with('items')
            ->findOrFail((string) $packId);

        $content = $pack->items->pluck('content')->implode('|');
        $integrityHash = hash('sha256', $content);

        $pack->update([
            'status' => 'finalized',
            'integrity_hash' => $integrityHash,
            'finalized_at' => now(),
        ]);

        return (object) $pack->fresh()->toArray();
    }

    public function verify($packId): bool
    {
        $pack = EvidencePack::where('tenant_id', tenant()->id)
            ->with('items')
            ->findOrFail((string) $packId);

        if ($pack->status !== 'finalized') {
            return false;
        }

        $content = $pack->items->pluck('content')->implode('|');
        $currentHash = hash('sha256', $content);

        return $currentHash === $pack->integrity_hash;
    }

    public function getPack($packId): ?object
    {
        $pack = EvidencePack::where('tenant_id', tenant()->id)
            ->with('items')
            ->find((string) $packId);

        if (!$pack) {
            return null;
        }

        return (object) [
            'pack' => $pack->toArray(),
            'items' => $pack->items->toArray(),
        ];
    }
}
