<?php
// Rooktest: alles wat de site publiek serveert laadt, en geen enkele link op de pagina is dood.
// - In `npm test` (test/run.php) tegen de lokale server met de nagebootste feed.
// - Los, alleen-lezen tegen een draaiende site:  php test/smoke.php https://dartpraat.nl
//   Doet daar alleen GET-verzoeken naar die ene host (geen POST, geen formulieren, geen verzoeken naar andere sites).
// Externe links (Spotify, socials, partners) worden nooit opgevraagd: alleen gecontroleerd op vorm (https, host, geen spaties).
declare(strict_types=1);

/**
 * @param string   $base    waar de site draait, zonder slash aan het eind (http://127.0.0.1:18087)
 * @param string   $siteUrl site.url uit site.json (https://dartpraat.nl); absolute links daarop horen bij de site zelf
 * @param bool     $writes  mag de test iets laten doen (POST refresh.php)? Alleen lokaal.
 * @param callable $check   fn(string $name, bool $ok, string $detail = '')
 */
function smoke(string $base, string $siteUrl, bool $writes, callable $check): void
{
    $base = rtrim($base, '/');
    $siteUrl = rtrim($siteUrl, '/');

    // De pagina's die de site publiek heeft (zie public/.htaccess en test/router.php).
    [$st, $html, $h] = smoke_get("$base/");
    $check('rook: / laadt (200, text/html)', $st === 200 && str_starts_with($h['content-type'] ?? '', 'text/html')
        && str_contains($html, '</html>'), "$st " . ($h['content-type'] ?? ''));
    [$st, $body, $h] = smoke_get("$base/status.json");
    $s = json_decode($body, true);
    $check('rook: /status.json laadt en meldt ok', $st === 200 && str_contains($h['content-type'] ?? '', 'json')
        && ($s['ok'] ?? false) === true, "$st " . substr($body, 0, 200));
    [$st, $sitemap, $h] = smoke_get("$base/sitemap.xml");
    $locs = [];
    $xml = $st === 200 ? @simplexml_load_string($sitemap) : false;
    if ($xml !== false) {
        foreach ($xml->url as $u) {
            $locs[] = (string) $u->loc;
        }
    }
    $check('rook: /sitemap.xml is geldige XML met adressen', $xml !== false && $locs !== [], "$st");
    [$st, $robots] = smoke_get("$base/robots.txt");
    $check('rook: /robots.txt laadt en wijst naar de sitemap', $st === 200 && str_contains($robots, "Sitemap: $siteUrl/sitemap.xml"), "$st");

    // Elk adres in de sitemap bestaat.
    $bad = [];
    foreach ($locs as $loc) {
        $u = smoke_local($loc, $siteUrl, $base);
        if ($u === null) {
            $bad[] = "$loc (ander domein)";
            continue;
        }
        [$st] = smoke_get($u);
        if ($st !== 200) {
            $bad[] = "$loc → $st";
        }
    }
    $check('rook: alle adressen uit de sitemap laden', $bad === [], implode(', ', $bad));

    // Alle links, beelden, scripts en stylesheets op de pagina.
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $ids = [];
    $dupes = [];
    foreach ((new DOMXPath($dom))->query('//*[@id]') as $el) {
        $id = $el->getAttribute('id');
        isset($ids[$id]) ? $dupes[] = $id : $ids[$id] = true;
    }
    $check('rook: geen dubbele id\'s', $dupes === [], implode(', ', $dupes));

    $refs = []; // url => waar gevonden
    $xp = new DOMXPath($dom);
    foreach (['//a/@href', '//link/@href', '//script/@src', '//img/@src', '//source/@src', '//iframe/@src',
        '//meta[@property="og:image" or @property="og:url"]/@content'] as $q) {
        foreach ($xp->query($q) as $attr) {
            $refs[trim($attr->value)][] = $attr->parentNode->nodeName;
        }
    }
    foreach ($xp->query('//img/@srcset | //source/@srcset') as $attr) {
        foreach (explode(',', $attr->value) as $part) {
            $refs[preg_split('/\s+/', trim($part))[0]][] = 'srcset';
        }
    }
    foreach ($xp->query('//*[@data-refresh]/@data-refresh') as $attr) {
        $refs[trim($attr->value)][] = 'data-refresh';
    }
    $check('rook: de pagina heeft links (> 20)', count($refs) > 20, (string) count($refs));

    $deadAnchors = $badExt = $deadLocal = $empty = $local = [];
    foreach ($refs as $ref => $where) {
        $w = implode('/', array_unique($where));
        if ($ref === '' || preg_match('~^\s*javascript:~i', $ref)) {
            $empty[] = "$w: '" . $ref . "'";
        } elseif ($ref[0] === '#') {
            if ($ref !== '#' && !isset($ids[rawurldecode(substr($ref, 1))])) {
                $deadAnchors[] = $ref;
            }
        } elseif (str_starts_with($ref, 'mailto:')) {
            if (!filter_var(substr($ref, 7), FILTER_VALIDATE_EMAIL)) {
                $badExt[] = $ref;
            }
        } elseif (($u = smoke_local($ref, $siteUrl, $base)) !== null) {
            $local[$u] = $w;
        } elseif (!smoke_external_ok($ref)) {
            $badExt[] = "$w: $ref";
        }
    }
    $check('rook: geen lege of javascript:-links', $empty === [], implode(', ', $empty));
    $check('rook: elke #anker op de pagina bestaat', $deadAnchors === [], implode(', ', $deadAnchors));
    $check('rook: externe links zijn geldige https-adressen (niet opgevraagd)', $badExt === [], implode(', ', $badExt));

    // Eigen bestanden echt opvragen (één keer per adres), en de url()'s in de css (fonts) erbij.
    $css = [];
    foreach ($local as $u => $w) {
        if ($w === 'data-refresh') {
            continue; // alleen POST, hieronder
        }
        [$st, $body, $h] = smoke_get($u);
        if ($st !== 200 || $body === '') {
            $deadLocal[] = "$w: " . substr($u, strlen($base)) . " → $st";
        } elseif (str_contains($h['content-type'] ?? '', 'css')) {
            preg_match_all('~url\(\s*[\'"]?([^\'")]+)~', $body, $m);
            foreach ($m[1] as $r) {
                if (!str_starts_with($r, 'data:')) {
                    $css[smoke_resolve($u, $r)] = 'css url()';
                }
            }
        }
    }
    foreach ($css as $u => $w) {
        [$st, $body] = smoke_get($u);
        if ($st !== 200 || $body === '') {
            $deadLocal[] = "$w: " . substr($u, strlen($base)) . " → $st";
        }
    }
    $check('rook: alle eigen links, beelden, css, js en fonts laden (' . (count($local) + count($css)) . ')', $deadLocal === [],
        implode(', ', $deadLocal));

    // Alles wat site.json als link opgeeft, staat ook echt op de pagina (niets valt stil weg).
    $cfg = json_decode((string) @file_get_contents(dirname(__DIR__) . '/public/site.json'), true) ?: [];
    $want = [];
    $walk = function (array $a) use (&$walk, &$want) {
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $walk($v);
            } elseif (in_array($k, ['url', 'href'], true) && is_string($v) && $v !== '' && !str_contains($v, 'rss')) {
                // `listen` met "video": true linkt naar de video van de nieuwste aflevering zodra die er is.
                $want[$v] = !empty($a['video']) ? 'https://www.youtube.com/watch?v=' : null;
            }
        }
    };
    $walk($cfg);
    $missing = [];
    foreach ($want as $v => $alt) {
        if ($alt !== null && preg_grep('~^' . preg_quote($alt, '~') . '[\w-]{11}$~', array_keys($refs))) {
            continue;
        }
        $abs = smoke_local((string) $v, $siteUrl, $base);
        if (!isset($refs[$v]) && !isset($refs[$v . '/']) && !($abs !== null && isset($local[$abs]))
            && !($v === $siteUrl && isset($refs["$siteUrl/"]))) {
            $missing[] = $v;
        }
    }
    $check('rook: elke link uit site.json staat op de pagina (' . count($want) . ')', $want !== [] && $missing === [], implode(', ', $missing));

    // Formulieren en knoppen die iets naar de server sturen.
    $forms = $xp->query('//form');
    $badForms = [];
    foreach ($forms as $f) {
        $action = smoke_local($f->getAttribute('action') ?: './', $siteUrl, $base);
        if ($action === null) {
            $badForms[] = 'form naar buiten: ' . $f->getAttribute('action');
        } elseif ($writes || strtolower($f->getAttribute('method')) !== 'post') {
            $method = strtoupper($f->getAttribute('method') ?: 'GET');
            [$st] = smoke_get($action, $method);
            if ($st < 200 || $st >= 400) {
                $badForms[] = "$method " . substr($action, strlen($base)) . " → $st";
            }
        }
    }
    $check('rook: formulieren sturen naar de eigen site en werken (' . $forms->length . ')', $badForms === [], implode(', ', $badForms));
    [$st] = smoke_get("$base/refresh.php");
    $check('rook: refresh.php weigert GET (405)', $st === 405, (string) $st);
    if ($writes) {
        [$st] = smoke_get("$base/refresh.php", 'POST');
        $check('rook: refresh.php (de beacon van de pagina) antwoordt op POST met 204', $st === 204, (string) $st);
    }

    // Wat niet bereikbaar mag zijn.
    foreach (['/_app/boot.php', '/_app/template.php'] as $p) {
        [$st] = smoke_get($base . $p);
        $check("rook: $p is dicht", $st >= 400 && $st < 500, (string) $st);
    }
}

