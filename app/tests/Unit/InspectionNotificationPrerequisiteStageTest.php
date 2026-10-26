<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\InspectionNotificationPrerequisiteStage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InspectionNotificationPrerequisiteStageTest extends TestCase
{
    private static function data(): array
    {
        return ['project_id' => '11111111-1111-4111-8111-111111111111',
            'document_id' => '22222222-2222-4222-8222-222222222222', 'document_number' => 'ITP-RECORDED',
            'revision_id' => '33333333-3333-4333-8333-333333333333', 'revision' => 'B',
            'stage_id' => '44444444-4444-4444-8444-444444444444', 'stage_sequence' => 7, 'point' => 'H',
            'description_en' => 'Recorded stage', 'description_fa' => 'مرحلهٔ محفوظ'];
    }

    private static function notice(array $data): object
    {
        return (object) ['state' => 'ISSUED', 'stage_id' => self::data()['stage_id'], 'project_id' => self::data()['project_id'], 'issued_snapshot' => json_encode($data)];
    }

    public function test_projection_is_exact_allowlist_not_parent_snapshot_or_future_private_data(): void
    {
        $data = self::data() + ['notes_en' => 'PRIVATE', 'future_column' => ['secret'], 'issuer_name' => 'UNNEEDED'];
        $context = InspectionNotificationPrerequisiteStage::fromNotice(self::notice($data));
        unset($data['project_id'],$data['notes_en'],$data['future_column'],$data['issuer_name']);
        $this->assertSame($data, $context);
        $this->assertCount(9, $context);
    }

    public function test_legacy_project_metadata_absence_does_not_invent_a_live_project_or_revision(): void
    {
        $data = self::data();
        unset($data['project_id']);
        $data['description_en'] = null;
        $this->assertSame($data, InspectionNotificationPrerequisiteStage::fromNotice(self::notice($data)));
    }

    public static function invalidFields(): iterable
    {
        foreach (['document_id', 'revision_id', 'stage_id', 'document_number', 'revision', 'stage_sequence', 'point', 'description_en'] as $key) {
            yield 'missing '.$key => [$key, null, true];
        }
        foreach (['document_id' => ['not-uuid', [], 1], 'revision_id' => ['not-uuid', false], 'stage_id' => ['55555555-5555-4555-8555-555555555555'], 'document_number' => [[], "\u{00A0}"], 'revision' => [[], ''], 'stage_sequence' => ['7', 0, -1], 'point' => [['H'], 'PASS'], 'description_fa' => [[], 1], 'project_id' => ['66666666-6666-4666-8666-666666666666', null]] as $key => $values) {
            foreach ($values as $i => $value) {
                yield $key.' malformed '.$i => [$key, $value, false];
            }
        }
    }

    #[DataProvider('invalidFields')]
    public function test_inconsistent_or_malformed_recorded_context_is_unknown(string $key, mixed $value, bool $remove): void
    {
        $data = self::data();
        if ($remove) {
            unset($data[$key]);
        } else {
            $data[$key] = $value;
        }
        $this->assertNull(InspectionNotificationPrerequisiteStage::fromNotice(self::notice($data)));
    }

    public function test_bad_json_nonobjects_draft_and_empty_descriptions_are_unknown_without_exception_or_source_lookup(): void
    {
        foreach (['{broken', 'null', '[]', '42', '"text"'] as $json) {
            $notice = self::notice(self::data());
            $notice->issued_snapshot = $json;
            $this->assertNull(InspectionNotificationPrerequisiteStage::fromNotice($notice));
        }
        $notice = self::notice(self::data());
        $notice->state = 'DRAFT';
        $this->assertNull(InspectionNotificationPrerequisiteStage::fromNotice($notice));
        $notice = self::notice(self::data());
        $notice->issued_snapshot = [];
        $this->assertNull(InspectionNotificationPrerequisiteStage::fromNotice($notice));
        $data = self::data();
        $data['description_en'] = null;
        $data['description_fa'] = ' ';
        $this->assertNull(InspectionNotificationPrerequisiteStage::fromNotice(self::notice($data)));
    }
}
