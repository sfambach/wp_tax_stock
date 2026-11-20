<?php declare(strict_types=1);

namespace TaxmodStock\WordPress;

/**
 * The labels while the labels module does not answer (D-1296): none, so every label choice falls back to the fixed layout.
 *
 * @see wp_tax_label src/Core/LabelCatalog.php — the same questions, answered there
 */
final class NoLabels
{
    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return [];
    }

    /** @return array<string, mixed>|null */
    public function get(int $id): ?array
    {
        return null;
    }

    public function has(int $id): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $more
     * @param array<string, mixed> $contents
     * @return array<string, mixed>
     */
    public function dialog(int $chosen, array $more = [], array $contents = []): array
    {
        return [];
    }

    public function firstIn(int $node): int
    {
        return 0;
    }

    public function preferredIn(int $node, int $wanted): int
    {
        return 0;
    }

    /** @return array<int, array<string, mixed>> */
    public function printerList(): array
    {
        return [];
    }

    /**
     * D-1519: no rules without the module.
     *
     * @param array<string, mixed> $asked
     * @return array{id: int, name: string, label: int, copies: int|null, printer: int}|null
     */
    public function ruleFor(array $asked): ?array
    {
        return null;
    }

    public function preferring(int $printer): self
    {
        return $this;
    }
}
