<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductFeatureValue extends Model
{
    protected $fillable = ['product_id', 'product_feature_id', 'value'];

    public function feature()
    {
        return $this->belongsTo(ProductFeature::class, 'product_feature_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
