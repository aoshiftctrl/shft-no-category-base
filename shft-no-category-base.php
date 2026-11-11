<?php

/*
 * Plugin Name:       CatRmove
 * Plugin URI:        https://github.com/aoshiftctrl/shft-no-category-base
 * Description:       Entfernt den Kategorie-Praefix aus Kategorie-URLs (/category/aktuelles/ -> /aktuelles/) und leitet alte URLs per 301 um.
 * Version:           1.3.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            aoshiftctrl
 * Author URI:        https://github.com/aoshiftctrl 
 * License:           CC BY-ND 4.0
 * License URI:       https://creativecommons.org/licenses/by-nd/4.0/
 * Text Domain:       shft-no-category-base
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Funktionsweise
 *
 * - category_link:          Links werden ohne Praefix ausgegeben.
 * - category_rewrite_rules: In Cores Kategorie-Regeln (inkl. Feed, Embed,
 *                           Pagination, Endpoints) wird der Catch-all durch
 *                           eine explizite Regel je Kategoriepfad ersetzt.
 *                           Seiten, Beitraege und alle anderen URLs bleiben
 *                           unberuehrt.
 * - Alte URLs:              /category/... landet auf einer Redirect-Regel und
 *                           wird per 301 auf die neue URL umgeleitet.
 * - Kollisionen:            Ist die URL ohne Praefix schon vergeben (Seite,
 *                           Beitrag, WordPress-Pfad), gewinnt der bestehende
 *                           Inhalt und die Kategorie behaelt ihren Praefix
 *                           (siehe shft_ncb_taken()).
 * - Flush:                 Regeln werden bei Anlegen/Bearbeiten/Loeschen einer
 *                           Kategorie, bei (De-)Aktivierung und - falls sie
 *                           trotzdem veraltet sind - beim naechsten Request neu
 *                           erzeugt. Kategorie-Aenderungen bauen einmal am Ende
 *                           des Requests neu, (De-)Aktivierung verwirft nur.
 *
 * Praefix und Front werden aus der registrierten Permastruct gelesen, nicht
 * hart verdrahtet - ein eigener Kategorie-Praefix aus Einstellungen ->
 * Permalinks funktioniert damit ebenso wie /index.php/-Permalinks.
 */

define('SHFT_NCB_QUERY_VAR', 'shft_category_redirect');

/**
 * Normalisiert einen Pfad auf "/a/b/" bzw. "/".
 */
function shft_ncb_slashes(string $pth): string
{
    $pth = trim($pth, '/');
    return $pth === '' ? '/' : '/' . $pth . '/';
}

/**
 * Alter und neuer Praefix, z. B. ['old' => '/category/', 'new' => '/'].
 * null, wenn nichts zu tun ist (Plain-Permalinks, Taxonomie ohne Rewrite).
 *
 * @return array{old: string, new: string}|null
 */
function shft_ncb_bases(): ?array
{
    global $wp_rewrite;

    if (!$wp_rewrite instanceof WP_Rewrite || !$wp_rewrite->using_permalinks()) {
        return null;
    }

    $tax = get_taxonomy('category');
    if (!$tax || empty($tax->rewrite)) {
        return null;
    }

    $str = $wp_rewrite->get_extra_permastruct('category');
    if (!$str || strpos($str, '%category%') === false) {
        return null;
    }

    $old = shft_ncb_slashes(str_replace('%category%', '', $str));
    $new = shft_ncb_slashes(
        !empty($tax->rewrite['with_front']) ? $wp_rewrite->front : $wp_rewrite->root
    );

    return $old === $new ? null : ['old' => $old, 'new' => $new];
}

/**
 * Alle Kategoriepfade, hierarchisch: "eltern/kind".
 *
 * @return string[]
 */
function shft_ncb_category_paths(): array
{
    $trm = get_terms([
        'taxonomy'               => 'category',
        'hide_empty'             => false,
        'update_term_meta_cache' => false,
        'lang'                   => '', // Polylang: alle Sprachen
    ]);

    if (is_wp_error($trm) || !$trm) {
        return [];
    }

    $map = [];
    foreach ($trm as $t) {
        $map[$t->term_id] = $t;
    }

    $out = [];
    foreach ($trm as $t) {
        $seg = [$t->slug];
        $see = [$t->term_id => true]; // Schutz gegen zirkulaere Eltern
        $pid = (int) $t->parent;

        while ($pid && isset($map[$pid]) && !isset($see[$pid])) {
            $see[$pid] = true;
            array_unshift($seg, $map[$pid]->slug);
            $pid = (int) $map[$pid]->parent;
        }

        $out[] = implode('/', $seg);
    }

    return $out;
}

