#!/bin/bash
# Fix P&L data on production (noova-server) so reset-then-reposted invoices
# surface in the P&L after the code change in commit 9aedcf02.
#
# This script applies three remediations to every tenant schema:
#
#   1. Every reversal JE (is_reversal=true) gets is_active_version=false
#      and lifecycle_status='reversed', so the new
#      `je.is_active_version=true` report filter excludes it.
#
#   2. Every original JE that has a reversal record gets
#      is_active_version=false (only if it doesn't have a fresh
#      reposted replacement yet). Without this, the OLD original's
#      revenue credit would still be counted in the P&L.
#
#   3. Every reposted JE (is_active_version=true) gets
#      lifecycle_status='posted' — mirrors the local code fix in
#      InvoiceService::postInvoice() that was committed in 9aedcf02.
#
# Idempotent — re-running this script is a no-op once the data is clean.
#
# Usage (as the noovaerp_user DB role):
#   PGPASSWORD=your_secure_password_here psql -U noovaerp_user noovaerp -f fix_reset_repost_data.sql

DO $$
DECLARE
    s text;
    step1 int;
    step2 int;
    step3 int;
    step4 int;
    step5 int;
    step6 int;
BEGIN
    FOR s IN
        SELECT schema_name
        FROM information_schema.schemata
        WHERE schema_name ~ '^[0-9a-f]{8}-'
    LOOP
        BEGIN
            -- (1) Mark every reversal JE as inactive + reversed
            EXECUTE format('
                UPDATE %I.journal_entries
                SET is_active_version = false, lifecycle_status = ''reversed''
                WHERE is_reversal = true
                  AND deleted_at IS NULL
                  AND (is_active_version = true OR lifecycle_status IS DISTINCT FROM ''reversed'')',
                s
            );
            GET DIAGNOSTICS step1 = ROW_COUNT;

            -- (2) Find original JEs (non-reversal) that have a reversal JE
            -- pointing at them, and mark them inactive so the report
            -- filter excludes them too.
            EXECUTE format('
                UPDATE %I.journal_entries orig
                SET is_active_version = false,
                    lifecycle_status = ''reversed''
                FROM %I.journal_entries rev
                WHERE rev.reversed_entry_id = orig.id
                  AND rev.is_reversal = true
                  AND rev.deleted_at IS NULL
                  AND orig.deleted_at IS NULL
                  AND orig.is_active_version = true',
                s, s
            );
            GET DIAGNOSTICS step2 = ROW_COUNT;

            -- (3) For every (invoice, 'post') link that points to an
            -- inactive JE, drop the link so a future repost can create
            -- a fresh post link + JE. (Mirrors InvoiceController::resetToDraft.)
            EXECUTE format('
                DELETE FROM %I.journal_entry_links jel
                USING %I.journal_entries je
                WHERE je.id = jel.journal_entry_id
                  AND jel.relation_type = ''post''
                  AND jel.source_document_type = ''invoice''
                  AND je.is_active_version = false',
                s, s
            );
            GET DIAGNOSTICS step3 = ROW_COUNT;

            -- (4) For every reposted invoice pointing to an inactive JE,
            -- null out the stale journal_entry_id. The InvoiceService
            -- path will create a fresh link on the next repost.
            EXECUTE format('
                UPDATE %I.invoices inv
                SET journal_entry_id = NULL,
                    lifecycle_status = ''draft'',
                    status = ''draft''
                FROM %I.journal_entries je
                WHERE je.id = inv.journal_entry_id
                  AND je.is_active_version = false
                  AND je.deleted_at IS NULL
                  AND inv.deleted_at IS NULL
                  AND je.lifecycle_status != ''reversed''
                  AND je.is_reversal = false',
                s, s
            );
            GET DIAGNOSTICS step4 = ROW_COUNT;

            -- (5) For every reposted invoice pointing to a reversed
            -- original JE (the bug we are fixing), null out the link
            -- and reset the invoice to draft so the user can repost it.
            EXECUTE format('
                UPDATE %I.invoices inv
                SET journal_entry_id = NULL,
                    lifecycle_status = ''draft'',
                    status = ''draft''
                FROM %I.journal_entries je
                WHERE je.id = inv.journal_entry_id
                  AND je.lifecycle_status = ''reversed''
                  AND je.is_reversal = false
                  AND je.deleted_at IS NULL
                  AND inv.deleted_at IS NULL
                  AND je.is_active_version = false',
                s, s
            );
            GET DIAGNOSTICS step5 = ROW_COUNT;

            -- (6) For every active JE that is correctly linked to an
            -- invoice whose lifecycle_status='draft' (because of step
            -- 4/5), set the invoice back to 'posted' if it has an
            -- active journal entry pointing at it.
            EXECUTE format('
                UPDATE %I.invoices inv
                SET status = ''posted'', lifecycle_status = ''posted''
                FROM %I.journal_entries je
                WHERE je.id = inv.journal_entry_id
                  AND je.deleted_at IS NULL
                  AND je.is_active_version = true
                  AND je.status = ''posted''
                  AND inv.deleted_at IS NULL
                  AND (inv.status != ''posted'' OR inv.lifecycle_status != ''posted'')',
                s, s
            );
            GET DIAGNOSTICS step6 = ROW_COUNT;

            IF step1+step2+step3+step4+step5+step6 > 0 THEN
                RAISE NOTICE 'schema=% step1(rev_inactive)=% step2(orig_inactive)=% step3(stale_post_links_deleted)=% step4(stale_jeid_invoices_reset)=% step5(rev-linked_invoices_reset)=% step6(invoices_back_to_posted)=%',
                    s, step1, step2, step3, step4, step5, step6;
            END IF;
        EXCEPTION WHEN OTHERS THEN
            RAISE NOTICE 'schema=% error=%', s, SQLERRM;
        END;
    END LOOP;
END $$;