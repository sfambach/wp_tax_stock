<?php declare(strict_types=1);

/**
 * A small Stock structure built through `taxmod/apply` and the module's option pointed at it — shared by the checks
 * (Grenzlauf 2026-11-17, modeller D-1427: navigation-check read the owner's live ids and fataled on any other database).
 *
 * Included at file scope: it reads `$kind` (aggregation|composition) and leaves `$r`, `$struktur`, `$field`, `$apply`
 * and the type ids behind for the including check. Runs inside the clamp (`lib/no-write.php`).
 */

use TaxmodStock\WordPress\StockService;

[$text, $int, $decimal, $bool, $date, $money] = [1175, 1171, 1173, 1179, 1183, 4232];
$field = static fn (string $ref, int|string $node, string $name, int|string $target, string $kind, string $mult): array
    => ['op' => 'add_field', 'ref' => $ref, 'node' => $node, 'name' => $name, 'target' => $target, 'kind' => $kind, 'multiplicity' => $mult];

$apply = static fn (array $changes): array => (array) wp_get_ability('taxmod/apply')->execute(['changes' => $changes]);
$aufbau = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'stock', 'name' => '__Stock', 'parent' => 402],
    // On Model itself, so the things in __Teile carry it as well as the Stock records.
    $field('label', 402, 'Bezeichnung', $text, 'composition', '0..1'),
    $field('bilder', 402, '__Bilder', $text, 'composition', '0..*'),
    ['op' => 'create_node', 'ref' => 'mat', 'name' => 'Lager', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'lot', 'name' => 'Bestandsposten', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'vg', 'name' => 'Vorgänge', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'vp', 'name' => 'Vorgänge Pos', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'lo', 'name' => 'Lagerorte', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'ord', 'name' => 'Bestellungen', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'pur', 'name' => 'Käufe', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'arten', 'name' => '__Vorgangsarten', 'parent' => 410, 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 'k_we', 'name' => 'Wareneingang', 'parent' => '@arten', 'class' => 'Constant'],
    ['op' => 'create_node', 'ref' => 'k_vb', 'name' => 'Verbrauch', 'parent' => '@arten', 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 'k_in', 'name' => 'Inventur', 'parent' => '@arten', 'class' => 'Constant'],
    ['op' => 'create_node', 'ref' => 'k_um', 'name' => 'Umbuchung', 'parent' => '@arten', 'class' => 'Constant'],
    ['op' => 'create_node', 'ref' => 'k_ul', 'name' => 'Umlagerung', 'parent' => '@arten', 'class' => 'Constant'],
    ['op' => 'create_node', 'ref' => 'k_vk', 'name' => 'Verkauf', 'parent' => '@arten', 'class' => 'Constant'],
    ['op' => 'create_node', 'ref' => 'stat', 'name' => '__Bestellstatus', 'parent' => 410, 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 's_teil', 'name' => 'teilweise eingegangen', 'parent' => '@stat'],
    ['op' => 'create_node', 'ref' => 's_ganz', 'name' => 'eingegangen', 'parent' => '@stat'],
    // D-1027: purchasing's statuses; D-1083: the reservation's kind.
    ['op' => 'create_node', 'ref' => 's_entw', 'name' => 'Entwurf', 'parent' => '@stat'],
    ['op' => 'create_node', 'ref' => 's_best', 'name' => 'bestellt', 'parent' => '@stat'],
    ['op' => 'create_node', 'ref' => 's_storn', 'name' => 'storniert', 'parent' => '@stat'],
    ['op' => 'create_node', 'ref' => 's_vers', 'name' => 'versendet', 'parent' => '@stat'],
    ['op' => 'create_node', 'ref' => 's_zug', 'name' => 'zugestellt', 'parent' => '@stat'],
    ['op' => 'create_node', 'ref' => 'k_res', 'name' => 'Reservierung', 'parent' => '@arten'],
    ['op' => 'create_node', 'ref' => 'quel', 'name' => '__Quellen', 'parent' => 410, 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 'q_hand', 'name' => 'von Hand', 'parent' => '@quel'],
    ['op' => 'create_node', 'ref' => 'verw', 'name' => '__Verwendungen', 'parent' => 410, 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 'u_eigen', 'name' => 'Eigenbedarf', 'parent' => '@verw'],
    ['op' => 'create_node', 'ref' => 'teile', 'name' => '__Teile', 'parent' => 402],
    $field('m_ding', '@mat', 'Material', 402, 'aggregation', '1..1'),
    $field('m_einz', '@mat', 'Einzelstück', $bool, 'composition', '1..1'),
    $field('m_min', '@mat', 'Mindestbestand', $int, 'composition', '0..1'),
    $field('m_use', '@mat', 'Verwendung', '@verw', 'aggregation', '0..1'),
    $field('m_ort', '@mat', 'Standard-Lagerort', '@lo', 'aggregation', '0..1'),
    $field('l_mat', '@lot', 'Material', 402, 'aggregation', '1..1'),
    $field('l_kauf', '@lot', 'Kauf', '@pur', 'aggregation', '0..1'),
    $field('l_lo', '@lot', 'Lagerort', '@lo', 'aggregation', '0..1'),
    $field('l_wert', '@lot', 'Wert je Stück', $money, 'composition', '0..1'),
    $field('l_dat', '@lot', 'Eingegangen am', $date, 'composition', '1..1'),
    $field('v_art', '@vg', 'Art', '@arten', 'aggregation', '1..1'),
    $field('v_dat', '@vg', 'Datum', $date, 'composition', '1..1'),
    $field('v_best', '@vg', 'Bestellung', '@ord', 'aggregation', '0..1'),
    $field('v_quel', '@vg', 'Quelle', '@quel', 'aggregation', '0..1'),
    $field('v_pos', '@vg', 'Positionen', '@vp', 'aggregation', '0..*'),
    $field('p_lot', '@vp', 'Bestandsposten', '@lot', 'aggregation', '1..1'),
    $field('p_menge', '@vp', 'Menge', $decimal, 'composition', '1..1'),
    $field('p_gez', '@vp', 'Gezählt', $decimal, 'composition', '0..1'),
    $field('p_lo', '@vp', 'Lagerort', '@lo', 'aggregation', '0..1'),
    $field('lo_vater', '@lo', 'Übergeordneter Ort', '@lo', 'aggregation', '0..1'),
    $field('lo_beweg', '@lo', 'beweglich', $bool, 'composition', '1..1'),
    $field('v_traeger', '@vg', 'Ladungsträger', '@lo', 'aggregation', '0..1'),
    $field('o_stat', '@ord', 'Status', '@stat', 'aggregation', '0..1'),
    $field('o_pos', '@ord', 'Positionen', '@pur', 'aggregation', '0..*'),
    $field('k_anz', '@pur', 'Anzahl', $int, 'composition', '0..1'),
    $field('k_mod', '@pur', 'Modell', 402, 'aggregation', '0..1'),
    $field('k_offen', '@pur', 'Noch nicht näher bestimmt', $bool, 'composition', '1..1'),
    $field('k_ein', '@pur', 'Einstandspreis je Stück', $money, 'composition', '0..1'),
    $field('k_preis', '@pur', 'Preis je Stück', $money, 'composition', '0..1'),
    $field('o_hand', '@ord', 'Händler', $text, 'composition', '0..1'),
    $field('k_bedarf', '@pur', 'Bedarf', '@ord', 'aggregation', '0..1'),
    $field('v_bezug', '@vg', 'Bezug', 402, 'aggregation', '0..1'),
    $field('p_ding', '@vp', 'Material', 402, 'aggregation', '0..1'),
    // D-1036: a line that cancels another one — the Storno of a resend's loss (D-1168).
    $field('p_storno', '@vp', 'Storniert', '@vp', 'aggregation', '0..1'),
    ['op' => 'create_record', 'ref' => 'lager', 'node' => '@lo'],
    ['op' => 'set_values', 'record' => '@lager', 'values' => ['@label' => 'Lager']],
    ['op' => 'create_record', 'ref' => 'platz', 'node' => '@lo'],
    ['op' => 'set_values', 'record' => '@platz', 'values' => ['@label' => 'Platinen']],
    // D-995: the things live in the tree; a Lager record hangs on the first.
    ['op' => 'create_record', 'ref' => 'lm', 'node' => '@teile'],
    ['op' => 'set_values', 'record' => '@lm', 'values' => ['@label' => 'LM2930T-5.0']],
    ['op' => 'create_record', 'ref' => 'lmlager', 'node' => '@mat'],
    ['op' => 'set_values', 'record' => '@lmlager', 'values' => ['@m_ding' => '@lm', '@m_einz' => '0', '@m_min' => '2']],
    ['op' => 'create_record', 'ref' => 'kaufA', 'node' => '@pur'],
    ['op' => 'set_values', 'record' => '@kaufA', 'values' => ['@label' => 'Konvolut unbestimmt', '@k_offen' => '1']],
    ['op' => 'create_record', 'ref' => 'kaufB', 'node' => '@pur'],
    ['op' => 'set_values', 'record' => '@kaufB', 'values' => ['@label' => 'LM2930T-5.0 ×5', '@k_offen' => '0', '@k_mod' => '@lm', '@k_anz' => '5',
        '@k_preis' => ['neu' => ['4234' => '1.80']]]],
    ['op' => 'create_record', 'ref' => 'best', 'node' => '@ord'],
    ['op' => 'set_values', 'record' => '@best', 'values' => ['@label' => 'Bestellung 1', '@o_pos' => ['values' => ['@kaufA', '@kaufB']]]],
    // Two more things; an order whose first purchase names one as «Modell» and whose second names none.
    ['op' => 'create_record', 'ref' => 't1', 'node' => '@teile'],
    ['op' => 'set_values', 'record' => '@t1', 'values' => ['@label' => 'BC640']],
    ['op' => 'create_record', 'ref' => 't2', 'node' => '@teile'],
    ['op' => 'set_values', 'record' => '@t2', 'values' => ['@label' => 'MPSA56']],
    ['op' => 'create_record', 'ref' => 'kaufC', 'node' => '@pur'],
    ['op' => 'set_values', 'record' => '@kaufC', 'values' => ['@label' => 'BC640 ×3', '@k_offen' => '0', '@k_mod' => '@t1', '@k_anz' => '3']],
    ['op' => 'create_record', 'ref' => 'kaufD', 'node' => '@pur'],
    ['op' => 'set_values', 'record' => '@kaufD', 'values' => ['@label' => 'MPSA56 ×2', '@k_offen' => '0', '@k_anz' => '2']],
    ['op' => 'create_record', 'ref' => 'best2', 'node' => '@ord'],
    ['op' => 'set_values', 'record' => '@best2', 'values' => ['@label' => 'Bestellung 2', '@o_pos' => ['values' => ['@kaufC', '@kaufD']]]],
    // D-1202: one material label, so the lists offer «Etiketten drucken …» for the ticked materials.
    ['op' => 'create_node', 'ref' => 'etk', 'name' => '__Etiketten', 'parent' => '@stock'],
    ['op' => 'create_node', 'ref' => 'etk_mat', 'name' => 'Materialetiketten', 'parent' => '@etk'],
    $field('e_key', '@etk', 'Druckformat', $text, 'composition', '0..1'),
    $field('e_w', '@etk', 'Breite', $int, 'composition', '0..1'),
    $field('e_h', '@etk', 'Höhe', $int, 'composition', '0..1'),
    $field('e_lay', '@etk', 'Layout', $text, 'composition', '0..1'),
    ['op' => 'create_record', 'ref' => 'etk40', 'node' => '@etk_mat'],
    ['op' => 'set_values', 'record' => '@etk40', 'values' => ['@label' => 'Material 40×30', '@e_key' => 'mat4030', '@e_w' => '40', '@e_h' => '30',
        '@e_lay' => '[{"type":"text","field":"Bezeichnung","x":2,"y":2,"w":36,"h":8,"pt":9}]']],
]]);