function shft_ncb_feed_regex(): string
{
    global $wp_rewrite;

    return implode('|', array_map(
        static fn ($f) => preg_quote($f, '#'),
        $wp_rewrite->feeds
    ));
}

/**
 * Schluessel der Redirect-Regel, dient zugleich als Marker fuer den
 * Self-Heal-Check.
 */
function shft_ncb_redirect_key(array $bas): string
{
    return ltrim($bas['old'], '/') . '(.+)$';
}

function shft_ncb_category_link(string $lnk): string
{
    $bas = shft_ncb_bases();
    if (!$bas) {
        return $lnk;
    }

    // Nur den Praefix direkt hinter home_url() ersetzen - nie irgendwo
    // im Slug.
    $frm = home_url($bas['old']);
    if (strpos($lnk, $frm) !== 0) {
        return $lnk;
    }

    $pth = substr($lnk, strlen($frm));
    if (isset(shft_ncb_taken()[trim($pth, '/')])) {
        return $lnk;
    }

    return home_url($bas['new']) . $pth;
}
add_filter('category_link', 'shft_ncb_category_link', 10, 1);

/**
 * Regex fuer einen Kategoriepfad, ohne Ruecksicht auf Gross-/Kleinschreibung:
 * /News/ soll wie mit Praefix die Kategorie finden (Core leitet danach auf
 * die kanonische URL um). Prozentkodierte Slugs (z. B. kyrillisch) stehen in
 * der DB mit kleinen Hex-Ziffern; Browser und Suchmaschinen senden grosse,
 * parse_request() prueft zusaetzlich die dekodierte Form. Die Query
 * normalisiert den Slug selbst (sanitize_title).
 */
function shft_ncb_path_regex(string $pth): string
{
    $rgx = '(?i:' . preg_quote($pth, '#') . ')';
    if (strpos($pth, '%') === false) {
        return $rgx;
    }

    return $rgx . '|' . preg_quote(rawurldecode($pth), '#');
}

/**
 * Ersetzt in Cores Kategorie-Regeln den Catch-all "category/(.+?)" durch
 * eine Regel je Kategoriepfad. Die eingehenden Regeln dienen als Vorlage,
 * damit Aenderungen anderer Plugins (Sprachpraefixe, Endpoints) erhalten
 * bleiben - Gruppennummern bleiben gleich, da genau eine Gruppe ersetzt wird.
 */
function shft_ncb_rewrite_rules(array $rul): array
{
    $bas = shft_ncb_bases();
    if (!$bas) {
        return $rul;
    }

    $old = ltrim($bas['old'], '/') . '(.+?)';
    $pre = ltrim($bas['new'], '/');
    $rsv = shft_ncb_reserved($bas);
    // Von WordPress belegte Pfade bekommen keine Regel (siehe shft_ncb_taken())
    $pth = array_filter(
        shft_ncb_category_paths(),
        static fn (string $p): bool => !shft_ncb_is_reserved(explode('/', $p)[0], $rsv)
    );
    $out = [];

    foreach ($rul as $key => $qry) {
        if (strpos($key, $old) === false) {
            $out[$key] = $qry;
            continue;
        }
        foreach ($pth as $p) {
            $out[str_replace($old, $pre . '(' . shft_ncb_path_regex($p) . ')', $key)] = $qry;
        }
    }

    // Alte URLs mit Praefix -> 301 (siehe shft_ncb_request()). Der
    // Parameter ist bewusst kein oeffentlicher Query-Var: er wird nur aus
    // der gematchten Regel gelesen und laesst sich nicht per GET/POST
    // setzen.
    $out[shft_ncb_redirect_key($bas)] = 'index.php?' . SHFT_NCB_QUERY_VAR . '=$matches[1]';

    return $out;
}
add_filter('category_rewrite_rules', 'shft_ncb_rewrite_rules', 20);

