<?php

namespace Modules\OCRBookkeeping\Services;

use App\Models\Customer;

class CustomerMappingService
{
    public function getBestMatch(?string $customerName): ?array
    {
        if (! $customerName) {
            return null;
        }

        $customers = Customer::select('id', 'name', 'email', 'phone')
            ->whereNull('deleted_at')
            ->get();

        $bestMatch = null;
        $bestScore = 0;

        foreach ($customers as $customer) {
            $score = $this->calculateSimilarity($customerName, $customer->name);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $customer;
            }
        }

        if (! $bestMatch || $bestScore < 0.7) {
            return null;
        }

        return [
            'customer_id' => $bestMatch->id,
            'name' => $bestMatch->name,
            'confidence' => round($bestScore, 2),
            'source' => 'fuzzy_search',
            'email' => $bestMatch->email,
            'phone' => $bestMatch->phone,
        ];
    }

    private function calculateSimilarity(string $input, string $target): float
    {
        $input = strtolower(trim($input));
        $target = strtolower(trim($target));

        if ($input === $target) {
            return 1.0;
        }

        similar_text($input, $target, $percent);

        return $percent / 100;
    }
}
