<?php

namespace Modules\FinancialReporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;

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
        return !$this->is_system;
    }

    public function canBeModified(): bool
    {
        return !$this->is_system;
    }

    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    public function toggleFavorite(): void
    {
        $this->update(['is_favorite' => !$this->is_favorite]);
    }

    public function duplicate(int $userId, ?string $name = null): self
    {
        $template = $this->replicate();
        $template->name = $name ?? $this->name . ' (Copy)';
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
