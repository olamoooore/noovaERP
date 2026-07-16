<?php

namespace Modules\IoT\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IoTDeviceTemplate extends Model
{
    use HasFactory;

    protected $table = 'iot_device_templates';

    protected $fillable = [
        'name',
        'category',
        'type',
        'vendor',
        'model',
        'connection_type',
        'config',
        'description',
        'image_url',
        'is_verified',
        'is_popular',
        'supported_features',
        'setup_instructions',
    ];

    protected $casts = [
        'config' => 'array',
        'supported_features' => 'array',
        'setup_instructions' => 'array',
        'is_verified' => 'boolean',
        'is_popular' => 'boolean',
    ];

    // Scopes
    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    public function scopePopular($query)
    {
        return $query->where('is_popular', true);
    }

    // Relationships
    public function devices()
    {
        return $this->hasMany(IoTDevice::class, 'template_id');
    }

    // Helper methods
    public function getFullNameAttribute()
    {
        return $this->vendor.' '.($this->model ? $this->model.' ' : '').$this->name;
    }

    public function getCategoryDisplayNameAttribute()
    {
        return match ($this->category) {
            'printers' => 'Printers',
            'scanners' => 'Scanners',
            'cameras' => 'Cameras',
            'kiosks' => 'Kiosks',
            'sensors' => 'Sensors',
            'displays' => 'Displays',
            default => ucfirst($this->category),
        };
    }
}