/**
 * 301 von /category/<pfad>[/feed|/embed|/page/n] auf die neue URL.
 * Der Pfad wird ueber die Kategorie aufgeloest, nicht per String-Ersatz -
 * so werden auch /category/kind/ (ohne Elternteil) korrekt umgeleitet.
 * Unbekannte Pfade enden im 404.
 */
function shft_ncb_request(array $var): array
{
    global $wp, $wp_rewrite;

    $bas = shft_ncb_bases();
    if (!$bas || !$wp instanceof WP || !$wp->matched_rule) {
        return $var;
    }
    if ($wp->matched_rule !== shft_ncb_redirect_key($bas)) {
        return shft_ncb_yield($var, $bas);
    }

    parse_str((string) $wp->matched_query, $mtq);
    $raw = trim(stripslashes((string) ($mtq[SHFT_NCB_QUERY_VAR] ?? '')), '/');

    $pth = $raw;
    $suf = '';
    $rgx = '#^(.+?)(/(?:(?:feed/)?(?:' . shft_ncb_feed_regex() . ')|embed|'
        . preg_quote($wp_rewrite->pagination_base, '#') . '/[0-9]+))$#';

    // Der volle Pfad hat Vorrang - eine Unterkategorie "news/rss" ist kein
    // Feed von "news".
    $cat = shft_ncb_find_category($raw, true);
    if (!$cat && preg_match($rgx, $raw, $m)) {
        $pth = $m[1];
        $suf = $m[2];
        $cat = shft_ncb_find_category($pth, true);
    }

    // Nur bei einem einzelnen Segment: der Slug einer Unterkategorie.
    // Beliebige Zwischensegmente werden nicht akzeptiert, auch nicht als
    // kodierter Slash (%2F).
    if (!$cat && strpos(urldecode($pth), '/') === false) {
        $cat = shft_ncb_find_category($pth, false);
    }

    $url = '';
    $srv = []; // Query-Vars fuer die Auslieferung ohne Redirect
    if ($cat) {
        $srv = ['category_name' => $cat->slug];
        // Behaelt die Kategorie ihren Praefix (siehe shft_ncb_taken()), ist
        // die alte URL die richtige - ein Redirect liefe im Kreis.
        $lnk = get_category_link($cat);
        if (strpos($lnk, home_url($bas['old'])) !== 0) {
            $url = user_trailingslashit(untrailingslashit($lnk) . $suf, 'category');
        }
    } else {
        // Beitrag unter altem Praefix (z. B. /%category%/%postname%/):
        // Ziel ist immer der kanonische Permalink, nie der Request-Pfad.
        $pid = url_to_postid(home_url($bas['new'] . $pth . '/'));
        if ($pid) {
            $srv = shft_ncb_post_vars($pid);
            $url = user_trailingslashit(untrailingslashit((string) get_permalink($pid)) . $suf, 'single');
        }
    }

    // Nichts gefunden: 404. category_name bleibt leer - WP_Query wertet
    // davon nur das letzte Segment aus und faende die Kategorie trotz
    // erfundener Zwischensegmente.
    if (!$srv) {
        return ['error' => '404'];
    }

    // Nur GET/HEAD im Frontend umleiten - ein 301 verwirft POST-Daten.
    $mth = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($url === '' || is_admin() || !in_array($mth, ['GET', 'HEAD'], true)) {
        return array_merge($var, $srv, shft_ncb_suffix_vars($suf));
    }

    $qry = wp_unslash($_GET);
    unset($qry[SHFT_NCB_QUERY_VAR]);
    if ($qry) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($qry, '', '&', PHP_QUERY_RFC3986);
    }

    wp_safe_redirect($url, 301, 'CatRmove');
    exit;
}
add_filter('request', 'shft_ncb_request');

/**
 * get_category_by_path() mit Pruefung der Segmente: Bleibt von einem
 * Segment nach sanitize_title() nichts uebrig ("-", "%20", "..."), sucht
 * Core nach einem leeren Slug und liefert eine beliebige Kategorie.
 */
function shft_ncb_find_category(string $pth, bool $ful): ?WP_Term
{
    foreach (explode('/', urldecode($pth)) as $seg) {
        if (sanitize_title($seg) === '') {
            return null;
        }
    }

    $cat = get_category_by_path($pth, $ful);

    return $cat instanceof WP_Term ? $cat : null;
}