if (is_wp_error($aufbau)) {
    echo 'setup: ', $aufbau->get_error_message(), "\n";
    exit(1);
}

$r = $aufbau['refs'];

// D-1097: the switch as it will be made live — the records stand, only the kind of the two lists changes (D-587).
if ($kind === 'composition') {
    $wechsel = wp_get_ability('taxmod/apply')->execute(['changes' => [
        ['op' => 'set_kind', 'node' => $r['vg'], 'field' => $r['v_pos'], 'kind' => 'composition'],
        ['op' => 'set_kind', 'node' => $r['ord'], 'field' => $r['o_pos'], 'kind' => 'composition'],
    ]]);

    if (is_wp_error($wechsel)) {
        echo 'set_kind: ', $wechsel->get_error_message(), "\n";
        exit(1);
    }
}

$struktur = [
    'nodes'  => ['lager' => $r['mat'], 'lot' => $r['lot'], 'transaction' => $r['vg'], 'line' => $r['vp'], 'location' => $r['lo'], 'order' => $r['ord'], 'purchase' => $r['pur'], 'money' => $money,
        'lager_use' => $r['verw'], 'label_template' => $r['etk'], 'label_materials' => $r['etk_mat']],
    'fields' => [
        'lager_use' => $r['m_use'], 'lager_location' => $r['m_ort'], 'purchase_model' => $r['k_mod'],
        'order_merchant' => $r['o_hand'], 'purchase_demand' => $r['k_bedarf'], 'transaction_reference' => $r['v_bezug'], 'line_thing' => $r['p_ding'],
        'label' => $r['label'], 'images' => $r['bilder'], 'lager_thing' => $r['m_ding'], 'lager_single' => $r['m_einz'], 'lager_min' => $r['m_min'],
        'lot_thing' => $r['l_mat'], 'lot_purchase' => $r['l_kauf'], 'lot_location' => $r['l_lo'], 'lot_value' => $r['l_wert'], 'lot_date' => $r['l_dat'],
        'transaction_kind' => $r['v_art'], 'transaction_date' => $r['v_dat'], 'transaction_order' => $r['v_best'], 'transaction_source' => $r['v_quel'], 'transaction_lines' => $r['v_pos'],
        'line_lot' => $r['p_lot'], 'line_quantity' => $r['p_menge'], 'line_counted' => $r['p_gez'], 'line_location' => $r['p_lo'], 'line_reverses' => $r['p_storno'],
        'location_parent' => $r['lo_vater'], 'location_movable' => $r['lo_beweg'], 'transaction_carrier' => $r['v_traeger'],
        'order_status' => $r['o_stat'], 'order_lines' => $r['o_pos'],
        'purchase_quantity' => $r['k_anz'], 'purchase_undetermined' => $r['k_offen'],
        'template_key' => $r['e_key'], 'template_width' => $r['e_w'], 'template_height' => $r['e_h'], 'template_layout' => $r['e_lay'],
        'purchase_landed_price' => $r['k_ein'], 'purchase_unit_price' => $r['k_preis'], 'money_amount' => 4234, 'money_unit' => 4236,
    ],
    'values' => [
        'kind_receipt' => $r['k_we'], 'kind_consumption' => $r['k_vb'], 'kind_count' => $r['k_in'], 'kind_transfer' => $r['k_um'], 'kind_relocation' => $r['k_ul'], 'kind_sale' => $r['k_vk'], 'source_manual' => $r['q_hand'],
        'status_partial' => $r['s_teil'], 'status_received' => $r['s_ganz'], 'location_default' => $r['lager'],
        'status_draft' => $r['s_entw'], 'status_ordered' => $r['s_best'], 'status_cancelled' => $r['s_storn'], 'kind_reservation' => $r['k_res'],
        'status_shipped' => $r['s_vers'], 'status_delivered' => $r['s_zug'],
    ],
];
update_option(StockService::OPTION, $struktur);
// D-1296: the labels are the labels module's — point it at the same label node and fields, else it reads the live ids.
update_option('taxmod_label_structure', [
    'nodes'  => ['label_template' => $r['etk']],
    'fields' => array_intersect_key($struktur['fields'], array_flip(['label', 'template_key', 'template_width', 'template_height', 'template_layout', 'money_amount'])),
]);