/** GET (of andere methode), zonder redirects te volgen. [status, body, headers met kleine letters]. */
function smoke_get(string $url, string $method = 'GET'): array
{
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'method' => $method, 'follow_location' => 0,
        'header' => "User-Agent: podsite-rooktest\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    $hdrs = $http_response_header ?? [];
    $status = isset($hdrs[0]) ? (int) (explode(' ', $hdrs[0])[1] ?? 0) : 0;
    $h = [];
    foreach (array_slice($hdrs, 1) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $h[strtolower(trim($k))] = trim($v);
        }
    }
    return [$status, (string) $body, $h];
}

/** Hoort $ref bij de site zelf? Dan het adres om op te vragen (op $base), anders null. */
function smoke_local(string $ref, string $siteUrl, string $base): ?string
{
    foreach ([$siteUrl, $base] as $own) {
        if ($ref === $own || str_starts_with($ref, "$own/")) {
            return $base . (substr($ref, strlen($own)) ?: '/');
        }
    }
    if (preg_match('~^[a-z][a-z0-9+.-]*:|^//~i', $ref)) {
        return null;
    }
    return smoke_resolve("$base/", $ref);
}

/** Relatief adres oplossen tegen $from (genoeg voor deze site: ./, ../, /pad, pad?query). */
function smoke_resolve(string $from, string $ref): string
{
    $p = parse_url($from);
    $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $ref = preg_replace('~#.*$~', '', $ref);
    [$path, $query] = array_pad(explode('?', $ref, 2), 2, null);
    if ($path === '' || $path[0] !== '/') {
        $dir = preg_replace('~[^/]*$~', '', $p['path'] ?? '/');
        $path = $dir . $path;
    }
    $out = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '..') {
            array_pop($out);
        } elseif ($seg !== '.') {
            $out[] = $seg;
        }
    }
    $path = implode('/', $out);
    $path = ($path === '' || $path[0] !== '/') ? '/' . $path : $path;
    if (str_ends_with($ref, '/') || str_ends_with($ref, '.') || $ref === '') {
        $path = rtrim($path, '/') . '/';
    }
    return $origin . $path . ($query !== null ? "?$query" : '');
}

function smoke_external_ok(string $url): bool
{
    $p = parse_url($url);
    return $p !== false && ($p['scheme'] ?? '') === 'https' && !empty($p['host']) && str_contains($p['host'], '.')
        && !preg_match('~\s~', $url) && filter_var($url, FILTER_VALIDATE_URL) !== false;
}

// Los draaien: php test/smoke.php <base> [site-url]
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $base = $argv[1] ?? '';
    if (!preg_match('~^https?://~', $base)) {
        fwrite(STDERR, "gebruik: php test/smoke.php https://dartpraat.nl [site-url]\n");
        exit(2);
    }
    $cfg = json_decode((string) file_get_contents(dirname(__DIR__) . '/public/site.json'), true);
    $fail = 0;
    $pass = 0;
    smoke($base, $argv[2] ?? $cfg['site']['url'], false, function (string $name, bool $ok, string $detail = '') use (&$fail, &$pass) {
        $ok ? $pass++ : $fail++;
        echo ($ok ? 'ok  ' : 'FOUT ') . $name . (!$ok && $detail !== '' ? " — $detail" : '') . "\n";
    });
    echo "\n$pass ok, $fail fout\n";
    exit($fail ? 1 : 0);
}