/**
 * Query-Vars, die genau diesen Beitrag bzw. diese Seite liefern.
 */
function shft_ncb_post_vars(int $pid): array
{
    return [get_post_type($pid) === 'page' ? 'page_id' : 'p' => $pid];
}

/**
 * Trifft eine Kategorie-Regel eine URL, die einer veroeffentlichten Seite
 * bzw. einem Beitrag gehoert, gewinnt der Inhalt: Die Query zeigt auf ihn,
 * Feed, Embed und Pagination bleiben erhalten (wie bei Cores Seitenregeln).
 */
function shft_ncb_yield(array $var, array $bas): array
{
    global $wp;

    // Aus der gematchten Regel lesen, nicht aus $var - dort hat ein
    // gleichnamiger GET-Parameter Vorrang.
    parse_str((string) $wp->matched_query, $mtq);
    $pth = implode('/', array_map(
        'sanitize_title',
        explode('/', stripslashes((string) ($mtq['category_name'] ?? '')))
    ));
    if ($pth === '') {
        return $var;
    }

    // Nur die eigene Regel direkt unter dem neuen Praefix. Regeln mit
    // Sprachpraefix o. ae. gehoeren zu einer anderen URL.
    $key = ltrim($bas['new'], '/') . '(' . shft_ncb_path_regex($pth) . ')';
    $own = shft_ncb_taken()[$pth] ?? null;
    if (!is_int($own) || strpos($wp->matched_rule, $key) !== 0) {
        return $var;
    }

    unset($var['category_name']);

    return array_merge($var, shft_ncb_post_vars($own));
}

/**
 * Query-Vars zum Suffix "/feed/rss2", "/rss2", "/embed" oder "/page/2".
 */
function shft_ncb_suffix_vars(string $suf): array
{
    global $wp_rewrite;

    $seg = explode('/', trim($suf, '/'));
    if ($seg[0] === '') {
        return [];
    }
    if ($seg[0] === $wp_rewrite->pagination_base) {
        return ['paged' => (int) ($seg[1] ?? 1)];
    }
    if ($seg[0] === 'embed') {
        return ['embed' => 'true'];
    }

    return ['feed' => end($seg)];
}

/**
 * Verwirft die gespeicherten Regeln; WP baut sie beim naechsten
 * Bedarf neu. Billiger als flush_rewrite_rules() und bei Deaktivierung
 * korrekt, weil der Neubau dann ohne dieses Plugin laeuft.
 */
function shft_ncb_flush(): void
{
    delete_option('rewrite_rules');
}

/**
 * Baut die Regeln am Ende des Requests einmal neu, statt sie nur zu
 * verwerfen - sonst wuerde jeder parallele Frontend-Request den Neubau
 * selbst ausfuehren. Mehrere Aenderungen (Bulk, Import) fuehren nur zu
 * einem Neubau.
 */
function shft_ncb_queue_flush(): void
{
    if (!has_action('shutdown', 'shft_ncb_rebuild')) {
        add_action('shutdown', 'shft_ncb_rebuild');
    }
}

function shft_ncb_rebuild(): void
{
    flush_rewrite_rules(false);
}
add_action('created_category', 'shft_ncb_queue_flush');
add_action('edited_category', 'shft_ncb_queue_flush');
add_action('delete_category', 'shft_ncb_queue_flush');
register_activation_hook(__FILE__, 'shft_ncb_flush');
register_deactivation_hook(__FILE__, 'shft_ncb_deactivate');

/**
 * Eigene Filter vor dem Verwerfen entfernen - sonst schreibt ein Neubau
 * spaeter im selben Request die Plugin-Regeln wieder in die DB.
 */
function shft_ncb_deactivate(): void
{
    remove_filter('category_link', 'shft_ncb_category_link', 10);
    remove_filter('category_rewrite_rules', 'shft_ncb_rewrite_rules', 20);
    remove_filter('request', 'shft_ncb_request');
    remove_action('shutdown', 'shft_ncb_rebuild');
    remove_action('init', 'shft_ncb_self_heal', 99);
    shft_ncb_flush();
}

