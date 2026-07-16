<?php

namespace Modules\WhatsApp\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WhatsAppApiKey extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'whatsapp_api_keys';

    protected $fillable = [
        'key',
        'name',
        'description',
        'platform_name',
        'platform_url',
        'whatsapp_provider',
        'api_endpoint',
        'webhook_secret',
        'is_active',
        'usage_count',
        'last_used_at',
        'expires_at',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'usage_count' => 'integer',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = [
        'key',
        'webhook_secret',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->key)) {
                $model->key = static::generateApiKey();
            }
        });

        static::saved(function (self $apiKey): void {
            $apiKey->syncWebhookTenantLookup();
        });

        static::deleted(function (self $apiKey): void {
            $apiKey->deleteWebhookTenantLookup();
        });
    }

    public static function webhookTenantLookupBaseTable(): string
    {
        return 'whatsapp_webhook_tenant_lookups';
    }

    public static function webhookTenantLookupTable(): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? 'public.'.static::webhookTenantLookupBaseTable()
            : static::webhookTenantLookupBaseTable();
    }

    /**
     * Generate a unique API key.
     */
    public static function generateApiKey(): string
    {
        do {
            $key = 'wapibiz_'.Str::random(64);
        } while (static::where('key', $key)->exists());

        return $key;
    }

    /**
     * Encrypt the API key when setting it.
     */
    public function setKeyAttribute($value): void
    {
        if ($value) {
            $this->attributes['key'] = Crypt::encryptString($value);
        }
    }

    /**
     * Decrypt the API key when getting it.
     */
    public function getKeyAttribute($value): ?string
    {
        if ($value) {
            try {
                return Crypt::decryptString($value);
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Encrypt the webhook secret when setting it.
     */
    public function setWebhookSecretAttribute($value): void
    {
        if ($value) {
            $this->attributes['webhook_secret'] = Crypt::encryptString($value);
        }
    }

    /**
     * Decrypt the webhook secret when getting it.
     */
    public function getWebhookSecretAttribute($value): ?string
    {
        if ($value) {
            try {
                return Crypt::decryptString($value);
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Get the user who created this API key.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Check if the API key is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Check if the API key is valid (active and not expired).
     */
    public function isValid(): bool
    {
        return $this->is_active && ! $this->isExpired();
    }

    public function syncWebhookTenantLookup(): void
    {
        if (! function_exists('tenant') || ! tenant() || ! $this->key) {
            return;
        }

        if (! Schema::hasTable(static::webhookTenantLookupBaseTable())) {
            return;
        }

        $timestamp = now();

        DB::table(static::webhookTenantLookupTable())->upsert(
            [[
                'key_hash' => hash('sha256', $this->key),
                'tenant_id' => (string) tenant()->getTenantKey(),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]],
            ['key_hash'],
            ['tenant_id', 'updated_at']
        );
    }

    public function deleteWebhookTenantLookup(): void
    {
        if (! Schema::hasTable(static::webhookTenantLookupBaseTable()) || ! $this->key) {
            return;
        }

        DB::table(static::webhookTenantLookupTable())
            ->where('key_hash', hash('sha256', $this->key))
            ->delete();
    }

    /**
     * Increment the usage count and update last used timestamp.
     */
    public function recordUsage(): void
    {
        $this->increment('usage_count');
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Revoke (deactivate) the API key.
     */
    public function revoke(): void
    {
        $this->update(['is_active' => false]);
    }

    /**
     * Activate the API key.
     */
    public function activate(): void
    {
        $this->update(['is_active' => true]);
    }

    /**
     * Get a masked version of the API key for display.
     */
    public function getMaskedKey(): string
    {
        $key = $this->key;
        if (! $key) {
            return '****';
        }

        // Show first 16 chars and last 4 chars
        $length = strlen($key);
        if ($length <= 20) {
            return substr($key, 0, 8).'****';
        }

        return substr($key, 0, 16).'...'.substr($key, -4);
    }

    /**
     * Scope to get only active API keys.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get only valid (active & not expired) API keys.
     */
    public function scopeValid($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope to get expired API keys.
     */
    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }
}
