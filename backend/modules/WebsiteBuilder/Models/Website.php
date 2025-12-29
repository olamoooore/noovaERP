<?php

namespace Modules\WebsiteBuilder\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Website extends Model
{
    use SoftDeletes;

    protected $table = 'wb_websites';

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'status',
        'settings',
        'favicon',
        'created_by',
        'published_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'published_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->name);
            }
        });
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class, 'website_id')->orderBy('sort_order');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'website_id');
    }

    public function publishLogs(): HasMany
    {
        return $this->hasMany(PublishLog::class, 'website_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function homePage()
    {
        return $this->pages()->where('is_home', true)->first();
    }
}
