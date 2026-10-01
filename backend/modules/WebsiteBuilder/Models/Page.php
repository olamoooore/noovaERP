<?php

namespace Modules\WebsiteBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Page extends Model
{
    use SoftDeletes;

    protected $table = 'wb_pages';

    protected $fillable = [
        'website_id',
        'uuid',
        'title',
        'slug',
        'is_home',
        'seo_title',
        'seo_description',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'is_home' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->title);
            }
        });
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class, 'website_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PageVersion::class, 'page_id')->orderByDesc('version');
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(PageVersion::class, 'page_id')->where('is_current', true);
    }

    /**
     * The version the PUBLIC surfaces must serve (P5 publish truthfulness):
     * the latest version explicitly marked published, so an unpublished
     * draft edit can never change what visitors see before it is published.
     * The `?? currentVersion` fallback keeps legacy pages (created before
     * version-marking, incl. every page that predates this change) serving
     * exactly as they always did — disclosed.
     */
    public function servedVersion(): ?PageVersion
    {
        $published = $this->versions()
            ->where('is_published', true)
            ->orderByDesc('version')
            ->first();

        return $published ?? $this->currentVersion;
    }

    public function getContent(): ?array
    {
        return $this->currentVersion?->content;
    }

    public function createVersion(array $content, int $userId): PageVersion
    {
        // Atomic: a failed insert must not leave the page with every version
        // marked stale and no current version — that made the editor load
        // blank (content: null) while the published HTML still rendered.
        // Confirmed on production 2026-09-22 after a failed save (PK collision
        // from a stale sequence) stranded Acme Production's Home page.
        return DB::transaction(function () use ($content, $userId) {
            // Mark all existing versions as not current
            $this->versions()->update(['is_current' => false]);

            $latestVersion = $this->versions()->max('version') ?? 0;

            return $this->versions()->create([
                'version' => $latestVersion + 1,
                'content' => $content,
                'is_current' => true,
                'is_published' => false,
                'created_by' => $userId,
            ]);
        });
    }
}
