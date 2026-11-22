<?php declare(strict_types=1);

namespace TaxmodStock\WordPress\Admin;

use TaxmodStock\Core\StockList;
use Taxmod\WordPress\Admin\ListPage;

/**
 * The material search of the stock (D-1385): on the page «Bestand» opens it jumps to a material's stock; as a building block
 * in another module's dialog (D-1387) it picks a material, then a place and a quantity, and hands them on as fields and as
 * an event. The matching is the server's (D-1378, D-1287), so the script holds no copy of its rules.
 *
 * ```mermaid
 * flowchart LR
 *   T["typing"] --> H["hits: preferred first, then the search, each with stock"]
 *   H -->|page| J["open the material's stock"]
 *   H -->|picker| P["places of the material with free pieces"] --> Q["place + quantity"] --> E["fields + event taxmod-stock-material-picked"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1385, D-1387
 */
final class MaterialPicker
{
    /** D-1387: `apply_filters('taxmod_stock_material_picker', '', ['key' => …, 'name' => …, 'term' => …, 'preferred' => [id, …], 'except' => box])` → the picker's HTML. */
    public const FILTER = 'taxmod_stock_material_picker';

    /** D-1387: admin-post action answering the free places of one material. */
    public const PLACES = 'taxmod_stock_picker_places';

    /** The event a picker sends on its root when a material, a place and a quantity are taken. */
    public const EVENT = 'taxmod-stock-material-picked';

    private static bool $assets = false;

    /** D-1385: the search on the page of «Bestand», below its tiles; a hit opens that material's stock. */
    public static function page(bool $stock = false): string
    {
        // His word 2026-11-20 20:23: «die Suche im Bestand soll im Bestand bleiben» — from «Bestand» the list keeps to what is in
        // stock and shows the lots ({@see StockPage::asksStock()}); from «Stammdaten» it stays the material list.
        return '<form class="taxmod-material-search is-page" role="search" method="get" action="' . esc_url(admin_url('admin.php')) . '" data-mode="jump"' . self::data([]) . '>'
            . '<input type="hidden" name="page" value="' . esc_attr(Pages::STOCK) . '"><input type="hidden" name="tab" value="material">'
            . ($stock ? '<input type="hidden" name="filter" value="' . esc_attr(StockList::IN_STOCK) . '"><input type="hidden" name="' . esc_attr(StockPage::ASKS_STOCK) . '" value="1">' : '')
            . self::field('taxmod-material-search', 's', '', __('Material suchen', 'taxmod-stock'), true)
            . self::hint() . '</form>' . self::assets();
    }

