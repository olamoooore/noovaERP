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
        // Currently returning a default warehouse as multi-warehouse support is not yet fully implemented
        return response()->json([
            'data' => [
                [
                    'id' => 1,
                    'name' => 'Main Warehouse',
                    'code' => 'MAIN',
                    'address' => 'Main Branch',
                    'is_active' => true
                ]
            ]
        ]);
    }
}
