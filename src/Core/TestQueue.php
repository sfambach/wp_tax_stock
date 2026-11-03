<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Which lots wait for their test after the goods receipt, and the change list that records a result (D-1002).
 *
 * A lot waits when its material lies in a tested branch, it has a rest, and its condition is empty or «ungetestet»: *his
 * word: «wenn ich dieses woanders kaufe, gebraucht, muss auch getestet werden»* — so the platform does not matter, and
 * «neu» goes without a test. The result is the lot's condition («Zustand», the same choices as on a copy): one fact, not
 * a second field beside it that could say otherwise.
 *
 * ```mermaid
 * flowchart LR
 *   L["lot with rest"] --> B{"material in a tested branch?"}
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
     * @param array<int, int|null> $nodeOfLot lot → the node its material lives in
     * @param array<int, int>      $parents   node → parent, the tree of the groups
     * @return list<int> the lots, in the order given
     */
    public function waiting(Ledger $ledger, Table $lots, array $nodeOfLot, array $parents, TestSettings $settings): array
    {
        if (! $this->isConfigured() || $settings->branches === []) {
            return [];
        }

        $field = $this->structure->field('lot_condition');
        $out   = [];

        foreach ($lots->ids() as $lot) {
            if ($ledger->restOf($lot) > 0.0 && $this->needsTest($nodeOfLot[$lot] ?? null, (string) ($lots->first($lot, $field) ?? ''), $parents, $settings)) {
                $out[] = $lot;
            }
        }

        return $out;
    }

    /**
     * The one rule of D-1002, for a lot that is there and for one the goods receipt is about to make: the material lies in a
     * tested branch, and the condition is empty or «ungetestet».
     *
     * @param string          $condition the condition's value id as stored, '' when none
     * @param array<int, int> $parents   node → parent, the tree of the groups
     */
    public function needsTest(?int $node, string $condition, array $parents, TestSettings $settings): bool
    {
        $s = $this->structure;

        if ($node === null || ! $this->isConfigured() || ! $settings->covers($node, $parents)) {
            return false;
        }

        return $condition === '' || ($s->hasValue('condition_untested') && $condition === (string) $s->value('condition_untested'));
    }

    /**
     * D-1111: the place the goods receipt proposes — «Lagerort für Test» for what will wait for its test, else the usual one.
     *
     * ```mermaid
     * flowchart LR
     *   P["usual proposal"] --> T{"test place set and the lot needs a test?"}
     *   T -->|yes| X["test place"]
     *   T -->|no| P2["usual proposal"]
     * ```
     *
     * @param array<int, int> $parents node → parent, the tree of the groups
     */
    public function receiptPlace(int $usual, ?int $node, string $condition, array $parents, TestSettings $settings): int
    {
        return $settings->place !== null && $this->needsTest($node, $condition, $parents, $settings) ? $settings->place : $usual;
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