    /**
     * D-1387: the picker for another module's dialog — several on one page, each with its own `key`. The chosen values travel
     * as `{name}[thing]`, `{name}[place]` and `{name}[quantity]`; the event carries them with the words shown.
     *
     * D-1398: with `piece` (pins per piece, from the BOM line) and `pieces`, a strip counted in pins is picked in pieces:
     * the quantity asked is pieces, `{name}[quantity]` carries their pins, `{name}[pieces]` and `{name}[piece]` travel too,
     * and each place says how many strips the cut opens.
     *
     * D-1582: `within` (the project of an order) also offers that project's boxes, with what no order was given there.
     *
     * @param array{key?: string, name?: string, term?: string, preferred?: list<int>, except?: int, within?: int, quantity?: float|int|string, piece?: int, pieces?: int} $args
     */
    public static function picker(array $args): string
    {
        $key       = sanitize_key((string) ($args['key'] ?? '')) ?: 'p' . wp_rand(1000, 999999);
        $name      = preg_replace('/[^A-Za-z0-9_\[\]-]/', '', (string) ($args['name'] ?? 'taxmod_pick_' . $key)) ?: 'taxmod_pick_' . $key;
        $preferred = array_values(array_filter(array_map('absint', is_array($args['preferred'] ?? null) ? $args['preferred'] : [])));
        $id        = 'taxmod-material-pick-' . $key;
        $wanted    = is_numeric($args['quantity'] ?? null) ? (string) (float) $args['quantity'] : '';
        $piece     = absint($args['piece'] ?? 0);
        $pieces    = $piece > 0 ? absint($args['pieces'] ?? 0) : 0;

        return '<div class="taxmod-material-search is-picker" id="' . esc_attr($id) . '" data-mode="pick" data-key="' . esc_attr($key) . '"'
            . self::data($preferred) . ' data-places="' . esc_url(add_query_arg(['action' => self::PLACES, '_ajax_nonce' => wp_create_nonce(self::PLACES), 'except' => absint($args['except'] ?? 0), 'within' => absint($args['within'] ?? 0)] + ($pieces > 0 ? ['piece' => $piece, 'pieces' => $pieces] : []), admin_url('admin-post.php'))) . '"'
            . ' data-wanted="' . esc_attr($wanted) . '" data-piece="' . esc_attr((string) $piece) . '" data-pieces="' . esc_attr((string) $pieces) . '"'
            /* translators: %d: pins per piece */
            . ' data-pieces-label="' . esc_attr(sprintf(__('Stücke à %d Pins', 'taxmod-stock'), $piece)) . '"'
            /* translators: %d: strips the cut opens */
            . ' data-strips="' . esc_attr__('%d Leiste(n) anbrechen', 'taxmod-stock') . '" data-no-fit="' . esc_attr__('Stücke passen hier in keine Leiste', 'taxmod-stock') . '">'
            . self::field($id . '-input', '', sanitize_text_field((string) ($args['term'] ?? '')), __('Material aus dem Bestand wählen', 'taxmod-stock'), false)
            . self::hint()
            // Stefan 20.11. 20:39 «Aus Bestand wählen» shows the material tree: the stock's standard material chooser (RecordPick::remote,
            // in a dialog inline under its field, D-1467/D-1488) beside the search; its choice goes on to the places like a hit.
            . '<div class="taxmod-material-search-tree">' . self::tree($id . '-tree') . '</div>'
            . '<div class="taxmod-material-search-pick" hidden><p class="taxmod-material-search-chosen"></p>'
            . '<fieldset class="taxmod-material-search-places"><legend>' . esc_html__('Lagerort', 'taxmod-stock') . '</legend><ul></ul></fieldset>'
            . '<p><label><span class="taxmod-material-search-qty-label">' . esc_html__('Menge', 'taxmod-stock') . '</span> <input type="number" class="small-text taxmod-material-search-qty" min="0" step="any"> <span class="taxmod-material-search-qty-unit"></span></label> '
            . '<button type="button" class="button button-primary taxmod-material-search-take">' . esc_html__('Übernehmen', 'taxmod-stock') . '</button></p>'
            . '<p class="taxmod-material-search-msg" role="alert"></p></div>'
            . '<input type="hidden" class="taxmod-material-search-out" data-part="thing" name="' . esc_attr($name . '[thing]') . '">'
            . '<input type="hidden" class="taxmod-material-search-out" data-part="place" name="' . esc_attr($name . '[place]') . '">'
            . '<input type="hidden" class="taxmod-material-search-out" data-part="quantity" name="' . esc_attr($name . '[quantity]') . '">'
            . '<input type="hidden" class="taxmod-material-search-out" data-part="pieces" name="' . esc_attr($name . '[pieces]') . '">'
            . '<input type="hidden" class="taxmod-material-search-out" data-part="piece" name="' . esc_attr($name . '[piece]') . '">'
            . '</div>' . self::assets();
    }

    /**
     * D-1387: the filter behind {@see self::FILTER} — an answer another callback gave stays.
     *
     * @param mixed $previous `mixed`: whatever another callback handed on
     * @param mixed $args     `mixed`: checked here
     */
    public static function filter(mixed $previous, mixed $args): string
    {
        if (is_string($previous) && $previous !== '') {
            return $previous;
        }

        return current_user_can('manage_options') ? self::picker(is_array($args) ? $args : []) : '';
    }

