<?php

namespace Modules\WebsiteBuilder\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
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

    public function getContent(): ?array
    {
        return $this->currentVersion?->content;
    }

    public function createVersion(array $content, int $userId): PageVersion
    {
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
    }
}
