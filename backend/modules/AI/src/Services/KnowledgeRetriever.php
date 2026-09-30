<?php

namespace Modules\AI\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\AI\Models\AIKnowledgeBase;

/**
 * Retrieval over the tenant's ai_knowledge_base.
 *
 * Primary path is Postgres full-text search (stemmed, ranked via ts_rank
 * against the generated search_vector); when the FTS query produces no
 * matches (typos, very short/stopword queries) it falls back to a keyword
 * ILIKE scan. Both paths are read-only and parameterized.
 */
class KnowledgeRetriever
{
    /** Words ignored when extracting search terms. */
    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'what', 'where', 'when', 'who', 'why',
        'how', 'does', 'did', 'your', 'you', 'are', 'can', 'could', 'would',
        'should', 'will', 'have', 'has', 'had', 'from', 'about', 'this',
        'that', 'these', 'those', 'there', 'their', 'them', 'they', 'please',
        'hello', 'hi', 'hey', 'just', 'some', 'any', 'all', 'not', 'but',
        'was', 'were', 'been', 'being', 'get', 'got', 'into', 'out', 'our',
    ];

    /**
     * Retrieve the most relevant active knowledge entries for a query.
     *
     * @param  string[]  $categories  Optional category filter.
     * @return Collection<int, AIKnowledgeBase>
     */
    public function retrieve(string $query, int $limit = 5, string $language = 'en', array $categories = []): Collection
    {
        $query = trim($query);
        if ($query === '' || $limit < 1) {
            return collect();
        }

        $hits = $this->fullTextSearch($query, $limit, $language, $categories);
        if ($hits->isNotEmpty()) {
            return $hits;
        }

        return $this->keywordSearch($query, $limit, $language, $categories);
    }

    /**
     * Meaningful lowercase terms in a text (stopwords and short words
     * removed, de-duplicated). Shared with the assistant context builder
     * (catalog search uses the same terms).
     *
     * @return string[]
     */
    public function keywords(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/i', mb_strtolower($text)) ?: [];
        $terms = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $terms[$word] = true;
        }

        return array_keys($terms);
    }

    /**
     * Render retrieved entries as an authoritative context block for the LLM.
     */
    public function formatContext(Collection $hits): string
    {
        $lines = $hits->map(function (AIKnowledgeBase $doc): string {
            $answer = trim((string) $doc->answer);

            return "- Q: {$doc->question}\n  A: {$answer}";
        })->implode("\n");

        return "OFFICIAL BUSINESS KNOWLEDGE (recorded by the business itself — authoritative for policy, shipping, returns, payment, membership and support questions. Quote it accurately; never contradict it; do not invent details beyond it):\n{$lines}";
    }

    private function fullTextSearch(string $query, int $limit, string $language, array $categories): Collection
    {
        try {
            $params = [$query, $language, $query];

            $categorySql = '';
            if ($categories !== []) {
                $placeholders = implode(',', array_fill(0, count($categories), '?'));
                $categorySql = "AND category IN ({$placeholders})";
                $params = array_merge($params, array_values($categories));
            }

            $params[] = $limit;

            return AIKnowledgeBase::fromQuery(
                "SELECT *, ts_rank(search_vector, websearch_to_tsquery('english', ?)) AS rank
                 FROM ai_knowledge_base
                 WHERE is_active = true
                   AND language = ?
                   AND search_vector @@ websearch_to_tsquery('english', ?)
                   {$categorySql}
                 ORDER BY rank DESC, priority DESC, usage_count DESC
                 LIMIT ?",
                $params
            );
        } catch (\Throwable $e) {
            Log::warning('KnowledgeRetriever: full-text search failed', [
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    private function keywordSearch(string $query, int $limit, string $language, array $categories): Collection
    {
        $terms = array_slice($this->keywords($query), 0, 8);
        if ($terms === []) {
            return collect();
        }

        $builder = AIKnowledgeBase::query()
            ->where('is_active', true)
            ->where('language', $language);

        if ($categories !== []) {
            $builder->whereIn('category', $categories);
        }

        $builder->where(function ($where) use ($terms) {
            foreach ($terms as $term) {
                $where->orWhere('question', 'ilike', "%{$term}%")
                    ->orWhere('answer', 'ilike', "%{$term}%")
                    ->orWhereRaw('keywords::text ilike ?', ["%{$term}%"]);
            }
        });

        return $builder
            ->orderByDesc('priority')
            ->orderByDesc('usage_count')
            ->limit($limit)
            ->get();
    }
}
