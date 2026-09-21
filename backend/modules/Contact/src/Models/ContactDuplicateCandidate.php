<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Issue #115 (BLOCKER): moved out of the misnamed ContactDuplicate.php into a
 * PSR-4-correct file so class_exists()/autoloading resolves it. The old file
 * ContactDuplicate.php matched neither class it contained.
 */
class ContactDuplicateCandidate extends Model
{
    use HasFactory;

    protected $table = 'contact_duplicate_candidates';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'contact_id_1', 'contact_id_2', 'match_type',
        'match_score', 'match_details', 'status', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'match_score' => 'decimal:2',
        'match_details' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function contact1()
    {
        return $this->belongsTo(CoreContact::class, 'contact_id_1');
    }

    public function contact2()
    {
        return $this->belongsTo(CoreContact::class, 'contact_id_2');
    }
}
