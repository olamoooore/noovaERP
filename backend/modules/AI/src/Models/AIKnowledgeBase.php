<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AIKnowledgeBase extends Model
{
    protected $table = 'ai_knowledge_base';

    protected $fillable = [
        'category',
        'question',
        'answer',
        'keywords',
        'language',
        'is_active',
        'usage_count',
    ];

    protected $casts = [
        'keywords' => 'array',
        'is_active' => 'boolean',
        'usage_count' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeLanguage($query, string $language)
    {
        return $query->where('language', $language);
    }

    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    public static function search(string $query, string $language = 'en', int $limit = 5): array
    {
        return self::active()
            ->language($language)
            ->where(function ($q) use ($query) {
                $q->where('question', 'ilike', "%{$query}%")
                    ->orWhere('answer', 'ilike', "%{$query}%")
                    ->orWhereJsonContains('keywords', $query);
            })
            ->orderBy('usage_count', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}
