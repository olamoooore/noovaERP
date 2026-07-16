<?php

namespace Modules\FinancialReporting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReportTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'category',
        'report_type',
        'default_filters',
        'chart_config',
        'column_config',
        'configuration',
        'is_system',
        'is_public',
        'is_favorite',
        'usage_count',
        'icon',
        'color',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'default_filters' => 'array',
        'chart_config' => 'array',
        'column_config' => 'array',
        'is_system' => 'boolean',
        'is_public' => 'boolean',
        'is_favorite' => 'boolean',
    ];

    protected $appends = ['configuration'];

    /**
     * Virtual "configuration" accessor — combines the three JSON columns
     * into a single array for backward compatibility with controllers/tests.
     */
    public function getConfigurationAttribute(): array
    {
        $config = $this->chart_config ?? [];
        if (! empty($this->column_config)) {
            $config['columns'] = $this->column_config;
        }
        if (! empty($this->default_filters)) {
            $config['filters'] = $this->default_filters;
        }

        return $config;
    }

    /**
     * Virtual "configuration" mutator — distributes into the three JSON columns.
     */
    public function setConfigurationAttribute($value): void
    {
        if (! is_array($value)) {
            return;
        }

        $this->attributes['column_config'] = json_encode($value['columns'] ?? []);
        $this->attributes['default_filters'] = json_encode($value['filters'] ?? []);

        $remaining = array_diff_key($value, array_flip(['columns', 'filters']));
        $this->attributes['chart_config'] = json_encode($remaining);
    }

    /**
     * Relationships
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scopes
     */
    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeFavorite($query)
    {
        return $query->where('is_favorite', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByReportType($query, string $reportType)
    {
        return $query->where('report_type', $reportType);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('is_public', true)
                ->orWhere('is_system', true)
                ->orWhere('created_by', $userId);
        });
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Methods
     */
    public function canBeDeleted(): bool
    {
        return ! $this->is_system;
    }

    public function canBeModified(): bool
    {
        return ! $this->is_system;
    }

    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    public function toggleFavorite(): void
    {
        $this->update(['is_favorite' => ! $this->is_favorite]);
    }

    public function duplicate(int $userId, ?string $name = null): self
    {
        $template = $this->replicate();
        $template->name = $name ?? $this->name.' (Copy)';
        $template->is_system = false;
        $template->is_public = false;
        $template->is_favorite = false;
        $template->usage_count = 0;
        $template->created_by = $userId;
        $template->updated_by = null;
        $template->save();

        return $template;
    }

    /**
     * Static methods
     */
    public static function getSystemTemplates(): \Illuminate\Database\Eloquent\Collection
    {
        return static::system()->ordered()->get();
    }

    public static function getPublicTemplates(): \Illuminate\Database\Eloquent\Collection
    {
        return static::public()->ordered()->get();
    }

    public static function getUserTemplates(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return static::forUser($userId)->ordered()->get();
    }

    public static function getByCategory(string $category): \Illuminate\Database\Eloquent\Collection
    {
        return static::byCategory($category)->ordered()->get();
    }

    public static function getMostUsed(int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return static::orderBy('usage_count', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Modules\FinancialReporting\Database\Factories\ReportTemplateFactory::new();
    }
}
