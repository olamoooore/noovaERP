<?php

namespace App\Platform\Evidence;

use Illuminate\Database\Eloquent\Model;

class EvidenceItem extends Model
{
    protected $table = 'platform_evidence_items';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
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

    public function pack()
    {
        return $this->belongsTo(EvidencePack::class, 'pack_id');
    }
}
