<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Issue #115 (BLOCKER): moved out of the misnamed ContactDuplicate.php into a
 * PSR-4-correct file so class_exists()/autoloading resolves it. Previously this
 * class lived inside ContactDuplicate.php (which matched neither class it
 * contained), so any instantiation threw a fatal "class not found" Error.
 */
class ContactMergeHistory extends Model
{
    use HasFactory;

    protected $table = 'contact_merge_history';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'primary_contact_id', 'merged_contact_id',
        'merge_details', 'merged_by', 'merged_at', 'reason',
    ];

    protected $casts = [
        'merge_details' => 'array',
        'merged_at' => 'datetime',
    ];

    public function primaryContact()
    {
        return $this->belongsTo(CoreContact::class, 'primary_contact_id');
    }

    public function mergedContact()
    {
        return $this->belongsTo(CoreContact::class, 'merged_contact_id');
    }
}
