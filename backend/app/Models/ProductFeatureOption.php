<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductFeatureOption extends Model
{
    protected $fillable = ['product_feature_id', 'value'];

    public function feature()
    {
        return $this->belongsTo(ProductFeature::class, 'product_feature_id');
    }
}
