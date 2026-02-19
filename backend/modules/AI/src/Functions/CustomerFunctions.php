<?php

namespace Modules\AI\Functions;

use App\Models\Customer;
use Illuminate\Support\Facades\Log;

/**
 * Customer Functions
 * 
 * AI-callable functions for customer queries
 */
class CustomerFunctions
{
    /**
     * Search for customers by name, email, or phone
     * 
     * @param array $args Arguments with search query
     * @return array Customer search results
     */
    public static function search(array $args): array
    {
        try {
            $query = $args['query'] ?? '';
            
            if (empty($query)) {
                return [
                    'success' => false,
                    'message' => 'Search query is required',
                ];
            }
            
            // Search customers by name, email, or phone
            $customers = Customer::where(function($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                  ->orWhere('email', 'LIKE', "%{$query}%")
                  ->orWhere('phone', 'LIKE', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'name', 'email', 'phone'])
            ->toArray();
            
            if (empty($customers)) {
                return [
                    'success' => true,
                    'found' => false,
                    'message' => "No customers found matching '{$query}'",
                    'customers' => [],
                ];
            }
            
            return [
                'success' => true,
                'found' => true,
                'customers' => $customers,
                'count' => count($customers),
            ];
            
        } catch (\Exception $e) {
            Log::error('CustomerFunctions::search failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);
            
            return [
                'success' => false,
                'error' => 'Failed to search customers',
            ];
        }
    }
}