    /**
     * D-1387: admin-post handler — the free places of one material, most pieces first: capability → nonce → validate → act.
     */
    public static function placesAnswer(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error([], 403);
        }

        Acts::ajaxNonce(self::PLACES);
        $thing = absint($_GET['thing'] ?? 0);

        if ($thing <= 0) {
            wp_send_json_error([], 400);
        }

        $stock  = new \TaxmodStock\WordPress\StockService();
        $free   = $stock->freeStockByPlace([$thing], absint($_GET['except'] ?? 0), absint($_GET['within'] ?? 0))[$thing] ?? [];
        $places = $stock->places();
        arsort($free);
        // D-1398: pieces of a strip counted in pins — per place the strips the cut opens; a place they do not fit says so.
        $pieces = absint($_GET['pieces'] ?? 0);
        $strips = $pieces > 0 ? $stock->stripsToOpen($thing, array_keys($free), $pieces, absint($_GET['piece'] ?? 0)) : [];
        $cut    = $pieces > 0 && $stock->packsOf([$thing]) !== [];
        // D-1500, Prüftor 20.11.: the unit is shown beside the quantity; the server books in the material's unit, never in this word.
        // ⚠️ *Stefan 21.11. 15:08 «Mengenfeld schon wieder ohne Einheit, dafür Übernehmen dahinter»: with nothing free there is no row to
        // carry the unit and nothing to take — the script hides «Menge … Übernehmen» then and only says where nothing lies.*
        $unit   = $stock->unitWords([$thing])[$thing] ?? '';

