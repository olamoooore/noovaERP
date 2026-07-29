<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Sales Orders: block delete for confirmed/allocated/fulfilled/invoiced
        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_so_non_draft_deletion()
            RETURNS TRIGGER AS $$
            BEGIN
                IF OLD.status IN ('confirmed', 'allocated', 'fulfilled', 'invoiced') THEN
                    RAISE EXCEPTION 'Cannot delete % sales order %. Cancel it first, or reset to draft if cancelled.', OLD.status, OLD.sales_order_number;
                END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;
        ");

        DB::unprepared("
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'prevent_so_deletion') THEN
                    CREATE TRIGGER prevent_so_deletion
                    BEFORE DELETE ON sales_orders
                    FOR EACH ROW EXECUTE FUNCTION prevent_so_non_draft_deletion();
                END IF;
            END $$;
        ");

        // Delivery Orders: block delete for confirmed/done
        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_do_non_draft_deletion()
            RETURNS TRIGGER AS $$
            BEGIN
                IF OLD.status IN ('confirmed', 'done') THEN
                    RAISE EXCEPTION 'Cannot delete % delivery order %. Cancel it first, or reverse it if completed.', OLD.status, OLD.delivery_order_number;
                END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;
        ");

        DB::unprepared("
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'prevent_do_deletion') THEN
                    CREATE TRIGGER prevent_do_deletion
                    BEFORE DELETE ON delivery_orders
                    FOR EACH ROW EXECUTE FUNCTION prevent_do_non_draft_deletion();
                END IF;
            END $$;
        ");

        // Quotations: block delete for confirmed
        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_quotation_non_draft_deletion()
            RETURNS TRIGGER AS $$
            BEGIN
                IF OLD.status = 'confirmed' THEN
                    RAISE EXCEPTION 'Cannot delete confirmed quotation %. Cancel it first.', OLD.quotation_number;
                END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;
        ");

        DB::unprepared("
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'prevent_quotation_deletion') THEN
                    CREATE TRIGGER prevent_quotation_deletion
                    BEFORE DELETE ON quotations
                    FOR EACH ROW EXECUTE FUNCTION prevent_quotation_non_draft_deletion();
                END IF;
            END $$;
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS prevent_so_deletion ON sales_orders');
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_so_non_draft_deletion()');
        DB::unprepared('DROP TRIGGER IF EXISTS prevent_do_deletion ON delivery_orders');
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_do_non_draft_deletion()');
        DB::unprepared('DROP TRIGGER IF EXISTS prevent_quotation_deletion ON quotations');
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_quotation_non_draft_deletion()');
    }
};
