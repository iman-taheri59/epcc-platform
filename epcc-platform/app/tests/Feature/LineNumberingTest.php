<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Numbering\Exceptions\AmbiguousPatternException;
use App\Support\Numbering\Exceptions\InvalidPatternException;
use App\Support\Numbering\Exceptions\UnknownTokenValueException;
use App\Support\Numbering\NumberEngine;
use App\Support\Numbering\NumberPattern;
use App\Support\Numbering\PatternToken;
use App\Support\Numbering\TokenKind;
use Tests\TestCase;

/**
 * ADR-006 §4 - the line number engine.
 *
 * Pure unit tests: no database, no seeding. The engine is deliberately free of
 * I/O so the numbering rules can be exercised exhaustively and fast.
 *
 * The centre of gravity is PARSING, because composing is easy and parsing is
 * what the product actually needs: lines arrive already numbered from the P&ID
 * or the 3D model, and the useful question is whether every part of that number
 * is something the project has registered.
 */
final class LineNumberingTest extends TestCase
{
    private NumberEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new NumberEngine();
    }

    /** The format from ADR-006 §4, which is the user's own example. */
    private function pattern(): NumberPattern
    {
        return new NumberPattern([
            new PatternToken('size', TokenKind::ATTRIBUTE, 'nominal_size'),
            new PatternToken('service', TokenKind::ATTRIBUTE, 'service_code'),
            new PatternToken('class', TokenKind::ATTRIBUTE, 'piping_class'),
            new PatternToken('subsystem', TokenKind::PARENT, 'subsystem.code'),
            new PatternToken('sequence', TokenKind::COUNTER, width: 3),
            new PatternToken('coating', TokenKind::ATTRIBUTE, 'coating_code', optional: true),
        ]);
    }

    /** @return array<string, list<string>> */
    private function vocab(): array
    {
        return [
            'size' => ['8', '6', '12', '2 1/2'],
            'service' => ['P', 'CW', 'HS'],
            'class' => ['A1', 'A1B', 'B2'],
            // The real fixture code. Three separators INSIDE one token value.
            'subsystem' => ['PH-14-SS-2201', 'PH-14-SS-3301'],
            'coating' => ['CT1', 'CT2'],
        ];
    }

    // ----------------------------------------------------------- compose --

    public function test_it_composes_a_number_from_attributes(): void
    {
        $number = $this->engine->compose($this->pattern(), [
            'size' => '8', 'service' => 'P', 'class' => 'A1',
            'subsystem' => 'PH-14-SS-2201', 'sequence' => 1, 'coating' => 'CT2',
        ], $this->vocab());

        $this->assertSame('8-P-A1-PH-14-SS-2201-001-CT2', $number);
    }

    public function test_the_counter_is_padded_for_display_only(): void
    {
        // ADR-006 §11.1: the integer is stored, the padding is presentation.
        $number = $this->engine->compose($this->pattern(), [
            'size' => '8', 'service' => 'P', 'class' => 'A1',
            'subsystem' => 'PH-14-SS-2201', 'sequence' => 7,
        ], $this->vocab());

        $this->assertStringContainsString('-007', $number);
    }

    public function test_an_optional_token_is_simply_left_out(): void
    {
        $number = $this->engine->compose($this->pattern(), [
            'size' => '6', 'service' => 'CW', 'class' => 'B2',
            'subsystem' => 'PH-14-SS-3301', 'sequence' => 12,
        ], $this->vocab());

        $this->assertSame('6-CW-B2-PH-14-SS-3301-012', $number);
        $this->assertStringEndsNotWith('-', $number, 'no dangling separator');
    }

    public function test_composing_refuses_an_unregistered_value(): void
    {
        // Catching it here matters: otherwise we mint a number containing a
        // piping class that does not exist, and it fails later as an
        // unparseable number of our own making.
        $this->expectException(UnknownTokenValueException::class);

        $this->engine->compose($this->pattern(), [
            'size' => '8', 'service' => 'P', 'class' => 'NOPE',
            'subsystem' => 'PH-14-SS-2201', 'sequence' => 1,
        ], $this->vocab());
    }

    public function test_composing_refuses_a_missing_mandatory_value(): void
    {
        $this->expectException(UnknownTokenValueException::class);

        $this->engine->compose($this->pattern(), [
            'size' => '8', 'service' => null, 'class' => 'A1',
            'subsystem' => 'PH-14-SS-2201', 'sequence' => 1,
        ], $this->vocab());
    }

    // ------------------------------------------------------------- parse --

    public function test_naive_splitting_would_be_wrong_and_the_engine_is_not(): void
    {
        // THE DEFECT THIS ENGINE EXISTS TO AVOID.
        // A subsystem code is PH-14-SS-2201, so the separator appears three
        // times inside one token value. explode() returns nine fragments for a
        // six-token pattern; anything counting fields is already wrong.
        $number = '8-P-A1-PH-14-SS-2201-001-CT2';

        $this->assertCount(9, explode('-', $number), 'the naive split really does shatter');

        $parsed = $this->engine->parse($this->pattern(), $number, $this->vocab());

        $this->assertTrue($parsed->isValid());
        $this->assertSame('PH-14-SS-2201', $parsed->token('subsystem'), 'the multi-dash value is one token');
        $this->assertSame(1, $parsed->sequence());
    }

    public function test_it_round_trips(): void
    {
        $values = [
            'size' => '2 1/2', 'service' => 'HS', 'class' => 'A1B',
            'subsystem' => 'PH-14-SS-2201', 'sequence' => 42, 'coating' => 'CT1',
        ];

        $number = $this->engine->compose($this->pattern(), $values, $this->vocab());
        $parsed = $this->engine->parse($this->pattern(), $number, $this->vocab());

        $this->assertTrue($parsed->isValid());
        $this->assertSame('2 1/2', $parsed->token('size'), 'a size with a space and a slash survives');
        $this->assertSame('A1B', $parsed->token('class'));
        $this->assertSame(42, $parsed->sequence());
    }

    public function test_a_longer_value_wins_over_a_shorter_one_sharing_its_prefix(): void
    {
        // PCRE alternation is first-match, not longest-match. Mostly
        // backtracking hides that: with 'A1|A1B' the engine tries A1, fails on
        // the next separator, and retries A1B - so the naive order still works.
        //
        // It stops working when the token AFTER the ambiguous one can absorb
        // the difference. Here two subsystem codes share a prefix and the
        // following token is a free-form reference, so BOTH splits match and
        // the regex keeps the first one it finds. Ordering alternatives
        // longest-first is what makes the correct split the one that wins.
        //
        // Verified against raw PCRE first: unsorted, 'PH-14|PH-14-SS' against
        // PH-14-SS-2201 yields sub=PH-14 - silently the wrong subsystem.
        $pattern = new NumberPattern([
            new PatternToken('subsystem', TokenKind::PARENT),
            new PatternToken('tail', TokenKind::ATTRIBUTE),
        ]);

        $parsed = $this->engine->parse($pattern, 'PH-14-SS-2201', [
            'subsystem' => ['PH-14', 'PH-14-SS'],
        ]);

        $this->assertSame('PH-14-SS', $parsed->token('subsystem'), 'the longer registered code must win');
        $this->assertSame('2201', $parsed->token('tail'));
    }

    public function test_backtracking_still_resolves_the_simple_prefix_case(): void
    {
        // The companion to the test above: where the next token is a fixed
        // vocabulary or a separator-anchored counter, either ordering works.
        // Kept so the two situations are visibly different rather than one
        // being mistaken for the other.
        $parsed = $this->engine->parse(
            $this->pattern(),
            '8-P-A1B-PH-14-SS-2201-001',
            $this->vocab(),
        );

        $this->assertTrue($parsed->isValid());
        $this->assertSame('A1B', $parsed->token('class'));
    }

    public function test_an_unregistered_value_is_named_not_just_rejected(): void
    {
        // The diagnosis is the product. "Invalid line number" would send
        // someone hunting for a formatting error that is not there.
        $parsed = $this->engine->parse(
            $this->pattern(),
            '8-P-ZZ-PH-14-SS-2201-001-CT2',
            $this->vocab(),
        );

        $this->assertTrue($parsed->matched, 'the shape is fine');
        $this->assertFalse($parsed->isValid());
        $this->assertSame(['class' => 'ZZ'], $parsed->unknownValues);
        $this->assertStringContainsString("class='ZZ'", $parsed->explain()['en']);
    }

    public function test_an_unregistered_multi_dash_value_is_still_recovered(): void
    {
        // The hard case. Relaxing every vocabulary at once does NOT work: a
        // relaxed token is [^-]+ which cannot span PH-99-SS-9999, so the parse
        // fails and the diagnosis is lost. The engine relaxes one token at a
        // time, keeping the others anchored.
        $parsed = $this->engine->parse(
            $this->pattern(),
            '8-P-A1-PH-99-SS-9999-001-CT2',
            $this->vocab(),
        );

        $this->assertTrue($parsed->matched);
        $this->assertSame(['subsystem' => 'PH-99-SS-9999'], $parsed->unknownValues);
    }

    public function test_genuine_rubbish_does_not_match(): void
    {
        $parsed = $this->engine->parse($this->pattern(), 'not a line number', $this->vocab());

        $this->assertFalse($parsed->matched);
        $this->assertFalse($parsed->isValid());
        $this->assertStringContainsString('{size}-{service}', $parsed->explain()['en']);
    }

    public function test_a_number_with_too_few_parts_does_not_match(): void
    {
        $parsed = $this->engine->parse($this->pattern(), '8-P-A1', $this->vocab());

        $this->assertFalse($parsed->matched);
    }

    public function test_checked_and_unchecked_are_not_the_same_answer(): void
    {
        // isValid() means "nothing was found wrong", which is weaker than
        // "everything was verified". An import preview that conflated them
        // would overstate what it knows.
        $number = '8-P-A1-PH-14-SS-2201-001-CT2';

        $checked = $this->engine->parse($this->pattern(), $number, $this->vocab());
        $this->assertTrue($checked->isFullyChecked());

        // Same number, but only the subsystem vocabulary supplied - the others
        // cannot be confirmed.
        $partial = $this->engine->parse($this->pattern(), $number, [
            'subsystem' => ['PH-14-SS-2201'],
        ]);

        $this->assertTrue($partial->matched);
        $this->assertFalse($partial->isFullyChecked());
        $this->assertContains('class', $partial->unconfirmed);
        $this->assertStringContainsString('Not checked', $partial->explain()['en']);
    }

    public function test_the_sequence_is_returned_as_an_integer(): void
    {
        $parsed = $this->engine->parse(
            $this->pattern(),
            '8-P-A1-PH-14-SS-2201-007-CT2',
            $this->vocab(),
        );

        // "007" must not leak out as a string: it would be compared against
        // integers and sorted as text.
        $this->assertSame(7, $parsed->sequence());
    }

    // ----------------------------------------------------------- pattern --

    public function test_a_pattern_needs_tokens_and_a_separator(): void
    {
        $this->expectException(InvalidPatternException::class);
        new NumberPattern([]);
    }

    public function test_duplicate_token_names_are_refused(): void
    {
        $this->expectException(InvalidPatternException::class);
        new NumberPattern([
            new PatternToken('size', TokenKind::ATTRIBUTE),
            new PatternToken('size', TokenKind::ATTRIBUTE),
        ]);
    }

    public function test_two_counters_are_refused(): void
    {
        // Two counters side by side have no unique split, and no meaning
        // either. Refused at construction so an unusable scheme cannot be
        // saved and then fail mysteriously during an import weeks later.
        $this->expectException(AmbiguousPatternException::class);
        new NumberPattern([
            new PatternToken('a', TokenKind::COUNTER, width: 3),
            new PatternToken('b', TokenKind::COUNTER, width: 3),
        ]);
    }

    public function test_an_optional_counter_is_refused(): void
    {
        // "Sometimes numbered" is a numbering_policy decision (ADR-006 §10.1),
        // not something to hide inside a format string.
        $this->expectException(InvalidPatternException::class);
        new PatternToken('sequence', TokenKind::COUNTER, optional: true);
    }

    public function test_an_unknown_token_kind_is_refused(): void
    {
        $this->expectException(InvalidPatternException::class);
        new PatternToken('x', 'telepathy');
    }

    public function test_a_pattern_survives_a_json_round_trip(): void
    {
        // It is stored as jsonb, so this is the real lifecycle.
        $original = $this->pattern();
        $json = json_encode($original->toArray());
        $restored = NumberPattern::fromArray(json_decode((string) $json, true));

        $this->assertSame($original->sketch(), $restored->sketch());
        $this->assertSame($original->tokenNames(), $restored->tokenNames());

        $number = '8-P-A1-PH-14-SS-2201-001-CT2';
        $this->assertTrue($this->engine->parse($restored, $number, $this->vocab())->isValid());
    }

    public function test_a_literal_token_is_carried_through(): void
    {
        $pattern = new NumberPattern([
            new PatternToken('line', TokenKind::PARENT),
            new PatternToken('marker', TokenKind::LITERAL, values: ['SUP']),
            new PatternToken('sequence', TokenKind::COUNTER, width: 2),
        ]);

        $number = $this->engine->compose($pattern, ['line' => 'L-1', 'sequence' => 3], ['line' => ['L-1']]);
        $this->assertSame('L-1-SUP-03', $number);

        $parsed = $this->engine->parse($pattern, $number, ['line' => ['L-1']]);
        $this->assertTrue($parsed->isValid());
        $this->assertSame(3, $parsed->sequence());
    }

    public function test_a_different_separator_works(): void
    {
        // §11.4: the separator is configurable per project.
        $pattern = new NumberPattern([
            new PatternToken('size', TokenKind::ATTRIBUTE),
            new PatternToken('service', TokenKind::ATTRIBUTE),
            new PatternToken('sequence', TokenKind::COUNTER, width: 3),
        ], separator: '/');

        $number = $this->engine->compose($pattern, ['size' => '8', 'service' => 'P', 'sequence' => 5], []);
        $this->assertSame('8/P/005', $number);
        $this->assertTrue($this->engine->parse($pattern, $number, [])->matched);
    }

    public function test_the_sketch_describes_the_format_for_a_human(): void
    {
        $this->assertSame(
            '{size}-{service}-{class}-{subsystem}-{sequence}-{coating}',
            $this->pattern()->sketch(),
        );
    }
}