        wp_send_json_success(array_map(static fn (int $place, float $quantity): array => [
            'place'    => $place,
            'unit'     => $unit,
            'path'     => (string) ($places[$place] ?? '#' . $place),
            'quantity' => ListPage::number($quantity),
        ] + ($cut ? ['strips' => $strips[$place] ?? 0] : []), array_keys($free), array_values($free)));
    }

    /** The stock's material chooser with the tree; the endpoints it reads are drawn once per page. */
    private static function tree(string $name): string
    {
        static $assets = false;
        $page = new StockPage();
        $html = $assets ? $page->materialChooser($name) : $page->materialPicker($name);
        $assets = true;

        return $html;
    }

    /** @param list<int> $preferred */
    private static function data(array $preferred): string
    {
        $hits = add_query_arg(array_filter(['action' => StockPage::THING_SEARCH, '_ajax_nonce' => wp_create_nonce(StockPage::THING_SEARCH), 'stock' => '1',
            'preferred' => implode(',', $preferred)]), admin_url('admin-post.php'));

        return ' data-hits="' . esc_url($hits) . '" data-unit="' . esc_attr__('St.', 'taxmod-stock') . '" data-none="' . esc_attr__('Kein Material gefunden', 'taxmod-stock') . '"'
            . ' data-preferred-label="' . esc_attr__('Bevorzugt', 'taxmod-stock') . '" data-no-place="' . esc_attr__('Liegt nirgends frei im Lager', 'taxmod-stock') . '"'
            . ' data-ask-place="' . esc_attr__('Bitte Lagerort und Menge wählen.', 'taxmod-stock') . '"';
    }

    private static function field(string $id, string $name, string $value, string $title, bool $big): string
    {
        return '<label class="taxmod-material-search-title' . ($big ? '' : ' is-small') . '" for="' . esc_attr($id) . '">' . esc_html($title) . '</label>'
            . '<span class="taxmod-material-search-field"><span class="dashicons dashicons-search" aria-hidden="true"></span>'
            . '<input type="search" id="' . esc_attr($id) . '" class="taxmod-material-search-input"' . ($name === '' ? '' : ' name="' . esc_attr($name) . '"') . ' value="' . esc_attr($value) . '" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="' . esc_attr($id . '-hits') . '" aria-autocomplete="list" placeholder="'
            . esc_attr__('Material suchen …', 'taxmod-stock') . '">'
            . '<ul id="' . esc_attr($id . '-hits') . '" class="taxmod-material-search-hits" role="listbox" hidden></ul></span>';
    }

    private static function hint(): string
    {
        return '<p class="taxmod-material-search-hint">' . esc_html__('u = µ · ohm = Ω · * = beliebig · Nummer vom Etikett (M-…)', 'taxmod-stock') . '</p>';
    }

    /** Style and script once per page, for every search and picker on it. */
    private static function assets(): string
    {
        if (self::$assets) {
            return '';
        }

        self::$assets = true;

        return '<style>'
            . '.taxmod-material-search{position:relative;box-sizing:border-box}'
            . '.taxmod-material-search.is-page{max-width:640px;margin:20px 0 8px;padding:16px 18px;background:#fff;border:1px solid #c3c4c7;border-radius:6px}'
            . '.taxmod-material-search-title{display:block;margin:0 0 10px;font-size:15px;font-weight:600}.taxmod-material-search-title.is-small{font-size:13px;margin-bottom:6px}'
            . '.taxmod-material-search-field{position:relative;display:block}'
            . '.taxmod-material-search-field .dashicons{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8c8f94;pointer-events:none}'
            . '.taxmod-material-search input[type=search]{width:100%;box-sizing:border-box;padding:6px 12px 6px 38px;min-height:40px;font-size:15px;border-radius:20px}'
            . '.taxmod-material-search-hits{position:absolute;z-index:100;left:0;right:0;top:calc(100% + 4px);margin:0;max-height:22em;overflow-y:auto;background:#fff;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,.15)}'
            . '.taxmod-material-search.is-picker .taxmod-material-search-hits{position:static;max-height:18em;box-shadow:none}'
            . '.taxmod-material-search-hits li{margin:0;border-bottom:1px solid #f0f0f1}.taxmod-material-search-hits li:last-child{border-bottom:0}'
            . '.taxmod-material-search-hits li.is-group{padding:4px 10px;font-size:11px;font-weight:600;text-transform:uppercase;color:#646970;background:#f6f7f7}'
            . '.taxmod-material-search-hits a{display:grid;grid-template-columns:1fr auto;gap:0 8px;padding:6px 10px;color:#1d2327;text-decoration:none}'
            . '.taxmod-material-search-hits .w{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.taxmod-material-search-hits .q{color:#007017;white-space:nowrap}'
            . '.taxmod-material-search-hits .q.is-zero{color:#8c8f94}.taxmod-material-search-hits .m{grid-column:1/-1;font-size:12px;color:#646970}'
            . '.taxmod-material-search-hits li.is-active a,.taxmod-material-search-hits a:hover{background:#f0f6fc}.taxmod-material-search-hits .is-empty{padding:8px 10px;color:#646970}'
            . '.taxmod-material-search-hint{margin:6px 4px 0;font-size:12px;color:#646970}'
            . '.taxmod-material-search-pick{margin-top:10px;padding:10px 12px;background:#f6f7f7;border-radius:4px}.taxmod-material-search-chosen{margin:0 0 8px;font-weight:600}'
            . '.taxmod-material-search-places{margin:0 0 8px}.taxmod-material-search-places legend{font-weight:600;margin-bottom:4px}.taxmod-material-search-places ul{margin:0}'
            . '.taxmod-material-search-msg{margin:4px 0 0;color:#b32d2e}.taxmod-material-search-msg:empty{display:none}'
            . '.taxmod-material-search-places li{margin:0 0 2px}.taxmod-material-search-places .n{color:#646970;margin-left:6px}'
            . '</style><script>(function(){if(window.taxmodMaterialSearch){return;}window.taxmodMaterialSearch=1;var ready=new WeakSet();'
            . 'function el(t,c,x){var e=document.createElement(t);if(c){e.className=c;}if(x!==undefined){e.textContent=x;}return e;}'
            . 'function num(v){return parseFloat(String(v).replace(",","."))||0;}'
            . 'function setup(root){if(ready.has(root)){return;}ready.add(root);var d=root.dataset,f=root.querySelector(".taxmod-material-search-input"),list=root.querySelector(".taxmod-material-search-hits"),'
            . 'pick=root.querySelector(".taxmod-material-search-pick"),timer=null,ask=0,at=-1,rows=[],chosen=null;if(pick){pick.dataset.qtyLabel=pick.querySelector(".taxmod-material-search-qty-label").textContent;}'
            . 'function show(o){list.hidden=!o;f.setAttribute("aria-expanded",o?"true":"false");}'
            . 'function opts(){return Array.prototype.filter.call(list.children,function(li){return li.getAttribute("role")==="option";});}'
            . 'function mark(i){var o=opts();at=i;o.forEach(function(li,n){li.setAttribute("aria-selected",n===i?"true":"false");li.classList.toggle("is-active",n===i);});'
            . 'if(i>=0&&o[i]){f.setAttribute("aria-activedescendant",o[i].id);o[i].scrollIntoView({block:"nearest"});}else{f.removeAttribute("aria-activedescendant");}}'
            . 'function choose(h){if(d.mode!=="pick"){window.location.href=h.url;return;}show(false);chosen=h;f.value=h.word;places(h);}'
            . 'function draw(hits){rows=hits;list.innerHTML="";at=-1;if(!hits.length){list.appendChild(el("li","is-empty",d.none));show(true);return;}var group=null;'
            . 'hits.forEach(function(h,n){if(h.preferred&&group===null){group=1;list.appendChild(el("li","is-group",d.preferredLabel));}else if(!h.preferred&&group===1){group=2;list.appendChild(el("li","is-group",""));}'
            . 'var li=el("li"),a=el("a"),q=el("span","q"+(num(h.quantity)>0?"":" is-zero"),h.quantity+" "+(h.unit||d.unit));li.id=f.id+"-hit-"+n;li.setAttribute("role","option");a.href=h.url||"#";'
            . 'a.appendChild(el("span","w",h.word));a.appendChild(q);a.appendChild(el("span","m",h.node));a.addEventListener("click",function(e){if(d.mode==="pick"){e.preventDefault();choose(h);}});'
            . 'li.appendChild(a);li.addEventListener("mouseenter",function(){mark(n);});list.appendChild(li);});show(true);}'
            . 'function search(){var t=f.value.trim();if(t.length<2&&!/^\d/.test(t)){show(false);return;}var me=++ask;'
            . 'fetch(d.hits+"&term="+encodeURIComponent(t),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(r){if(me===ask&&r&&r.success){draw(r.data||[]);}}).catch(function(){});}'
            . 'function places(h){var ul=pick.querySelector("ul"),qty=pick.querySelector(".taxmod-material-search-qty");pick.hidden=false;pick.querySelector(".taxmod-material-search-chosen").textContent=h.word;ul.innerHTML="";'
            . 'fetch(d.places+"&thing="+encodeURIComponent(h.id),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(r){var rows=r&&r.success?r.data:[];'
            . 'var line=qty.closest("p");line.hidden=!rows.length;if(!rows.length){ul.appendChild(el("li","",d.noPlace));return;}var cut=rows[0].strips!==undefined;pick.dataset.cut=cut?"1":"";pick.querySelector(".taxmod-material-search-qty-label").textContent=cut?d.piecesLabel:pick.dataset.qtyLabel;pick.querySelector(".taxmod-material-search-qty-unit").textContent=cut?"":(rows[0].unit||d.unit);'
            . 'rows.forEach(function(p,i){var li=el("li"),l=el("label"),c=document.createElement("input");c.type="radio";c.name=root.id+"-place";c.value=p.place;c.dataset.path=p.path;c.dataset.free=p.quantity;'
            . 'if(i===0){c.checked=true;}c.addEventListener("change",function(){if(!cut&&num(qty.value)>num(p.quantity)){qty.value=p.quantity;}});l.appendChild(c);l.appendChild(document.createTextNode(" "+p.path));l.appendChild(el("span","n",p.quantity+" "+(p.unit||d.unit)));if(cut){l.appendChild(el("span","n",p.strips>0?d.strips.replace("%d",p.strips):d.noFit));}li.appendChild(l);ul.appendChild(li);});'
            . 'qty.value=cut?num(d.pieces):(d.wanted&&num(d.wanted)>0?Math.min(num(d.wanted),num(rows[0].quantity)):rows[0].quantity);}).catch(function(){});}'
            . 'function fromTree(e){var t=e.target;if(!t||t.type!=="radio"||!t.value||!t.closest(".taxmod-material-search-tree")||!t.closest(".taxmod-record-choice")){return;}'
            . 'setTimeout(function(){var w=root.querySelector(".taxmod-material-search-tree .taxmod-chooser-shows, .taxmod-material-search-tree .taxmod-dialog-open");var h={id:+t.value,word:w?w.textContent.trim():"#"+t.value};if(d.mode!=="pick"){return;}chosen=h;f.value=h.word;places(h);},0);}'
            . 'root.addEventListener("change",fromTree);root.addEventListener("taxmod-record-picked",fromTree);'
            . 'if(pick){pick.querySelector(".taxmod-material-search-take").addEventListener("click",function(){var c=pick.querySelector("input[type=radio]:checked"),q=num(pick.querySelector(".taxmod-material-search-qty").value);'
            . 'var msg=pick.querySelector(".taxmod-material-search-msg");msg.textContent="";if(!chosen||!c||q<=0){msg.textContent=d.askPlace;return;}var cut=pick.dataset.cut==="1",v={thing:chosen.id,place:+c.value,quantity:cut?q*num(d.piece):q,pieces:cut?q:"",piece:cut?num(d.piece):""};root.querySelectorAll(".taxmod-material-search-out").forEach(function(o){o.value=v[o.dataset.part];});'
            . 'root.dispatchEvent(new CustomEvent("' . self::EVENT . '",{bubbles:true,detail:{key:d.key,thing:chosen.id,word:chosen.word,place:+c.value,path:c.dataset.path,quantity:v.quantity,pieces:v.pieces,piece:v.piece}}));});}'
            . 'f.addEventListener("input",function(){clearTimeout(timer);timer=setTimeout(search,250);});'
            . 'f.addEventListener("keydown",function(e){var o=opts();if(e.key==="Enter"&&at<0&&rows.length===1){e.preventDefault();choose(rows[0]);return;}if(e.key==="Enter"&&d.mode==="pick"){e.preventDefault();}if(list.hidden||!o.length){return;}'
            . 'if(e.key==="ArrowDown"){e.preventDefault();mark((at+1)%o.length);}else if(e.key==="ArrowUp"){e.preventDefault();mark(at<=0?o.length-1:at-1);}'
            . 'else if(e.key==="Enter"&&at>=0){e.preventDefault();choose(rows[at]);}else if(e.key==="Escape"){show(false);}});'
            . 'f.addEventListener("blur",function(){setTimeout(function(){show(false);},200);});'
            . 'f.addEventListener("focus",function(){if(rows.length&&f.value.trim()!==""){show(true);}else if(d.mode==="pick"&&f.value.trim()!==""&&!rows.length){search();}});}'
            . 'function all(){document.querySelectorAll(".taxmod-material-search[data-mode]").forEach(setup);}'
            . 'var wait=null;all();document.addEventListener("DOMContentLoaded",all);new MutationObserver(function(){if(!wait){wait=setTimeout(function(){wait=null;all();},100);}}).observe(document.documentElement,{childList:true,subtree:true});})();</script>';
    }
}
