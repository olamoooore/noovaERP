<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class LoyaltyRuleVersion extends Model
{
    protected $fillable = [
        'rule_name',
        'rule_version',
        'rule_description',
        'rule_logic',
        'rule_parameters',
        'is_active',
        'effective_from',
        'effective_until'
    ];

    protected $casts = [
        'rule_logic' => 'array',
        'rule_parameters' => 'array',
        'is_active' => 'boolean',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime'
    ];

    /**
     * Scope for active rules
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for rules effective at a given time
     */
    public function scopeEffectiveAt($query, Carbon $timestamp = null)
    {
        $timestamp = $timestamp ?? Carbon::now();
        
        return $query->where('effective_from', '<=', $timestamp)
                    ->where(function($q) use ($timestamp) {
                        $q->whereNull('effective_until')
                          ->orWhere('effective_until', '>', $timestamp);
                    });
    }

    /**
     * Scope for rules by name
     */
    public function scopeForRule($query, string $ruleName)
    {
        return $query->where('rule_name', $ruleName);
    }

    /**
     * Get the current active version of a rule
     */
    public static function getCurrentVersion(string $ruleName): ?self
    {
        return static::forRule($ruleName)
                    ->active()
                    ->effectiveAt()
                    ->orderBy('effective_from', 'desc')
                    ->first();
    }
}