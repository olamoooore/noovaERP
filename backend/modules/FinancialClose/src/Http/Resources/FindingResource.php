<?php

namespace Modules\FinancialClose\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * AI Finding resource.
 * SECURITY: raw structured_output (full AI payload) is NOT exposed here.
 * Permission-scoped access to structured_output requires a separate policy gate.
 */
class FindingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $structuredOutput = is_array($this->structured_output) ? $this->structured_output : [];
        $sourceRefs = is_array($structuredOutput['source_refs'] ?? null) ? $structuredOutput['source_refs'] : [];

        // Resolve real journal entry numbers at read time so BOTH newly
        // generated and previously-stored findings surface "JE-2026-0238"
        // (trackable) instead of an opaque id, and deep-link to the exact
        // entry rather than the journal list. Batched to avoid N+1.
        $journalIds = collect($sourceRefs)
            ->filter(fn ($ref) => is_array($ref) && ($ref['type'] ?? null) === 'journal_entry' && ! empty($ref['id']))
            ->map(fn ($ref) => (int) $ref['id'])
            ->unique()
            ->values()
            ->all();
        $journalNumbers = $journalIds
            ? \App\Models\JournalEntry::withTrashed()->whereIn('id', $journalIds)->pluck('entry_number', 'id')
            : collect();

        return [
            'id' => $this->id,
            'review_id' => $this->review_id,
            'entity_id' => $this->entity_id,
            'account_code' => $this->account_code,
            'finding_type' => $this->finding_type,
            'status' => $this->status,
            'risk_status' => $this->risk_status,
            'confidence' => $this->confidence,
            'description' => $this->description,
            'likely_cause' => isset($structuredOutput['likely_cause']) ? (string) $structuredOutput['likely_cause'] : null,
            'recommendation' => isset($structuredOutput['recommendation']) ? (string) $structuredOutput['recommendation'] : null,
            'source_refs' => collect($sourceRefs)
                ->filter(fn ($ref) => is_array($ref) && ! empty($ref['id']))
                ->map(function ($ref) use ($journalNumbers) {
                    $type = $ref['type'] ?? 'transaction';
                    $id = (string) $ref['id'];
                    $label = $ref['label'] ?? ('#'.$id);
                    $route = $ref['route'] ?? null;

                    if ($type === 'journal_entry') {
                        $number = $journalNumbers[$id] ?? null;
                        if ($number) {
                            $label = (string) $number;
                        }
                        // Always deep-link to the exact entry dialog.
                        $route = '/accounting/core/journal-entries?entry_id='.$id;
                    }

                    return [
                        'type' => $type,
                        'id' => $id,
                        'label' => $label,
                        'route' => $route,
                        'meta' => is_array($ref['meta'] ?? null) ? $ref['meta'] : null,
                    ];
                })
                ->values()
                ->all(),
            'detail_items' => collect($structuredOutput)
                ->except(['likely_cause', 'recommendation', 'source_refs'])
                ->take(4)
                ->map(fn ($value, $key) => [
                    'label' => Str::of($key)->replace('_', ' ')->title()->toString(),
                    'value' => is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value,
                ])
                ->values()
                ->all(),
            'accepted_by' => $this->accepted_by,
            'accepted_at' => $this->accepted_at,
            'dismissed_by' => $this->dismissed_by,
            'dismissed_at' => $this->dismissed_at,
            'created_at' => $this->created_at,
            // raw structured_output intentionally omitted — only safe preview fields are exposed here
        ];
    }
}
