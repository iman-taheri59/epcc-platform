<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PmsClassRevisionSnapshot;
use App\Models\PipingLine;
use Tests\TestCase;

final class PmsClassRevisionSnapshotKeyTest extends TestCase
{
    public function test_snapshot_uuid_is_not_coerced_to_an_integer_for_eager_loading(): void
    {
        $id = '0199a001-1234-7000-8000-000000000001';
        $snapshot = new PmsClassRevisionSnapshot();
        $snapshot->setRawAttributes(['id' => $id]);

        $this->assertFalse($snapshot->getIncrementing());
        $this->assertSame('string', $snapshot->getKeyType());
        $this->assertSame($id, $snapshot->getKey());
        $this->assertSame('string', (new PipingLine())->pmsSnapshot()->getRelated()->getKeyType());
    }
}
