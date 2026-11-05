<?php declare(strict_types=1);

namespace TaxmodStock\Core;

/**
 * Where the user came from, so a jump between pages and modules leads back to the very spot (D-1179): his word 2026-11-05
 * «woher komme ich dahin springe ich zurück». A stack of origins, one per jump, newest last.
 *
 * ```mermaid
 * flowchart LR
 *   J["link with origin"] -->|arrive| P["push origin"]
 *   B["back link = top origin"] -->|arrive there| C["cut the stack at that origin"]
 *   N["navigation menu"] -->|arrive| E["empty"]
 * ```
 *
 * Addresses are compared as given; the caller hands them in the same form (path and query, without the origin arguments).
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1179
 */
final class ReturnPath
{
    /** Deeper chains are rare; the oldest origin goes first. */
    public const DEPTH = 10;

    /** @param list<array{url: string, label: string}> $origins oldest first */
    private function __construct(private readonly array $origins)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /** From what was stored; anything not shaped like an origin is dropped. Stored value is untyped, hence mixed. */
    public static function fromStored(mixed $stored): self
    {
        $origins = [];

        foreach (is_array($stored) ? $stored : [] as $entry) {
            if (is_array($entry) && is_string($entry['url'] ?? null) && $entry['url'] !== '' && is_string($entry['label'] ?? null)) {
                $origins[] = ['url' => $entry['url'], 'label' => $entry['label']];
            }
        }

        return new self(array_slice($origins, -self::DEPTH));
    }

    /**
     * The request reaches `$here`: back at an origin, the stack ends below it; a jump with an origin puts it on top.
     *
     * @param array{url: string, label: string}|null $from
     */
    public function arrive(string $here, ?array $from): self
    {
        $origins = $this->origins;

        foreach ($origins as $index => $origin) {
            if ($origin['url'] === $here) {
                $origins = array_slice($origins, 0, $index);
                break;
            }
        }

        $top = $origins === [] ? null : $origins[array_key_last($origins)];

        if ($from !== null && $from['url'] !== '' && $from['url'] !== $here && ($top === null || $top['url'] !== $from['url'])) {
            $origins[] = ['url' => $from['url'], 'label' => $from['label']];
        }

        return new self(array_slice($origins, -self::DEPTH));
    }

    /** @return array{url: string, label: string}|null where the back link leads */
    public function top(): ?array
    {
        return $this->origins === [] ? null : $this->origins[array_key_last($this->origins)];
    }

    /** @return list<array{url: string, label: string}> */
    public function toStored(): array
    {
        return $this->origins;
    }
}
