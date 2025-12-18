<?php

namespace Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PosWarehouseController extends Controller
{
    /**
     * Get list of warehouses available for POS
     */
    public function index()
    {
        return response()->json([
            'data' => \App\Models\Warehouse::where('is_active', true)->get()
        ]);
    }
}
