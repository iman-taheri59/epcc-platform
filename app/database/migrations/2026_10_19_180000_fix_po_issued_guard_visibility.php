<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Preserve ADR-037 issued-content integrity even when buyer journal evidence is hidden by RLS. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- Integrity-only lookup. No generic journal read delegation and no definer rights.
-- Only a trigger can consume the three fields; snapshots/policies/source evidence never leave the journal.
CREATE OR REPLACE FUNCTION epcc_po_issued_guard_basis(oid uuid) RETURNS jsonb LANGUAGE plpgsql AS $$
DECLARE prior_company text; prior_project text; po purchase_orders%ROWTYPE; basis jsonb;
BEGIN
    prior_company:=current_setting('epcc.company_id',true);
    prior_project:=current_setting('epcc.project_id',true);
    IF pg_trigger_depth()=0 THEN
        RAISE EXCEPTION 'PO_EXEC_GUARD_TRIGGER_ONLY' USING ERRCODE='55000';
    END IF;
    -- Resolve exactly one parent under ORIGINAL caller RLS, before any context switch.
    SELECT * INTO po FROM purchase_orders WHERE id=oid;
    IF NOT FOUND THEN RETURN NULL; END IF;
    PERFORM set_config('epcc.company_id',po.company_id::text,true);
    PERFORM set_config('epcc.project_id',po.project_id::text,true);
    SELECT jsonb_build_object('content_hash',e.content_hash,'actor_id',e.actor_id,'created_at',e.created_at)
        INTO basis FROM po_execution_decisions e
        WHERE e.purchase_order_id=po.id AND e.project_id=po.project_id
          AND e.buyer_company_id=po.company_id AND e.purpose='issue';
    PERFORM set_config('epcc.company_id',COALESCE(prior_company,''),true);
    PERFORM set_config('epcc.project_id',COALESCE(prior_project,''),true);
    RETURN basis;
EXCEPTION WHEN OTHERS THEN
    PERFORM set_config('epcc.company_id',COALESCE(prior_company,''),true);
    PERFORM set_config('epcc.project_id',COALESCE(prior_project,''),true);
    RAISE;
END $$;
CREATE OR REPLACE FUNCTION epcc_guard_po_execution_header() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE basis jsonb;
BEGIN
    IF TG_OP='INSERT' THEN
        IF NEW.author_policy_id IS NOT NULL AND NEW.state<>'DRAFT' THEN
            RAISE EXCEPTION 'PO_EXEC_ISSUE_DECISION_REQUIRED' USING ERRCODE='23514';
        END IF;
        RETURN NEW;
    END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||NEW.project_id::text,0));
    basis:=epcc_po_issued_guard_basis(NEW.id);
    IF basis IS NOT NULL THEN
        IF epcc_po_execution_digest(epcc_po_execution_content(to_jsonb(NEW))) IS DISTINCT FROM (basis->>'content_hash') THEN
            RAISE EXCEPTION 'PO_EXEC_ISSUED_CONTENT_FROZEN' USING ERRCODE='23514';
        END IF;
        IF NEW.state NOT IN ('ISSUED','PARTIALLY_RECEIVED','RECEIVED') THEN
            RAISE EXCEPTION 'PO_EXEC_CANCELLATION_PENDING' USING ERRCODE='23514';
        END IF;
        IF OLD.state='DRAFT' AND NEW.state='ISSUED' THEN NEW.issued_by:=(basis->>'actor_id')::uuid; NEW.issued_at:=(basis->>'created_at')::timestamptz;
        ELSIF (NEW.issued_by,NEW.issued_at) IS DISTINCT FROM (OLD.issued_by,OLD.issued_at) OR NEW.state='DRAFT' THEN
            RAISE EXCEPTION 'PO_EXEC_ISSUE_BASIS_IMMUTABLE' USING ERRCODE='23514';
        END IF;
    ELSIF NEW.author_policy_id IS NOT NULL AND NEW.state IN ('ISSUED','PARTIALLY_RECEIVED','RECEIVED') AND (OLD.state NOT IN ('ISSUED','PARTIALLY_RECEIVED','RECEIVED') OR OLD.author_policy_id IS NULL) THEN
        RAISE EXCEPTION 'PO_EXEC_ISSUE_DECISION_REQUIRED' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION epcc_guard_po_execution_lines() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE oid uuid; pid uuid;
BEGIN
    IF TG_OP<>'INSERT' THEN
        SELECT p.project_id INTO pid FROM purchase_orders p WHERE p.id=OLD.purchase_order_id;
        PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||pid::text,0));
        IF epcc_po_issued_guard_basis(OLD.purchase_order_id) IS NOT NULL THEN
            RAISE EXCEPTION 'PO_EXEC_ISSUED_CONTENT_FROZEN' USING ERRCODE='23514';
        END IF;
    END IF;
    IF TG_OP<>'DELETE' THEN
        SELECT p.project_id INTO pid FROM purchase_orders p WHERE p.id=NEW.purchase_order_id;
        PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||pid::text,0));
        IF epcc_po_issued_guard_basis(NEW.purchase_order_id) IS NOT NULL THEN
            RAISE EXCEPTION 'PO_EXEC_ISSUED_CONTENT_FROZEN' USING ERRCODE='23514';
        END IF;
        RETURN NEW;
    END IF;
    RETURN OLD;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only security correction: do not restore an RLS-dependent issued-content bypass.');
    }
};
