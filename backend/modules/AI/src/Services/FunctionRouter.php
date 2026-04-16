<?php

namespace Modules\AI\Services;

use Illuminate\Support\Facades\Log;
use Modules\AI\Functions\CustomerFunctions;
use Modules\AI\Functions\OrderFunctions;
use Modules\AI\Functions\LoyaltyFunctions;
use Modules\AI\Functions\ProductFunctions;
use Modules\AI\Functions\InvoiceFunctions;
use Modules\AI\Functions\TicketFunctions;
use Modules\AI\Functions\InventoryFunctions;
use Modules\AI\Functions\AccountingFunctions;
use Modules\AI\Functions\CopilotFunctions;

/**
 * Function Router Service
 * 
 * Routes AI function calls to appropriate ERP functions
 */
class FunctionRouter
{
    /**
     * Execute function by name
     * 
     * @param string $functionName Function name
     * @param array $arguments Function arguments
     * @param int|null $customerId Customer ID (for context)
     * @param int|null $conversationId Conversation ID (for context)
     * @return array Execution result
     */
    public function execute(string $functionName, array $arguments, ?int $customerId = null, ?int $conversationId = null): array
    {
        Log::info('Executing AI function', [
            'function' => $functionName,
            'arguments' => $arguments,
        ]);
        
        try {
            $result = match($functionName) {
                'search_customers' => CustomerFunctions::search($arguments),
                'check_order_status' => OrderFunctions::checkStatus($arguments),
                'list_customer_orders' => OrderFunctions::listCustomerOrders($arguments, $customerId),
                'get_loyalty_points' => LoyaltyFunctions::getPoints($arguments, $customerId),
                'get_loyalty_rewards' => LoyaltyFunctions::getRewards($arguments, $customerId),
                // New Loyalty Functions (with customer_id in args)
                'check_loyalty_points' => LoyaltyFunctions::checkLoyaltyPoints(array_merge($arguments, ['customer_id' => $customerId])),
                'get_loyalty_program_details' => LoyaltyFunctions::getLoyaltyProgramDetails(array_merge($arguments, ['customer_id' => $customerId])),
                'get_customer_tier_info' => LoyaltyFunctions::getCustomerTierInfo(array_merge($arguments, ['customer_id' => $customerId])),
                'list_available_rewards' => LoyaltyFunctions::listAvailableRewards(array_merge($arguments, ['customer_id' => $customerId])),
                'check_redemption_history' => LoyaltyFunctions::checkRedemptionHistory(array_merge($arguments, ['customer_id' => $customerId])),
                'calculate_points_for_purchase' => LoyaltyFunctions::calculatePointsForPurchase(array_merge($arguments, ['customer_id' => $customerId])),
                'get_tier_benefits' => LoyaltyFunctions::getTierBenefits(array_merge($arguments, ['customer_id' => $customerId])),
                'check_next_tier_progress' => LoyaltyFunctions::checkNextTierProgress(array_merge($arguments, ['customer_id' => $customerId])),
                'list_loyalty_programs' => LoyaltyFunctions::listLoyaltyPrograms($arguments),
                // Other Functions
                'search_products' => ProductFunctions::search($arguments),
                'get_product_details' => ProductFunctions::getDetails($arguments),
                'check_product_availability' => ProductFunctions::checkAvailability($arguments),
                'list_product_categories' => ProductFunctions::listCategories($arguments),
                'check_inventory_stock' => InventoryFunctions::checkStock($arguments),
                'list_low_stock_items' => InventoryFunctions::listLowStock($arguments),
                'list_out_of_stock_items' => InventoryFunctions::listOutOfStock($arguments),
                'list_warehouses' => InventoryFunctions::listWarehouses($arguments),
                'list_recent_stock_movements' => InventoryFunctions::listRecentStockMovements($arguments),
                'get_invoice' => InvoiceFunctions::getDetails($arguments),
                'get_outstanding_invoices' => AccountingFunctions::getOutstandingInvoices($arguments),
                'get_invoice_details' => AccountingFunctions::getInvoiceDetails($arguments),
                'get_overdue_invoices' => AccountingFunctions::getOverdueInvoices($arguments),
                'get_recent_payments' => AccountingFunctions::getRecentPayments($arguments),
                'get_customer_balance' => AccountingFunctions::getCustomerBalance($arguments),
                'get_revenue_summary' => AccountingFunctions::getRevenueSummary($arguments),
                'get_journal_entries' => AccountingFunctions::getJournalEntries($arguments),
                'get_supplier_invoices' => AccountingFunctions::getSupplierInvoices($arguments),
                'get_financial_report' => AccountingFunctions::getFinancialReport($arguments),
                'get_customer_ledger' => AccountingFunctions::getCustomerLedger($arguments),
                'get_supplier_ledger' => AccountingFunctions::getSupplierLedger($arguments),
                'create_ticket' => TicketFunctions::create($arguments, $customerId, $conversationId),
                'transfer_to_human' => $this->transferToHuman($arguments),
                // Copilot Functions
                'detect_close_anomalies' => CopilotFunctions::detectCloseAnomalies($arguments),
                'prioritize_collections' => CopilotFunctions::prioritizeCollections($arguments),
                'suggest_ap_coding' => CopilotFunctions::suggestAPCoding($arguments),
                default => [
                    'success' => false,
                    'error' => 'Unknown function: ' . $functionName,
                ],
            };
            
            Log::info('Function executed', ['function' => $functionName]);
            return $result;
            
        } catch (\Exception $e) {
            Log::error('Function execution failed', [
                'function' => $functionName,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => 'Execution failed: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Transfer to human agent
     */
    protected function transferToHuman(array $arguments): array
    {
        $reason = $arguments['reason'] ?? 'Customer requested assistance';
        
        return [
            'success' => true,
            'message' => 'Transferring to human agent. Someone will assist you shortly.',
            'reason' => $reason,
        ];
    }
}
