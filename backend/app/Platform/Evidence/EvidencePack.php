<?php

namespace App\Platform\Evidence;

use Illuminate\Database\Eloquent\Model;

class EvidencePack extends Model
{
    protected $table = 'platform_evidence_packs';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';
    protected $guarded = [];

    protected $casts = [
        'finalized_at' => 'datetime',
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

    public function items()
    {
        return $this->hasMany(EvidenceItem::class, 'pack_id');
    }
}
