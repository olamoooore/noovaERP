<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
