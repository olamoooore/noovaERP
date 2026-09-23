<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;

/**
 * A free-form label attachable to any document (invoice, quotation, sales
 * order, delivery order, customer …). Managed by TagService.
 */
class Tag extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'color',
    ];

    /** Palette used to colour chips; kept small and readable. */
    public const COLORS = [
        'slate', 'blue', 'emerald', 'amber', 'violet', 'rose', 'cyan', 'orange', 'teal', 'pink',
    ];

    public static function slugFor(string $name): string
    {
        return Str::slug(trim($name));
    }

    public function invoices(): MorphToMany
    {
        return $this->morphedByMany(Invoice::class, 'taggable');
    }

    public function quotations(): MorphToMany
    {
        return $this->morphedByMany(Quotation::class, 'taggable');
    }

    public function customers(): MorphToMany
    {
        return $this->morphedByMany(Customer::class, 'taggable');
    }
}
