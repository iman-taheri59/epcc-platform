<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Employer's blanket visibility applies to CONTRACTOR letters only.
 *
 * WHY
 *
 * The original policy let the project owner read every ISSUED transmittal in
 * their project. That was right when there was one stream: all formal
 * correspondence on an EPCC project is ultimately the owner's business.
 *
 * Vendor transmittals break that assumption. A vendor letter is a supplier
 * writing to the contractor that bought the equipment - commercial
 * correspondence inside the EPC's supply chain, to which the Employer is not a
 * party. Leaving the blanket clause in place would mean the separation the
 * user asked for held on screen (each register filters by kind) but NOT in the
 * data: an owner reading through the API or a future report would see supplier
 * letters listed among submissions made to them.
 *
 * So the employer clause is narrowed to `kind = 'CONTRACTOR'`. The Employer
 * still sees a vendor letter when the contractor deliberately NAMES them as a
 * recipient - which is the explicit act of sharing it - and still sees every
 * vendor document that gets rolled up into a contractor transmittal, because
 * that is a submission to them.
 *
 * Visibility can always be widened later. A leak cannot be undone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP POLICY IF EXISTS transmittals_tenant_isolation ON transmittals');

        DB::statement(<<<'SQL'
            CREATE POLICY transmittals_tenant_isolation ON transmittals
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR transmittals.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR (
                        -- A DRAFT is unsent correspondence. Until it is issued the
                        -- sender may still add or remove recipients, revise the item
                        -- list, or abandon it entirely, so nobody outside the sending
                        -- company may see it - not the addressees, not the employer.
                        transmittals.issued_at IS NOT NULL
                        AND (
                            EXISTS (
                                SELECT 1 FROM transmittal_recipients tr
                                WHERE tr.transmittal_id = transmittals.id
                                  AND tr.recipient_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                            )
                            OR (
                                -- Employer catch-all, CONTRACTOR stream only.
                                -- A vendor letter is supply-chain correspondence
                                -- the owner is not a party to; they see it only
                                -- if named as a recipient, by the clause above.
                                transmittals.kind = 'CONTRACTOR'
                                AND EXISTS (
                                    SELECT 1 FROM projects p
                                    WHERE p.id = transmittals.project_id
                                      AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                                )
                            )
                        )
                    )
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = transmittals.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS transmittals_tenant_isolation ON transmittals');

        DB::statement(<<<'SQL'
            CREATE POLICY transmittals_tenant_isolation ON transmittals
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR transmittals.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR (
                        transmittals.issued_at IS NOT NULL
                        AND (
                            EXISTS (
                                SELECT 1 FROM transmittal_recipients tr
                                WHERE tr.transmittal_id = transmittals.id
                                  AND tr.recipient_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                            )
                            OR EXISTS (
                                SELECT 1 FROM projects p
                                WHERE p.id = transmittals.project_id
                                  AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                            )
                        )
                    )
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = transmittals.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);
    }
};
