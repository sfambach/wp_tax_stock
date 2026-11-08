<?php declare(strict_types=1);

namespace TaxmodStock\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TaxmodStock\Core\LabelContents;
use TaxmodStock\Core\LabelTemplates;
use TaxmodStock\Core\PlaceTypes;
use TaxmodStock\Core\Structure;
use TaxmodStock\Core\Table;

/** D-1153, D-1159: labels as records — a place type names its template, the number of labels falls back in order. */
final class LabelTemplatesTest extends TestCase
{
    private const LAYOUT = '[{"type":"qr","field":"code","x":2,"y":2,"w":20}]';

    #[Test]
    public function a_complete_template_is_read_with_its_format(): void
    {
        $templates = $this->templates();

        self::assertSame(['name' => 'Platz', 'format' => ['key' => 'holder62', 'w' => 62, 'h' => 50, 'roll' => 62], 'copies' => 1, 'layout' => self::LAYOUT, 'category' => '', 'node' => 0], $templates->get(90));
        self::assertSame(2, $templates->get(91)['copies']);
        self::assertFalse($templates->has(92), 'a template without a layout cannot print');
        self::assertFalse($templates->has(93), 'a label without a size cannot print');
    }

    #[Test]
    public function the_number_of_labels_comes_from_the_type_then_the_template_then_the_box_mark(): void
    {
        $templates = $this->templates();
        $types     = new PlaceTypes([1 => 'Alex', 2 => 'Kiste', 3 => 'Regal', 4 => 'Fach'], [2 => true], [], [1 => [90, 3], 2 => [91, 0], 3 => [0, 0], 4 => [92, 0]]);

        self::assertSame([$templates->get(90), 3], $templates->forPlace($types, 1, false));
        self::assertSame([$templates->get(91), 2], $templates->forPlace($types, 2, true));
        self::assertSame([null, 1], $templates->forPlace($types, 3, false));
        self::assertSame([null, 2], $templates->forPlace($types, 0, true));
        self::assertSame([null, 1], $templates->forPlace($types, 4, false), 'an unusable template falls back to the fixed label');
    }

    #[Test]
    public function the_first_label_of_a_category_is_its_default(): void
    {
        $s      = $this->structure();
        $label  = $s->field('label');
        $row    = static fn (string $name): array => [$label => [$name], 400 => ['material62'], 401 => ['62'], 402 => ['30'], 405 => ['1'], 406 => [self::LAYOUT]];
        $labels = LabelTemplates::from($s, new Table([1 => $row('62×50'), 2 => $row('62×30'), 3 => $row('100×62')]), [], [1 => 40, 2 => 40, 3 => 41]);

        self::assertSame(2, $labels->firstIn(40));
        self::assertSame(3, $labels->firstIn(41));
        self::assertSame(0, $labels->firstIn(42));
    }

    #[Test]
    public function a_label_starts_on_the_first_printer_whose_loaded_roll_allows_it(): void
    {
        $labels = $this->withPrinters();

        self::assertSame(['queue' => 'Phomemo_M220L', 'roll' => 40, 'turn' => false, 'options' => '', 'turn_options' => '', 'sizes' => '*=w{B}h{H}', 'offset' => 21, 'line' => 38, 'name' => 'Phomemo M22L'], $labels->get(1)['printer']);
        self::assertSame('QL-810W_take', $labels->get(2)['printer']['queue'], 'the first by name that has a queue and a roll');
        self::assertTrue($labels->get(2)['printer']['turn']);
        self::assertArrayNotHasKey('printer', $labels->get(3), 'no loaded roll allows it: the print service chooses as before');
        self::assertSame(['name' => '40×30', 'format' => $labels->get(1)['format'], 'layout' => self::LAYOUT, 'printer' => $labels->get(1)['printer'], 'record' => 5], LabelTemplates::forButton($labels->get(1), ['record' => 5]));
    }

    #[Test]
    public function the_dialog_offers_the_labels_of_the_category_and_every_printer_with_what_its_roll_allows(): void
    {
        $labels = $this->withPrinters();
        $dialog = $labels->dialog(2, ['record' => 5]);

        self::assertSame(2, $dialog['chosen']);
        self::assertSame([1, 2, 3], array_keys($dialog['choices']), 'the whole category, by name');
        self::assertSame(5, $dialog['choices'][3]['record']);
        self::assertArrayNotHasKey('printer', $dialog['choices'][1], 'the printer is chosen in the dialog, not carried by a choice');
        self::assertSame([70, 71], array_keys($dialog['printers']));
        self::assertSame([1], $dialog['printers'][70]['allows']);
        self::assertSame('40×30 gestanzt', $dialog['printers'][70]['roll_name']);
        self::assertSame(1, $labels->firstIn(40), 'a label a printer can print goes first');
        self::assertSame([], $labels->dialog(99));
    }

