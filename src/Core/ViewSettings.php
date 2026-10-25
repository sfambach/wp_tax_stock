<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * How each tab of the stock page opens: with «Gleich aufgeklappt» the rows below a table row (an order's purchases,
 * a material's lots) are open without a click. On by default only for «Wareneingang» (his word 2026-10-25:
 * «Standardeinstellung soll nur für den Wareneingang Expand automatically sein»).
 */
final class ViewSettings
{
    /** The tabs whose rows can be opened, with their default. */
    public const DEFAULTS = ['wareneingang' => true, 'bestand' => false];

    /** @param array<string, bool> $expanded */
    private function __construct(private readonly array $expanded)
    {
    }

    /** @param array<string, mixed> $raw the stored option; a tab left out keeps its default */
    public static function fromArray(array $raw): self
    {
        $expanded = [];

        foreach (self::DEFAULTS as $tab => $default) {
            $expanded[$tab] = is_array($raw[$tab] ?? null) && array_key_exists('expand', $raw[$tab]) ? (bool) $raw[$tab]['expand'] : $default;
        }

        return new self($expanded);
    }

    public function expanded(string $tab): bool
    {
        return $this->expanded[$tab] ?? false;
    }

    /** @return array<string, array{expand: bool}> */
    public function toArray(): array
    {
        return array_map(static fn (bool $on): array => ['expand' => $on], $this->expanded);
    }
}
