<?php

declare(strict_types=1);

use App\Support\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ADR-033: independent PO nomination; no default authors or approval grants. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $t): void {
            $t->unsignedBigInteger('purchase_order_sequence')->default(0);
        });
        // Each company can read its own historical requests; union maxima without an RLS bypass.
        $tenant = app(TenantContext::class);
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $tenant->runAs($companyId, function (): void {
                $maxima = DB::table('purchase_orders')->selectRaw('project_id, max(sequence) AS maximum')->groupBy('project_id')->get();
                foreach ($maxima as $maximum) {
                    DB::table('projects')->where('id', $maximum->project_id)
                        ->where('purchase_order_sequence', '<', $maximum->maximum)
                        ->update(['purchase_order_sequence' => $maximum->maximum]);
                }
            });
        }
        Schema::create('po_author_policies', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->string('actor_name');
            $t->integer('version');
            $t->jsonb('selectors');
            $t->text('reason');
            $t->timestampTz('created_at');
            $t->unique(['project_id', 'version']);
            $t->unique(['id', 'project_id']);
        });
        DB::statement("ALTER TABLE po_author_policies ADD CONSTRAINT po_author_policy_shape CHECK
            (version > 0 AND jsonb_typeof(selectors)='array' AND jsonb_array_length(selectors)<=100 AND length(btrim(reason))>0)");
        DB::statement('ALTER TABLE po_author_policies ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE po_author_policies FORCE ROW LEVEL SECURITY');
        $visible = "project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
            AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id=po_author_policies.project_id
            AND pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid)";
        DB::statement("CREATE POLICY po_author_policy_read ON po_author_policies FOR SELECT USING ({$visible})");
        DB::statement("CREATE POLICY po_author_policy_insert ON po_author_policies FOR INSERT WITH CHECK ({$visible}
            AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
            AND epcc_project_is_writable(project_id)
            AND EXISTS (SELECT 1 FROM projects p WHERE p.id=po_author_policies.project_id AND p.owner_company_id=po_author_policies.company_id))");
        // No UPDATE/DELETE policy: a replacement is a new version, not an edit.
        Schema::table('purchase_orders', function (Blueprint $t): void {
            $t->uuid('author_policy_id')->nullable();
            $t->foreignUuid('drafted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('discipline', 32)->nullable();
            $t->foreign(['author_policy_id', 'project_id'], 'po_entry_author_policy_fk')
                ->references(['id', 'project_id'])->on('po_author_policies')->restrictOnDelete();
        });
        Schema::table('purchase_order_items', function (Blueprint $t): void {
            $t->string('source_quantity', 32)->nullable();
        });
        DB::statement("ALTER TABLE purchase_order_items ADD CONSTRAINT po_source_quantity_matches CHECK (
            source_quantity IS NULL OR CASE WHEN source_quantity ~ '^[0-9]{1,15}(\\.[0-9]{1,3})?$'
            THEN source_quantity::numeric > 0 AND source_quantity::numeric=quantity ELSE false END)");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_guard_po_author_policy() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE current_version integer; person record;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:' || NEW.project_id::text, 0));
    SELECT COALESCE(max(version),0) INTO current_version FROM po_author_policies WHERE project_id=NEW.project_id;
    IF NEW.version<>current_version+1 THEN
        RAISE EXCEPTION 'PO_AUTHOR_POLICY_STALE' USING ERRCODE='23514';
    END IF;
    SELECT u.name INTO person FROM users u JOIN project_memberships pm ON pm.user_id=u.id
        WHERE u.id=NEW.created_by AND u.company_id=NEW.company_id AND u.is_active AND pm.project_id=NEW.project_id
        AND EXISTS (SELECT 1 FROM roles r WHERE jsonb_exists(pm.role_codes,r.code)
            AND (jsonb_exists(r.capabilities,'project.configure') OR jsonb_exists(r.capabilities,'project.*') OR jsonb_exists(r.capabilities,'*')));
    IF NOT FOUND THEN RAISE EXCEPTION 'PO_AUTHOR_CONFIG_FORBIDDEN' USING ERRCODE='42501'; END IF;
    NEW.actor_name:=person.name;
    NEW.created_at:=CURRENT_TIMESTAMP;
    RETURN NEW;
END $$;
CREATE TRIGGER po_author_policy_guard BEFORE INSERT ON po_author_policies
FOR EACH ROW EXECUTE FUNCTION epcc_guard_po_author_policy();

CREATE OR REPLACE FUNCTION epcc_po_author_policy(pid uuid, aid uuid, cid uuid, disc text)
RETURNS uuid LANGUAGE sql STABLE AS $$
    WITH latest AS (SELECT id,selectors FROM po_author_policies WHERE project_id=pid ORDER BY version DESC LIMIT 1)
    SELECT latest.id FROM latest, users u JOIN project_memberships pm ON pm.user_id=u.id
    WHERE u.id=aid AND u.company_id=cid AND u.is_active AND pm.project_id=pid
        AND cid=NULLIF(current_setting('epcc.company_id',true),'')::uuid
        AND pid=NULLIF(current_setting('epcc.project_id',true),'')::uuid
        AND epcc_project_is_writable(pid)
        AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id=pid AND pc.company_id=cid)
        AND (disc IS NULL OR pm.discipline_scope IS NULL OR jsonb_exists(pm.discipline_scope,disc))
        AND EXISTS (SELECT 1 FROM companies c WHERE c.id=cid AND c.tenant_type IN ('OWN','EPC','SUB'))
        AND EXISTS (SELECT 1 FROM jsonb_array_elements(latest.selectors) selection
            WHERE selection->>'selector'='user:' || u.id::text
            OR EXISTS (SELECT 1 FROM roles r WHERE jsonb_exists(pm.role_codes,r.code)
                AND selection->>'selector'='role:' || cid::text || ':' || r.code))
    LIMIT 1
$$;

CREATE OR REPLACE FUNCTION epcc_guard_po_entry_basis() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='UPDATE' AND OLD.author_policy_id IS NOT NULL AND
        (NEW.author_policy_id IS DISTINCT FROM OLD.author_policy_id
         OR NEW.discipline IS DISTINCT FROM OLD.discipline
         OR NEW.drafted_by IS DISTINCT FROM OLD.drafted_by
         OR NEW.company_id IS DISTINCT FROM OLD.company_id
         OR NEW.project_id IS DISTINCT FROM OLD.project_id) THEN
        RAISE EXCEPTION 'PO_ENTRY_BASIS_IMMUTABLE' USING ERRCODE='23514';
    END IF;
    IF NEW.author_policy_id IS NOT NULL AND (TG_OP='INSERT' OR OLD.author_policy_id IS NULL) THEN
        PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:' || NEW.project_id::text,0));
        IF NEW.author_policy_id IS DISTINCT FROM epcc_po_author_policy(NEW.project_id,NEW.drafted_by,NEW.company_id,NEW.discipline) THEN
            RAISE EXCEPTION 'PO_AUTHOR_NOT_NOMINATED' USING ERRCODE='42501';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER po_entry_basis_guard BEFORE INSERT OR UPDATE ON purchase_orders
FOR EACH ROW EXECUTE FUNCTION epcc_guard_po_entry_basis();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('PO author-policy history is forward-only; do not erase authorizing evidence.');
    }
};
