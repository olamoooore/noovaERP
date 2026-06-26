<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditExport extends Model
{
    use HasFactory;

    protected $fillable = [
        'auditor_id',
        'tenant_id',
        'scope',
        'file_name',
        'file_path',
        'file_size',
        'file_type',
        'downloaded_at',
        'download_ip',
    ];

    protected $casts = [
        'scope' => 'array',
        'downloaded_at' => 'datetime',
        'file_size' => 'integer',
    ];

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(Auditor::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
