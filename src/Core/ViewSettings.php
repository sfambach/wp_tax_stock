<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * How each tab of the stock page opens: with «Gleich aufgeklappt» the rows below a table row (an order's purchases,
 * a thing's lots) are open without a click. «Wareneingang» left with D-1440: its purchases stand in the rows themselves,
 * nothing is left to open. D-1467: whether a search with exactly one hit opens it at once — off unless set; a person's own
 * choice wins over the site's.
 */
final class ViewSettings
{
    /** The tabs whose rows can be opened, with their default. */
    public const DEFAULTS = ['material' => false];

    /**
     * @param array<string, bool> $expanded
     * @param array<string, bool> $opensSingle
     */
    private function __construct(private readonly array $expanded, private readonly array $opensSingle = [])
    {
    }

    /** @param array<string, mixed> $raw the stored option; a tab left out keeps its default */
    public static function fromArray(array $raw): self
    {
        $expanded = [];
        $single   = [];

        foreach (self::DEFAULTS as $tab => $default) {
            $expanded[$tab] = is_array($raw[$tab] ?? null) && array_key_exists('expand', $raw[$tab]) ? (bool) $raw[$tab]['expand'] : $default;
            $single[$tab]   = is_array($raw[$tab] ?? null) && ! empty($raw[$tab]['open_single']);
        }

        return new self($expanded, $single);
    }

    public function expanded(string $tab): bool
    {
        return $this->expanded[$tab] ?? false;
    }

    /**
     * D-1467: whether a search with exactly one hit opens it at once. His word 2026-11-18: «soll dann bei einer filterzeile stehen
     * bleiben nutzer wählt manuell aus … im benutzer profil hinterlegen und generell in den einstellungen, benutzer profil schlägt
     * einstellungen».
     *
     * @param bool|null $own the person's own choice; null follows the site's
     */
    public function opensSingleHit(string $tab, ?bool $own = null): bool
    {
        return $own ?? ($this->opensSingle[$tab] ?? false);
    }

    /** @return array<string, array{expand: bool, open_single: bool}> */
    public function toArray(): array
    {
        $out = [];

        foreach ($this->expanded as $tab => $on) {
            $out[$tab] = ['expand' => $on, 'open_single' => $this->opensSingle[$tab] ?? false];
        }

        return $out;
    }
}