    #[Test]
    public function a_label_with_a_content_field_offers_nothing_categories_or_materials_shortened_to_its_lines(): void
    {
        $s      = $this->structure();
        $label  = $s->field('label');
        $with   = '[{"type":"text","field":"inhalt","x":2,"y":40,"w":58,"h":20,"lines":3}]';
        $row    = static fn (string $name, string $layout): array => [$label => [$name], 400 => ['x'], 401 => ['62'], 402 => ['62'], 405 => ['1'], 406 => [$layout]];
        $labels = LabelTemplates::from($s, new Table([1 => $row('62×62', $with), 2 => $row('62×50', self::LAYOUT)]), [], [1 => 40, 2 => 40]);
        $dialog = $labels->dialog(1, ['values' => ['ort' => 'A1']], ['categories' => ['Widerstände', 'Kondensatoren'], 'materials' => ['R 10k', 'R 1k', 'C 100n', 'C 10µ'], 'words' => ['more' => '+%d weitere']]);

        self::assertSame(['ort' => 'A1'], $dialog['values'], 'the values stay the caller\'s');
        self::assertSame('categories', $dialog['variants']['chosen']);
        self::assertSame('Kondensatoren · Widerstände', $dialog['variants']['options']['categories']['text']);
        self::assertSame('C 10µ · C 100n · +2 weitere', $dialog['variants']['options']['materials']['text'], 'three lines: two names and the rest');
        self::assertSame('', $dialog['variants']['options']['']['text']);
        self::assertArrayNotHasKey('variants', $dialog['choices'][2], 'a label without a place for it offers nothing');
    }

    #[Test]
    public function a_content_line_says_how_many_it_leaves_out(): void
    {
        self::assertSame('A · B', LabelContents::line(['B', 'A', 'A', ' '], 2));
        self::assertSame('A · +2', LabelContents::line(['A', 'B', 'C'], 2));
        self::assertSame(6, LabelContents::maxOf('[{"field":"inhalt"}]'));
        self::assertSame(4, LabelContents::maxOf('[{"field":"inhalt","lines":2,"max":4}]'));
        self::assertFalse(LabelContents::named('[{"field":"code"}]'));
    }

    private function withPrinters(): LabelTemplates
    {
        $s      = $this->structure();
        $label  = $s->field('label');
        $row    = static fn (string $name, int $w, int $h, int $roll): array => [$label => [$name], 400 => ['k'], 401 => [(string) $w], 402 => [(string) $h], 403 => [(string) $roll], 405 => ['1'], 406 => [self::LAYOUT]];
        $rolls  = new Table([
            80 => [$label => ['40×30 gestanzt'], 440 => ['40'], 441 => ['1']],
            81 => [$label => ['62 mm endlos'], 440 => ['62'], 441 => ['2']],
        ]);
        $printers = new Table([
            70 => [$label => ['Phomemo M22L'], 430 => ['Phomemo_M220L'], 438 => ['80'], 431 => ['0'], 437 => ['*=w{B}h{H}'], 432 => ['21'], 433 => ['38']],
            71 => [$label => ['QL-810W'], 430 => ['QL-810W_take'], 438 => ['81'], 431 => ['1'], 436 => ['orientation-requested=4'], 437 => ['62x30=BrL053E01E3D0F6']],
            72 => [$label => ['A ohne Warteschlange'], 438 => ['81']],
            // D-1231: switched off, so not offered.
            73 => [$label => ['B aus'], 430 => ['Off'], 438 => ['81'], 442 => ['0']],
            73 => [$label => ['Z ohne Rolle'], 430 => ['QL-1100']],
        ]);

        return LabelTemplates::from($s, new Table([1 => $row('40×30', 40, 30, 40), 2 => $row('62×30', 62, 30, 62), 3 => $row('102×62', 102, 62, 102)]), [], [1 => 40, 2 => 40, 3 => 40], $printers, $rolls);
    }

    #[Test]
    public function a_place_type_reads_its_template_and_number(): void
    {
        $s     = $this->structure();
        $types = PlaceTypes::from($s, new Table([60 => [$s->field('label') => ['Alex'], 410 => ['90'], 411 => ['4']], 61 => [$s->field('label') => ['Fach']]]), new Table([]));

        self::assertSame(90, $types->labelTemplateOf(60));
        self::assertSame(4, $types->labelCopiesOf(60));
        self::assertSame(0, $types->labelTemplateOf(61));
        self::assertSame(0, $types->labelCopiesOf(99));
    }

    private function templates(): LabelTemplates
    {
        $s     = $this->structure();
        $label = $s->field('label');

        return LabelTemplates::from($s, new Table([
            90 => [$label => ['Platz'], 400 => ['holder62'], 401 => ['62'], 402 => ['50'], 403 => ['62'], 405 => ['1'], 406 => [self::LAYOUT]],
            91 => [$label => ['Kiste groß'], 400 => ['roll62'], 401 => ['100'], 402 => ['62'], 403 => ['62'], 405 => ['2'], 406 => [self::LAYOUT]],
            92 => [$label => ['Leer'], 400 => ['holder62'], 401 => ['62'], 402 => ['50'], 405 => ['1']],
            93 => [$label => ['Ohne Größe'], 400 => ['holder62'], 405 => ['1'], 406 => [self::LAYOUT]],
        ]));
    }

    private function structure(): Structure
    {
        $raw = Fixture::structure()->toArray();
        $raw['nodes'] += ['location_type' => 20, 'label_template' => 23];
        $raw['fields'] += ['template_key' => 400, 'template_width' => 401, 'template_height' => 402, 'template_roll' => 403,
            'template_copies' => 405, 'template_layout' => 406, 'type_label_template' => 410, 'type_label_copies' => 411,
            'printer_queue' => 430, 'printer_turns' => 431, 'printer_offset' => 432, 'printer_line' => 433,
            'printer_options' => 435, 'printer_turn_options' => 436, 'printer_sizes' => 437, 'printer_loaded_roll' => 438, 'roll_width' => 440, 'roll_labels' => 441, 'printer_active' => 442];

        return Structure::fromArray($raw);
    }
}
