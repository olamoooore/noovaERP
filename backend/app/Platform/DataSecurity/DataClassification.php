<?php

namespace App\Platform\DataSecurity;

use Illuminate\Database\Eloquent\Model;

class DataClassification extends Model
{
    protected $table = 'platform_data_classifications';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'classified_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = \Illuminate\Support\Str::uuid();
            }
        });
    }
}
