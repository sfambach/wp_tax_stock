<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Which lots wait for their test after the goods receipt, and the change list that records a result (D-1002).
 *
 * A lot waits when its material needs a test (D-1541: a test rule holds), it has a rest, and its condition is empty or «ungetestet»: *his
 * word: «wenn ich dieses woanders kaufe, gebraucht, muss auch getestet werden»* — so the platform does not matter, and
 * «neu» goes without a test. The result is the lot's condition («Zustand», the same choices as on a copy): one fact, not
 * a second field beside it that could say otherwise.
 *
 * ```mermaid
 * flowchart LR
 *   L["lot with rest"] --> B{"a test rule holds for the material?"}
 *   B -->|no| X["no test"]
 *   B -->|yes| N{"condition new?"}
 *   N -->|yes| X
 *   N -->|no| R{"result set?"}
 *   R -->|no| W["waits for its test"]
 *   R -->|yes| D["tested"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/lager/cockpit-wareneingang.md WC7
 */
final class TestQueue
{
    public function __construct(private readonly Structure $structure)
    {
    }

    /** Whether the structure knows where a result goes; without it the cockpit shows no test tab. */
    public function isConfigured(): bool
    {
        $s = $this->structure;

        return $s->hasField('lot_condition') && $s->hasField('lot_tested_on') && $s->hasField('lot_test_note')
            && array_filter(TestResult::cases(), static fn (TestResult $r): bool => ! $s->hasValue($r->value)) === [];
    }

    /**
     * @param array<int, bool> $requiredOfLot lot → whether its material needs a test (D-1541: a test rule holds; before the
     *                                        rules, D-1259's group property)
     * @return list<int> the lots, in the order given
     */
    public function waiting(Ledger $ledger, Table $lots, array $requiredOfLot): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $field = $this->structure->field('lot_condition');
        $out   = [];

        foreach ($lots->ids() as $lot) {
            if ($ledger->restOf($lot) > 0.0 && $this->needsTest($requiredOfLot[$lot] ?? false, (string) ($lots->first($lot, $field) ?? ''))) {
                $out[] = $lot;
            }
        }

        return $out;
    }

    /**
     * The one rule of D-1002, for a lot that is there and for one the goods receipt is about to make: its material needs a
     * test, and the condition is empty. D-1419: a lot marked «ungetestet» waits always — the boards a build books in — so this
     * list shows the lots {@see \TaxmodStock\WordPress\StockService::blockedLots()} blocks.
     *
     * @param bool   $required  whether the material needs a test (D-1541; the default is «kein Test»)
     * @param string $condition the condition's value id as stored, '' when none
     */
    public function needsTest(bool $required, string $condition): bool
    {
        $s = $this->structure;

        if (! $this->isConfigured()) {
            return false;
        }

        if ($condition !== '' && $s->hasValue('condition_untested') && $condition === (string) $s->value('condition_untested')) {
            return true;
        }

        return $condition === '' && $required;
    }

    /**
     * D-1111, D-1503: the place the goods receipt proposes — the test place for what will wait for its test, else the usual one.
     *
     * ```mermaid
     * flowchart LR
     *   P["usual proposal"] --> T{"test place known and the lot needs a test?"}
     *   T -->|yes| X["test place"]
     *   T -->|no| P2["usual proposal"]
     * ```
     */
    public function receiptPlace(int $usual, bool $required, string $condition, ?int $testPlace): int
    {
        return $testPlace !== null && $this->needsTest($required, $condition) ? $testPlace : $usual;
    }

    /**
     * D-1433 «Nacharbeit erledigt → zurück in Test»: the lot is «ungetestet» again, its test date cleared and the note as its
     * finding — which blocks it until a new result (D-1342).
     *
     * @return list<array<string, mixed>>
     */
    public function reopen(int $lot, string $note): array
    {
        $s = $this->structure;

        if (! $s->hasValue('condition_untested')) {
            throw StockError::notConfigured('values.condition_untested');
        }

        return [['op' => 'set_values', 'record' => (string) $lot, 'values' => [
            (string) $s->field('lot_condition') => (string) $s->value('condition_untested'),
            (string) $s->field('lot_tested_on') => '',
            (string) $s->field('lot_test_note') => trim($note),
        ]]];
    }

    /** @return list<array<string, mixed>> */
    public function record(int $lot, TestResult $result, string $reason, string $today): array
    {
        $s      = $this->structure;
        $reason = trim($reason);

        if ($result->needsReason() && $reason === '') {
            throw StockError::testWithoutReason($result->value);
        }

        return [['op' => 'set_values', 'record' => (string) $lot, 'values' => [
            (string) $s->field('lot_condition')   => (string) $s->value($result->value),
            (string) $s->field('lot_tested_on')   => substr($today, 0, 10),
            (string) $s->field('lot_test_note')   => $reason,
        ]]];
    }
}
