<?php

namespace Modules\AI\Services;

use Modules\AI\Data\PlatformKnowledge;
use Modules\AI\Data\StarterKnowledge;
use Modules\AI\Models\AIKnowledgeBase;

/**
 * Upserts knowledge entries into the tenant's ai_knowledge_base. Idempotent
 * by question text, so re-running the starter pack refreshes content
 * without creating duplicates.
 */
class KnowledgeIngestionService
{
    /**
     * @param  array<int, array{category?: string, question: string, answer: string, keywords?: string[]|null, priority?: int, language?: string}>  $items
     * @return int Number of entries ingested.
     */
    public function ingest(array $items, string $source = 'manual'): int
    {
        $count = 0;

        foreach ($items as $item) {
            $question = trim((string) ($item['question'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? ''));

            if ($question === '' || $answer === '') {
                continue;
            }

            AIKnowledgeBase::updateOrCreate(
                ['question' => $question],
                [
                    'category' => $item['category'] ?? 'faq',
                    'answer' => $answer,
                    'keywords' => $item['keywords'] ?? null,
                    'priority' => (int) ($item['priority'] ?? 0),
                    'language' => $item['language'] ?? 'en',
                    'source' => $source,
                    'is_active' => true,
                ]
            );

            $count++;
        }

        return $count;
    }

    /**
     * Load the built-in starter pack: storefront help-centre content plus
     * the NoovaERP platform knowledge (modules, pages, how-tos).
     */
    public function ingestStarterPack(): int
    {
        return $this->ingest(StarterKnowledge::all(), 'starter')
            + $this->ingestPlatformKnowledge();
    }

    /**
     * Load the NoovaERP platform knowledge (module directory + how-tos).
     */
    public function ingestPlatformKnowledge(): int
    {
        return $this->ingest(PlatformKnowledge::all(), 'platform');
    }
}
