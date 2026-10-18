<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** External report registration must not require storing its signed file. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE pipe_schedule_external_signatures DROP CONSTRAINT pipe_ext_signatures_fields');
        DB::statement('ALTER TABLE pipe_schedule_external_signatures ALTER COLUMN file_sha256 DROP NOT NULL');
        DB::statement('ALTER TABLE pipe_schedule_external_signatures ALTER COLUMN document_revision DROP NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE pipe_schedule_external_signatures ADD CONSTRAINT pipe_ext_signatures_fields CHECK (
                length(btrim(signer_name)) >= 2 AND length(btrim(signer_capacity)) >= 2
                AND length(btrim(document_number)) >= 2
                AND (document_revision IS NULL OR length(btrim(document_revision)) >= 1)
                AND (file_sha256 IS NULL OR file_sha256 ~ '^[0-9a-fA-F]{64}$')
            )
        SQL);
    }

    public function down(): void
    {
        // Refuse lossy rollback when metadata-only entries have been recorded.
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                IF EXISTS (SELECT 1 FROM pipe_schedule_external_signatures
                    WHERE file_sha256 IS NULL OR document_revision IS NULL) THEN
                    RAISE EXCEPTION 'Cannot restore mandatory file digest/revision: metadata-only reports exist';
                END IF;
            END $$
        SQL);
        DB::statement('ALTER TABLE pipe_schedule_external_signatures DROP CONSTRAINT pipe_ext_signatures_fields');
        DB::statement('ALTER TABLE pipe_schedule_external_signatures ALTER COLUMN file_sha256 SET NOT NULL');
        DB::statement('ALTER TABLE pipe_schedule_external_signatures ALTER COLUMN document_revision SET NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE pipe_schedule_external_signatures ADD CONSTRAINT pipe_ext_signatures_fields CHECK (
                length(btrim(signer_name)) >= 2 AND length(btrim(signer_capacity)) >= 2
                AND length(btrim(document_number)) >= 2 AND length(btrim(document_revision)) >= 1
                AND file_sha256 ~ '^[0-9a-fA-F]{64}$'
            )
        SQL);
    }
};
