<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add indexes to journal_entries table for better query performance
        Schema::table('journal_entries', function (Blueprint $table) {
            // Index for date range queries
            if (! $this->indexExists('journal_entries', 'journal_entries_entry_date_status_index')) {
                $table->index(['entry_date', 'status'], 'journal_entries_entry_date_status_index');
            }

            // Index for status filtering
            if (! $this->indexExists('journal_entries', 'journal_entries_status_index')) {
                $table->index('status');
            }
        });

        // Add indexes to journal_entry_lines table
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            // Composite index for account-based queries
            if (! $this->indexExists('journal_entry_lines', 'journal_entry_lines_account_journal_index')) {
                $table->index(['account_id', 'journal_entry_id'], 'journal_entry_lines_account_journal_index');
            }

            // Index for dimension-based filtering (JSONB) - only if column exists
            if (Schema::hasColumn('journal_entry_lines', 'dimensions') &&
                ! $this->indexExists('journal_entry_lines', 'journal_entry_lines_dimensions_index')) {
                $table->index('dimensions', 'journal_entry_lines_dimensions_index', 'gin');
            }
        });

        // Add indexes to daily_ledger_summaries table (if exists)
        if (Schema::hasTable('daily_ledger_summaries')) {
            Schema::table('daily_ledger_summaries', function (Blueprint $table) {
                // Composite index for date and account queries
                if (! $this->indexExists('daily_ledger_summaries', 'daily_ledger_summaries_date_account_index')) {
                    $table->index(['summary_date', 'account_id'], 'daily_ledger_summaries_date_account_index');
                }

                // Index for dimension-based filtering - only if column exists
                if (Schema::hasColumn('daily_ledger_summaries', 'dimensions') &&
                    ! $this->indexExists('daily_ledger_summaries', 'daily_ledger_summaries_dimensions_index')) {
                    $table->index('dimensions', 'daily_ledger_summaries_dimensions_index', 'gin');
                }
            });
        }

        // Add indexes to chart_of_accounts table
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            // Index for type-based queries
            if (Schema::hasColumn('chart_of_accounts', 'type') &&
                Schema::hasColumn('chart_of_accounts', 'is_active') &&
                ! $this->indexExists('chart_of_accounts', 'chart_of_accounts_type_active_index')) {
                $table->index(['type', 'is_active'], 'chart_of_accounts_type_active_index');
            }

            // Index for code range queries
            if (Schema::hasColumn('chart_of_accounts', 'code') &&
                ! $this->indexExists('chart_of_accounts', 'chart_of_accounts_code_index')) {
                $table->index('code');
            }
        });

        // Add indexes to invoices table (if exists)
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                // Index for date and status queries
                if (! $this->indexExists('invoices', 'invoices_date_status_index')) {
                    $table->index(['invoice_date', 'status'], 'invoices_date_status_index');
                }

                // Index for customer queries
                if (! $this->indexExists('invoices', 'invoices_customer_date_index')) {
                    $table->index(['customer_id', 'invoice_date'], 'invoices_customer_date_index');
                }
            });
        }

        // Add indexes to invoice_items table (if exists)
        if (Schema::hasTable('invoice_items')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                // Index for product-based queries
                if (! $this->indexExists('invoice_items', 'invoice_items_product_invoice_index')) {
                    $table->index(['product_id', 'invoice_id'], 'invoice_items_product_invoice_index');
                }
            });
        }

        // Add indexes to products table (if exists)
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                // Index for category queries - only if column exists
                if (Schema::hasColumn('products', 'category_id') &&
                    Schema::hasColumn('products', 'is_active') &&
                    ! $this->indexExists('products', 'products_category_active_index')) {
                    $table->index(['category_id', 'is_active'], 'products_category_active_index');
                }

                // Index for stock level queries
                if (Schema::hasColumn('products', 'stock_quantity') && Schema::hasColumn('products', 'reorder_level')) {
                    if (! $this->indexExists('products', 'products_stock_reorder_index')) {
                        $table->index(['stock_quantity', 'reorder_level'], 'products_stock_reorder_index');
                    }
                }
            });
        }

        // Add indexes to stock_movements table (if exists)
        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                // Index for product and date queries (using created_at since movement_date doesn't exist)
                if (Schema::hasColumn('stock_movements', 'created_at') &&
                    ! $this->indexExists('stock_movements', 'stock_movements_product_date_index')) {
                    $table->index(['product_id', 'created_at'], 'stock_movements_product_date_index');
                }

                // Index for type-based queries
                if (Schema::hasColumn('stock_movements', 'type') &&
                    ! $this->indexExists('stock_movements', 'stock_movements_type_date_index')) {
                    $table->index(['type', 'created_at'], 'stock_movements_type_date_index');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex('journal_entries_entry_date_status_index');
            $table->dropIndex('journal_entries_status_index');
        });

        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropIndex('journal_entry_lines_account_journal_index');
            if (Schema::hasColumn('journal_entry_lines', 'dimensions')) {
                $table->dropIndex('journal_entry_lines_dimensions_index');
            }
        });

        Schema::table('daily_ledger_summaries', function (Blueprint $table) {
            $table->dropIndex('daily_ledger_summaries_date_account_index');
            if (Schema::hasColumn('daily_ledger_summaries', 'dimensions')) {
                $table->dropIndex('daily_ledger_summaries_dimensions_index');
            }
        });

        Schema::table('chart_of_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('chart_of_accounts', 'type')) {
                $table->dropIndex('chart_of_accounts_type_active_index');
            }
            if (Schema::hasColumn('chart_of_accounts', 'code')) {
                $table->dropIndex('chart_of_accounts_code_index');
            }
        });

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropIndex('invoices_date_status_index');
                $table->dropIndex('invoices_customer_date_index');
            });
        }

        if (Schema::hasTable('invoice_items')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->dropIndex('invoice_items_product_invoice_index');
            });
        }

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (Schema::hasColumn('products', 'category_id')) {
                    $table->dropIndex('products_category_active_index');
                }
                if (Schema::hasColumn('products', 'stock_quantity')) {
                    $table->dropIndex('products_stock_reorder_index');
                }
            });
        }

        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->dropIndex('stock_movements_product_date_index');
                $table->dropIndex('stock_movements_type_date_index');
            });
        }
    }

    /**
     * Check if an index exists (Laravel 11 compatible)
     */
    protected function indexExists(string $table, string $index): bool
    {
        try {
            $connection = Schema::getConnection();
            $indexes = $connection->select(
                'SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $index]
            );

            return count($indexes) > 0;
        } catch (\Exception $e) {
            // If we can't check, assume it doesn't exist and let the migration handle duplicates
            return false;
        }
    }
};
