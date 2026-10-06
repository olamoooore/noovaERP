<?php

namespace Modules\AI\Services;

use Illuminate\Support\Facades\Log;
use Modules\AI\Functions\LoyaltyFunctions;
use Modules\AI\Functions\OrderFunctions;
use Modules\AI\Functions\ProductFunctions;
use Modules\AI\Models\AIConversation;

/**
 * Grounding context for the chat pipeline.
 *
 * Runs before every LLM call (ProcessAIMessage) and appends a grounded
 * context block to the system prompt:
 *
 *  1. Catalog matches (storefront conversations) — live product rows for
 *     whatever the shopper is asking about, so recommendations are real.
 *  2. Shopper account snapshot (storefront conversations) — the shopper's
 *     recent orders and loyalty balance, or a guest order lookup when the
 *     message contains an order number.
 *
 * Account data is fetched through the same Function classes the LLM's
 * tool-calls use (shared-service-first: one source of truth, no parallel
 * query logic).
 */
class AssistantContextService
{
    public function build(AIConversation $conversation, string $message): string
    {
        $blocks = [];

        if ($conversation->channel === 'storefront') {
            try {
                if ($catalog = $this->catalogContext($message)) {
                    $blocks[] = $catalog;
                }
            } catch (\Throwable $e) {
                Log::warning('AssistantContextService: catalog context failed', ['error' => $e->getMessage()]);
            }

            try {
                if ($account = $this->shopperAccountContext($conversation, $message)) {
                    $blocks[] = $account;
                }
            } catch (\Throwable $e) {
                Log::warning('AssistantContextService: shopper context failed', ['error' => $e->getMessage()]);
            }
        }

        return $blocks === [] ? '' : "\n\n".implode("\n\n", $blocks);
    }

    /** Words ignored when extracting search terms. */
    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'what', 'where', 'when', 'who', 'why',
        'how', 'does', 'did', 'your', 'you', 'are', 'can', 'could', 'would',
        'should', 'will', 'have', 'has', 'had', 'from', 'about', 'this',
        'that', 'these', 'those', 'there', 'their', 'them', 'they', 'please',
        'hello', 'hi', 'hey', 'just', 'some', 'any', 'all', 'not', 'but',
        'was', 'were', 'been', 'being', 'get', 'got', 'into', 'out', 'our',
    ];

    /**
     * Meaningful lowercase terms in a text (stopwords and short words
     * removed, de-duplicated) — used to search the catalog.
     *
     * @return string[]
     */
    private function keywords(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/i', mb_strtolower($text)) ?: [];
        $terms = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $terms[$word] = true;
        }

        return array_keys($terms);
    }

    /**
     * Live catalog matches for the shopper's message. Uses the same
     * ProductFunctions search the tool-calling path uses, so the assistant
     * can recommend products without a function round-trip.
     */
    private function catalogContext(string $message): ?string
    {
        $terms = array_slice($this->keywords($message), 0, 3);
        if ($terms === []) {
            return null;
        }

        $products = [];
        foreach ($terms as $term) {
            if (mb_strlen($term) < 3) {
                continue;
            }

            $result = ProductFunctions::search(['query' => $term, 'limit' => 3]);

            foreach (($result['products'] ?? []) as $product) {
                $products[$product['id']] = $product;
            }

            if (count($products) >= 5) {
                break;
            }
        }

        if ($products === []) {
            return null;
        }

        $lines = collect(array_slice($products, 0, 5))->map(function (array $product): string {
            $price = number_format((float) ($product['price'] ?? 0), 2);
            $stock = ($product['in_stock'] ?? false) ? 'in stock' : 'out of stock';
            $category = $product['category'] ? " — category: {$product['category']}" : '';

            return "- [{$product['name']}](/product/{$product['id']}) (SKU: {$product['sku']}) — \${$price} — {$stock}{$category}";
        })->implode("\n");

        return "CATALOG MATCHES (live from the product database for this question — recommend only these products; never invent products):\n{$lines}";
    }

    /**
     * Verified account data for the shopper. Authenticated storefront
     * customers get their recent orders + loyalty balance; guests get an
     * order-number lookup when they mention one (standard e-commerce
     * guest tracking — keyed strictly on the exact order number).
     */
    private function shopperAccountContext(AIConversation $conversation, string $message): ?string
    {
        $customerId = $conversation->customer_id;

        if ($customerId) {
            $lines = [];

            $orders = OrderFunctions::listCustomerOrders(['limit' => 3], (int) $customerId);
            foreach (($orders['orders'] ?? []) as $order) {
                $lines[] = "- Order {$order['order_number']} — status: {$order['status']} — total: {$order['total']} — date: {$order['date']}";
            }

            $loyalty = LoyaltyFunctions::getPoints([], (int) $customerId);
            if (($loyalty['success'] ?? false) && ($loyalty['enrolled'] ?? false)) {
                $points = $loyalty['points_balance'] ?? null;
                $tier = $loyalty['tier']['name'] ?? null;
                if ($points !== null) {
                    $lines[] = "- Loyalty: {$points} points".($tier ? ", tier: {$tier}" : '');
                }
            }

            if ($lines === []) {
                return null;
            }

            return "SHOPPER ACCOUNT (verified data for this customer — do not invent anything beyond it):\n".implode("\n", $lines);
        }

        if (preg_match('/\b((?:ORD|SO|INV)[- ]?[A-Z0-9]{3,12})\b/i', $message, $match)) {
            $orderNumber = strtoupper(str_replace(' ', '', $match[1]));
            $result = OrderFunctions::checkStatus(['order_number' => $orderNumber]);

            if ($result['found'] ?? false) {
                $items = $result['items_count'] ?? null;

                return "GUEST ORDER LOOKUP (verified — this exact order number exists):\n".
                    "- Order {$result['order_number']} — status: {$result['status']} — total: {$result['total']} — date: {$result['date']}".
                    ($items !== null ? " — items: {$items}" : '');
            }
        }

        return null;
    }
}
