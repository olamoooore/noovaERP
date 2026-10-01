<?php

namespace App\Services;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Attach/detach free-form tags on any taggable document and look them up.
 *
 * The subject whitelist mirrors the chatter module's approach: only models we
 * explicitly allow can be tagged, and the type is resolved from a short alias
 * so the API never accepts an arbitrary class name.
 */
class TagService
{
    /** alias => model class */
    public const SUBJECTS = [
        'invoice' => \App\Models\Invoice::class,
        'quotation' => \App\Models\Quotation::class,
        'sales_order' => \Modules\Sales\Models\SalesOrder::class,
        'delivery_order' => \Modules\Sales\Models\DeliveryOrder::class,
        'purchase_order' => \Modules\Inventory\Models\PurchaseOrder::class,
        'bill' => \App\Models\Bill::class,
        'customer' => \App\Models\Customer::class,
        'vendor' => \App\Models\Vendor::class,
        'payment' => \App\Models\Payment::class,
        'product' => \App\Models\Product::class,
    ];

    /**
     * Resolve the short alias for a model instance, or null when not taggable.
     */
    public function aliasFor(string $class): ?string
    {
        $alias = array_search($class, self::SUBJECTS, true);

        return $alias === false ? null : $alias;
    }

    public function modelFor(string $alias): ?string
    {
        return self::SUBJECTS[$alias] ?? null;
    }

    /**
     * Find an existing tag by slug or create it with the next palette colour.
     */
    public function findOrCreate(string $name): Tag
    {
        $name = trim($name);
        $slug = Tag::slugFor($name);

        if ($slug === '') {
            throw new \InvalidArgumentException('Tag name cannot be empty.');
        }

        return DB::transaction(function () use ($name, $slug) {
            $tag = Tag::where('slug', $slug)->lockForUpdate()->first();

            if ($tag) {
                return $tag;
            }

            // Rotate through the palette so chips stay visually distinct.
            $used = Tag::count();

            return Tag::create([
                'name' => $name,
                'slug' => $slug,
                'color' => Tag::COLORS[$used % count(Tag::COLORS)],
            ]);
        });
    }

    /**
     * Attach tags (by name) to a subject. Existing links are left untouched.
     *
     * @param  array<int, string>  $names
     * @return array<int, Tag>
     */
    public function attach(Model $subject, array $names, ?int $userId = null): array
    {
        $tags = [];

        foreach (array_unique(array_filter(array_map('trim', $names))) as $name) {
            $tag = $this->findOrCreate($name);

            $exists = DB::table('taggables')
                ->where('tag_id', $tag->id)
                ->where('taggable_type', $subject->getMorphClass())
                ->where('taggable_id', $subject->getKey())
                ->exists();

            if (! $exists) {
                DB::table('taggables')->insert([
                    'tag_id' => $tag->id,
                    'taggable_type' => $subject->getMorphClass(),
                    'taggable_id' => $subject->getKey(),
                    'created_by' => $userId,
                    'created_at' => now(),
                ]);
            }

            $tags[] = $tag;
        }

        return $tags;
    }

    /**
     * Replace the entire tag set of a subject.
     *
     * @param  array<int, string>  $names
     * @return array<int, Tag>
     */
    public function sync(Model $subject, array $names, ?int $userId = null): array
    {
        DB::table('taggables')
            ->where('taggable_type', $subject->getMorphClass())
            ->where('taggable_id', $subject->getKey())
            ->delete();

        return $this->attach($subject, $names, $userId);
    }

    public function detach(Model $subject, int $tagId): void
    {
        DB::table('taggables')
            ->where('tag_id', $tagId)
            ->where('taggable_type', $subject->getMorphClass())
            ->where('taggable_id', $subject->getKey())
            ->delete();
    }

    /**
     * Tags for a subject, as plain arrays for API responses.
     */
    public function forSubject(Model $subject): array
    {
        return $this->tagsForSubject($subject);
    }

    public function tagsForSubject(Model $subject): array
    {
        return DB::table('taggables')
            ->join('tags', 'tags.id', '=', 'taggables.tag_id')
            ->where('taggables.taggable_type', $subject->getMorphClass())
            ->where('taggables.taggable_id', $subject->getKey())
            ->orderBy('tags.name')
            ->get(['tags.id', 'tags.name', 'tags.slug', 'tags.color'])
            ->map(fn ($t) => [
                'id' => (int) $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'color' => $t->color,
            ])
            ->all();
    }

    /**
     * All tags with usage counts, for pickers and filters.
     */
    public function allWithCounts(): array
    {
        return Tag::query()
            ->leftJoin('taggables', 'taggables.tag_id', '=', 'tags.id')
            ->select('tags.id', 'tags.name', 'tags.slug', 'tags.color', DB::raw('COUNT(taggables.id) as usage_count'))
            ->groupBy('tags.id', 'tags.name', 'tags.slug', 'tags.color')
            ->orderByDesc('usage_count')
            ->orderBy('tags.name')
            ->get()
            ->map(fn ($t) => [
                'id' => (int) $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'color' => $t->color,
                'usage_count' => (int) $t->usage_count,
            ])
            ->all();
    }

    /**
     * Taggable IDs of a given alias carrying any of the supplied tag slugs.
     *
     * @param  array<int, string>  $slugs
     * @return array<int, int>
     */
    public function subjectIdsForTagSlugs(array $slugs, string $alias): array
    {
        $class = $this->modelFor($alias);
        if (! $class) {
            return [];
        }

        return DB::table('taggables')
            ->join('tags', 'tags.id', '=', 'taggables.tag_id')
            ->whereIn('tags.slug', $slugs)
            ->where('taggables.taggable_type', (new $class)->getMorphClass())
            ->pluck('taggables.taggable_id')
            ->unique()
            ->map('intval')
            ->values()
            ->all();
    }

    /**
     * Subject ids of a type whose tags match a free-text search — used by the
     * wide search so "vip" finds every customer tagged VIP.
     *
     * @return int[]
     */
    public function subjectIdsForTagNameSearch(string $search, string $alias): array
    {
        $class = $this->modelFor($alias);
        if (! $class) {
            return [];
        }

        return DB::table('taggables')
            ->join('tags', 'tags.id', '=', 'taggables.tag_id')
            ->where('tags.name', 'ilike', "%{$search}%")
            ->where('taggables.taggable_type', (new $class)->getMorphClass())
            ->pluck('taggables.taggable_id')
            ->unique()
            ->map('intval')
            ->values()
            ->all();
    }

    /**
     * Tag names (per subject id) that match the search — for showing WHY a
     * row matched ("Tag: VIP") in pickers and lists.
     *
     * @param  int[]  $subjectIds
     * @return array<int, array<int, string>>
     */
    public function matchingTagNames(array $subjectIds, string $alias, string $search): array
    {
        $class = $this->modelFor($alias);
        if (! $class || $subjectIds === []) {
            return [];
        }

        return DB::table('taggables')
            ->join('tags', 'tags.id', '=', 'taggables.tag_id')
            ->where('taggables.taggable_type', (new $class)->getMorphClass())
            ->whereIn('taggables.taggable_id', $subjectIds)
            ->where('tags.name', 'ilike', "%{$search}%")
            ->select('taggables.taggable_id', 'tags.name')
            ->get()
            ->groupBy('taggable_id')
            ->map(fn ($rows) => $rows->pluck('name')->unique()->take(3)->values()->all())
            ->all();
    }
}
