<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

/** D-1182: the one way a firm is chosen in this module — the modeller's tree chooser. */
final class FirmChoice
{
    /**
     * D-1182: a firm chosen in the modeller's tree chooser — more than one level, and «New here …» creates one right there.
     *
     * @param list<array{depth: int, name: string, records: array<int, string>, node: int}> $tree
     */
    public static function field(string $name, array $tree, ?int $chosen): string
    {
        return \Taxmod\WordPress\Admin\RecordPick::field($name, $tree, $chosen);
    }
}