/**
 * Fehlt die Redirect-Regel in den gespeicherten Regeln (Multisite-
 * Netzwerkaktivierung, Import, Plugin hat Regeln aus dem Cache
 * geschrieben ...), werden sie neu gebaut. Hoechstens einmal pro Stunde,
 * damit ein fremder Filter, der die Regel wieder entfernt, keine
 * Neubau-Schleife ausloest.
 */
function shft_ncb_self_heal(): void
{
    $bas = shft_ncb_bases();
    if (!$bas) {
        return;
    }

    $rul = get_option('rewrite_rules');
    if (!is_array($rul) || isset($rul[shft_ncb_redirect_key($bas)])) {
        return;
    }

    if (get_transient('shft_ncb_healed')) {
        return;
    }

    set_transient('shft_ncb_healed', 1, HOUR_IN_SECONDS);
    shft_ncb_rebuild();
}
add_action('init', 'shft_ncb_self_heal', 99);

/**
 * Kategoriepfade, deren URL ohne Praefix schon vergeben ist. Die Kategorie
 * behaelt dann ihren Praefix: kein Link-Filter, kein Redirect.
 *
 * - true:    Das erste Segment gehoert WordPress (shft_ncb_reserved()).
 *            Gilt auch fuer alle Unterkategorien, sie bekommen keine Regel.
 * - Post-ID: Eine veroeffentlichte Seite bzw. ein Beitrag hat genau diesen
 *            Permalink (shft_ncb_yield() uebergibt die Anfrage).
 *
 * Geprueft wird zur Laufzeit statt beim Speichern der Kategorie. So gewinnt
 * der bestehende Inhalt, egal in welcher Reihenfolge und auf welchem Weg
 * die Slugs entstanden sind, und kein Slug wird stillschweigend geaendert.
 *
 * @return array<string, int|true>
 */
function shft_ncb_taken(): array
{
    static $run = false;

    $bas = shft_ncb_bases();
    // $run: get_permalink() kann ueber fremde Filter wieder hier landen
    if (!$bas || $run) {
        return [];
    }

    $key = md5((string) wp_json_encode([
        $bas,
        get_option('permalink_structure'),
        wp_cache_get_last_changed('posts'),
        wp_cache_get_last_changed('terms'),
    ]));
    $out = wp_cache_get($key, 'shft_ncb');
    if (is_array($out)) {
        return $out;
    }

    $run = true;
    $pth = shft_ncb_category_paths();
    $rsv = shft_ncb_reserved($bas);
    $out = [];
    $slg = [];
    foreach ($pth as $p) {
        $seg = explode('/', $p);
        if (shft_ncb_is_reserved($seg[0], $rsv)) {
            $out[$p] = true;
        } else {
            $slg[] = end($seg);
        }
    }

    if ($slg) {
        // Kandidaten: gleicher Slug wie das letzte Segment einer Kategorie.
        // Ob wirklich dieselbe URL entsteht, entscheidet der Permalink -
        // das deckt jede Permalink-Struktur und Seitenhierarchie ab.
        $qry = new WP_Query([
            'post_type'              => ['page', 'post'],
            'post_status'            => 'publish',
            'post_name__in'          => array_values(array_unique($slg)),
            'nopaging'               => true,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'ignore_sticky_posts'    => true,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'lang'                   => '', // Polylang: alle Sprachen
        ]);

        $set = array_flip($pth);
        $frm = home_url($bas['new']);
        foreach ($qry->posts as $pst) {
            $lnk = (string) get_permalink($pst);
            if (strpos($lnk, $frm) !== 0) {
                continue;
            }
            $p = trim(substr($lnk, strlen($frm)), '/');
            if (isset($set[$p]) && !isset($out[$p])) {
                $out[$p] = (int) $pst->ID;
            }
        }
    }
    $run = false;

    wp_cache_set($key, $out, 'shft_ncb', HOUR_IN_SECONDS);

    return $out;
}

/**
 * Erste Pfadsegmente unterhalb des neuen Praefixes, die WordPress selbst
 * belegt (Feeds, Embed, Suche, Autoren, REST, andere Taxonomien, CPT-
 * Archive ...). Kategorie-Regeln stehen vor diesen Regeln - eine Kategorie
 * "feed" wuerde sonst den Haupt-Feed uebernehmen.
 *
 * @return string[]
 */
