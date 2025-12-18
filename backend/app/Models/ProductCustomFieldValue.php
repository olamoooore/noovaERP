<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductCustomFieldValue extends Model
{
    protected $fillable = ['product_id', 'product_custom_field_id', 'value'];

    public function field()
    {
        return $this->belongsTo(ProductCustomField::class, 'product_custom_field_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
