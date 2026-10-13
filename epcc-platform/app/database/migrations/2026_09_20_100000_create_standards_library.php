<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The library of codes and standards.
 *
 * Until now every standard in the system was a free-text string: the ITP row
 * said 'ASME B31.3 §323', the piping class said something else, and a grep
 * across the codebase turns up 'ASME B31.3', 'ASME B31.3.' and 'API RP' as
 * three different things. You cannot answer "which inspections cite B31.3?"
 * against prose, and on the day the project moves to a new edition there is no
 * list of what has to be re-checked.
 *
 * Two tiers, which is the user's ruling:
 *
 *   standards          the catalogue - global, shared by every project, owned
 *                      by whoever runs the platform. Nobody's tenant data.
 *   project_standards  the applicability list - THIS project uses THESE codes,
 *                      to THIS edition. Tenant data, under RLS.
 *
 * The split exists because the two answer different questions. "Does ASME
 * B31.3 exist and what is it called in Persian?" is the same answer for
 * everyone. "Is this project contractually bound to the 2020 or the 2022
 * edition?" is specific, contractual, and must not change because a platform
 * administrator tidied the catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------ 1
        // The catalogue. No project_id, no company_id, no RLS - the same
        // reasoning as itp_templates and the reference packs: this is
        // reference data, and the protection it needs is "no tenant can write
        // it", which is enforced by nobody exposing a tenant-facing writer.
        Schema::create('standards', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // The issuing body, kept separate from the designation so that
            // "all API standards" is an indexed query rather than a LIKE.
            $table->string('body', 32);

            // The designation WITHOUT the body: 'B31.3', '5L', '10204'.
            // Storing 'ASME B31.3' here would make the body column a lie that
            // drifts, and would put the same standard in twice the moment
            // somebody types 'ASME  B31.3' with two spaces.
            $table->string('designation', 64);

            // The normalised join of body+designation, used for lookup and
            // uniqueness: 'ASMEB313'. Case, spaces, dots and dashes removed,
            // because those are exactly what humans vary.
            $table->string('normalised', 96);

            $table->string('title_en', 512);
            $table->string('title_fa', 512)->nullable();

            // The edition the CATALOGUE currently describes. The edition a
            // project is bound to lives on project_standards - see below.
            $table->string('edition', 32)->nullable();

            // Coarse grouping for the picker: PIPING, WELDING, NDE, MATERIAL,
            // ELECTRICAL, INSTRUMENT, CIVIL, HSE, QUALITY, GENERAL.
            $table->string('category', 32)->default('GENERAL');

            // Withdrawn standards stay in the catalogue. A 1998 inspection
            // record cites what it cites, and deleting the row would orphan
            // history; the flag keeps it out of the picker instead.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique('normalised', 'standards_normalised_unique');
            $table->index(['category', 'body']);
        });

        DB::statement("COMMENT ON TABLE standards IS
            'Global catalogue of codes and standards. Reference data: no RLS, no tenant may write it. Project applicability lives in project_standards.'");

        DB::statement("COMMENT ON COLUMN standards.normalised IS
            'body+designation, uppercased with spaces/dots/dashes stripped. The uniqueness key: stops ASME B31.3 and ASME B 31.3 becoming two standards.'");

        DB::statement("COMMENT ON COLUMN standards.edition IS
            'Edition the CATALOGUE describes. A project pins its own in project_standards.edition - updating this must never move a project to a new edition.'");

        // ------------------------------------------------------------------ 2
        // Applicability: which standards this project actually works to.
        Schema::create('project_standards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();

            // restrictOnDelete: a catalogue entry a live project has adopted
            // is not deletable. Deactivate it instead.
            $table->foreignUuid('standard_id')->constrained('standards')->restrictOnDelete();

            /*
             * The edition THIS project is bound to.
             *
             * This is the whole reason the second tier exists. The catalogue
             * says what the current edition is; the contract says what this
             * project signed up to. When somebody updates the catalogue from
             * 2020 to 2022, a project that pinned 2020 must not silently move
             * - its acceptance criteria are contractual, and an inspection
             * signed last month was signed against the old text.
             *
             * Null means "whatever the catalogue says", which is honest for
             * the many standards nobody has taken a contractual position on.
             */
            $table->string('edition', 32)->nullable();

            // Why this project uses it, and any project-specific deviation
            // ("as modified by client spec SP-001 clause 4").
            $table->string('remarks_en', 512)->nullable();
            $table->string('remarks_fa', 512)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['project_id', 'standard_id'], 'project_standards_unique');
            $table->index(['project_id', 'is_active']);
        });

        DB::statement("COMMENT ON COLUMN project_standards.edition IS
            'The edition this project is contractually bound to. Null = follow the catalogue. Updating standards.edition must NOT change this.'");

        // House RLS pattern, copied verbatim from the engineering baseline.
        DB::statement('ALTER TABLE project_standards ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE project_standards FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY project_standards_tenant ON project_standards
            USING (project_id IN (
                SELECT project_id FROM project_company
                WHERE company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
            ))
            WITH CHECK (project_id IN (
                SELECT project_id FROM project_company
                WHERE company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
            ))");

        // ------------------------------------------------------------------ 3
        // Citations: an ITP row cites a standard.
        //
        // Polymorphic on purpose. The user's instruction is that the library
        // is usable "in various parts" of the system - piping classes, welding
        // procedures, datasheets - and a table per consumer would mean a new
        // table, a new model and a new RLS policy each time, all expressing
        // the identical relationship "this thing cites that standard at that
        // clause". Same call the ITP module already made for stage documents.
        Schema::create('standard_citations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUuid('standard_id')->constrained('standards')->restrictOnDelete();

            // 'ITP_STAGE' today; 'PIPING_CLASS', 'WELD_PROCEDURE' later.
            $table->string('citable_type', 32);
            $table->uuid('citable_id');

            // The clause, as printed: '§323.4', 'Table 302.3.5', 'Annex F'.
            // Free text because clause numbering is the standard's business,
            // not ours, and nobody can enumerate it.
            $table->string('clause', 64)->nullable();

            $table->integer('sequence')->default(10);
            $table->timestamps();

            // One citation of one standard per host. Two different clauses of
            // the same standard on one ITP row is a single citation with the
            // clauses written together - otherwise the grid grows a column per
            // clause and becomes unreadable.
            $table->unique(['citable_type', 'citable_id', 'standard_id'], 'standard_citations_unique');
            $table->index(['citable_type', 'citable_id', 'sequence']);
            $table->index(['project_id', 'standard_id']);
        });

        DB::statement("COMMENT ON TABLE standard_citations IS
            'Polymorphic: any project object cites a catalogue standard at a clause. citable_type is ITP_STAGE today; piping classes and weld procedures reuse it.'");

        DB::statement('ALTER TABLE standard_citations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE standard_citations FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY standard_citations_tenant ON standard_citations
            USING (project_id IN (
                SELECT project_id FROM project_company
                WHERE company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
            ))
            WITH CHECK (project_id IN (
                SELECT project_id FROM project_company
                WHERE company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
            ))");

        // ------------------------------------------------------------------ 4
        // itp_stages.reference stays. The user asked for the free text to be
        // kept as the escape hatch: on a real project there is always a
        // standard that is not in the library yet, and refusing the row until
        // an administrator adds it would stop work.
        DB::statement("COMMENT ON COLUMN itp_stages.reference IS
            'Free-text codes and standards - the escape hatch for anything not yet in the library. Structured citations live in standard_citations.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('standard_citations');
        Schema::dropIfExists('project_standards');
        Schema::dropIfExists('standards');
    }
};