function shft_ncb_reserved(array $bas): array
{
    global $wp_rewrite;

    $rot = $wp_rewrite->root;
    $str = array_merge(
        [
            $rot . $wp_rewrite->feed_base,
            $rot . 'embed',
            $rot . $wp_rewrite->pagination_base,
            $rot . $wp_rewrite->comments_base,
            $rot . $wp_rewrite->search_base,
            $rot . rest_get_url_prefix(),
            (string) $wp_rewrite->get_author_permastruct(),
            (string) $wp_rewrite->get_date_permastruct(),
            // Alter Praefix: eine Kategorie "category" wuerde mit ihren
            // Unterkategorien die alten URLs anderer Kategorien belegen
            $bas['old'],
        ],
        array_map(static fn ($f) => $rot . $f, $wp_rewrite->feeds)
    );

    foreach ($wp_rewrite->extra_permastructs as $nam => $ext) {
        if ($nam !== 'category') {
            $str[] = $ext['struct'];
        }
    }

    foreach (get_post_types(['_builtin' => false], 'objects') as $pto) {
        if (!$pto->has_archive || empty($pto->rewrite)) {
            continue;
        }
        $slg = $pto->has_archive === true ? $pto->rewrite['slug'] : $pto->has_archive;
        $str[] = (!empty($pto->rewrite['with_front']) ? $wp_rewrite->front : $rot) . $slg;
    }

    $new = trim($bas['new'], '/');
    $out = [];
    foreach ($str as $s) {
        // Nur der statische Teil vor dem ersten %tag% zaehlt
        $s = trim(explode('%', $s, 2)[0], '/');
        if ($new !== '') {
            if (strpos($s . '/', $new . '/') !== 0) {
                continue;
            }
            $s = ltrim(substr($s, strlen($new)), '/');
        }
        $seg = explode('/', $s)[0];
        if ($seg !== '') {
            $out[$seg] = true;
        }
    }

    return array_keys($out);
}

/**
 * Oberster Kategorie-Slug, der mit einem WordPress-Pfad kollidiert.
 * Rein numerisch: Jahresarchive bei /%year%/... und Kommentarseiten.
 */
function shft_ncb_is_reserved(string $slg, array $rsv): bool
{
    return in_array($slg, $rsv, true) || preg_match('#^(?:[0-9]+|comment-page-[0-9]+)$#', $slg) === 1;
}

/**
 * Listet unter Beitraege -> Kategorien und Einstellungen -> Permalinks die
 * Kategorien auf, die ihren Praefix behalten (siehe shft_ncb_taken()).
 */
function shft_ncb_conflict_notice(): void
{
    $scr = get_current_screen();
    if (!$scr || !in_array($scr->id, ['edit-category', 'options-permalink'], true)) {
        return;
    }
    if (!current_user_can('manage_categories')) {
        return;
    }

    $bas = shft_ncb_bases();
    if (!$bas) {
        return;
    }

    $hit = [];
    foreach (shft_ncb_taken() as $pth => $own) {
        $url = '<code>' . esc_html($bas['new'] . $pth . '/') . '</code>';
        if ($own === true) {
            $hit[] = sprintf(
                '<li>%s &ndash; %s</li>',
                $url,
                esc_html__('von WordPress belegter Pfad', 'shft-no-category-base')
            );
            continue;
        }

        $pto = get_post_type_object((string) get_post_type($own));
        $ttl = esc_html(get_the_title($own));
        $edt = get_edit_post_link($own); // null ohne edit_post
        $hit[] = sprintf(
            '<li>%s &ndash; %s %s</li>',
            $url,
            esc_html($pto ? $pto->labels->singular_name : ''),
            $edt ? sprintf('<a href="%s">%s</a>', esc_url($edt), $ttl) : $ttl
        );
    }
    if (!$hit) {
        return;
    }

    printf(
        '<div class="notice notice-info"><p><strong>%s</strong> %s</p><ul>%s</ul></div>',
        esc_html__('CatRmove:', 'shft-no-category-base'),
        esc_html__('Diese Pfade sind schon vergeben. Die Kategorien mit demselben Pfad behalten deshalb ihren Präfix:', 'shft-no-category-base'),
        implode('', $hit)
    );
}
add_action('admin_notices', 'shft_ncb_conflict_notice');
