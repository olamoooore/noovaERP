<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

class PosTenantSchemaForeignKeyTest extends TenantTestCase
{
    /** @test */
    public function pos_orders_session_fk_targets_the_current_tenant_schema()
    {
        $this->assertTenantSchemaReady(['pos_orders', 'pos_sessions']);

        $constraint = DB::selectOne(
            <<<'SQL'
            SELECT confns.nspname AS referenced_schema
            FROM pg_constraint con
            JOIN pg_class cl ON cl.oid = con.conrelid
            JOIN pg_namespace ns ON ns.oid = cl.relnamespace
            JOIN pg_class confcl ON confcl.oid = con.confrelid
            JOIN pg_namespace confns ON confns.oid = confcl.relnamespace
            WHERE con.conname = 'pos_orders_session_id_foreign'
              AND ns.nspname = current_schema()
              AND cl.relname = 'pos_orders'
            LIMIT 1
            SQL
        );

        $this->assertNotNull($constraint, 'pos_orders_session_id_foreign constraint not found');

        $currentSchema = DB::selectOne('select current_schema() as schema')->schema;

        $this->assertSame(
            $currentSchema,
            $constraint->referenced_schema,
            'pos_orders.session_id must reference the tenant schema pos_sessions table'
        );
    }

    /** @test */
    public function pos_orders_terminal_fk_targets_the_current_tenant_schema()
    {
        $this->assertTenantSchemaReady(['pos_orders', 'pos_terminals']);

        $constraint = DB::selectOne(
            <<<'SQL'
            SELECT confns.nspname AS referenced_schema
            FROM pg_constraint con
            JOIN pg_class cl ON cl.oid = con.conrelid
            JOIN pg_namespace ns ON ns.oid = cl.relnamespace
            JOIN pg_class confcl ON confcl.oid = con.confrelid
            JOIN pg_namespace confns ON confns.oid = confcl.relnamespace
            WHERE con.conname = 'pos_orders_terminal_id_foreign'
              AND ns.nspname = current_schema()
              AND cl.relname = 'pos_orders'
            LIMIT 1
            SQL
        );

        $this->assertNotNull($constraint, 'pos_orders_terminal_id_foreign constraint not found');

        $currentSchema = DB::selectOne('select current_schema() as schema')->schema;

        $this->assertSame(
            $currentSchema,
            $constraint->referenced_schema,
            'pos_orders.terminal_id must reference the tenant schema pos_terminals table'
        );
    }
}
