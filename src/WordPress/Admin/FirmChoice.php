<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

/** D-1182: the one way a firm is chosen in this module — the modeller's tree chooser, with a plain list as fallback. */
final class FirmChoice
{
    /**
     * D-1182: a firm chosen in the modeller's tree chooser — more than one level, and «New here …» creates one right there. Without
     * the modeller's helper (older modeller) the plain list stays.
     *
     * @param list<array{depth: int, name: string, records: array<int, string>, node: int}> $tree
     */
    public static function field(string $name, array $tree, ?int $chosen): string
    {
        if (class_exists(\Taxmod\WordPress\Admin\RecordPick::class)) {
            return \Taxmod\WordPress\Admin\RecordPick::field($name, $tree, $chosen);
        }

        $options = '<option value="">—</option>';

        foreach ($tree as $row) {
            if ($row['records'] === []) {
                continue;
            }

            $options .= '<optgroup label="' . esc_attr($row['name']) . '">';

            foreach ($row['records'] as $id => $word) {
                $options .= '<option value="' . esc_attr((string) $id) . '"' . selected($chosen, $id, false) . '>' . esc_html($word) . '</option>';
            }

            $options .= '</optgroup>';
        }

        return '<select name="' . esc_attr($name) . '">' . $options . '</select>';
    }
}
