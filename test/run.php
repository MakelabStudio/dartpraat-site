<?php
// Tests zonder framework. Draaien: sh test/run.sh (of npm test).
// Deel 1 in-process (parser, titels, render), deel 2 echt over HTTP met twee `php -S`-servers: de site en een
// nagebootste feed, om te laten zien dat een nieuwe aflevering zonder deploy bovenaan komt en een kapotte feed de oude cache laat staan.
declare(strict_types=1);

define('PODSITE', 1);
$root = dirname(__DIR__);
require $root . '/public/_app/boot.php';

use Podsite\App;
use Podsite\Feed;
use Podsite\Refresher;
use Podsite\Titles;
use Podsite\View;

$failures = 0;
$passes = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $passes;
    if ($ok) {
        $passes++;
        echo "ok  $name\n";
    } else {
        $failures++;
        echo "FOUT $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

function tmpdir(string $tag): string
{
    $d = sys_get_temp_dir() . '/podsite-test-' . $tag . '-' . bin2hex(random_bytes(3));
    mkdir($d, 0775, true);
    return $d;
}

function rrmdir(string $d): void
{
    if (!is_dir($d)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($d);
}

/** Nieuwe aflevering bovenaan een feed zetten (zoals Spotify for Creators dat doet). */
function withNewEpisode(string $xml, string $title, string $desc = 'Nieuwe aflevering.', string $link = 'https://example.org/ep12',
    string $audio = 'https://example.org/ep12.mp3'): string
{
    $item = "<item><title><![CDATA[$title]]></title><description><![CDATA[$desc]]></description><link>$link</link>"
        . '<guid isPermaLink="false">nieuw-' . md5($title) . '</guid><pubDate>' . gmdate('D, d M Y H:i:s', time() - 60) . ' GMT</pubDate>'
        . '<enclosure url="' . $audio . '" length="1" type="audio/mpeg"/><itunes:duration>00:58:30</itunes:duration></item>';
    return preg_replace('/<item>/', $item . "\n<item>", $xml, 1);
}

$fixtureFeed = file_get_contents(__DIR__ . '/fixtures/feed.xml');
$fixtureYt = file_get_contents(__DIR__ . '/fixtures/youtube.xml');

// ---------------------------------------------------------------- 1. parser en titels
$parsed = Feed::parsePodcast($fixtureFeed);
check('feed: afleveringen geparsed', count($parsed['episodes']) >= 12);
check('feed: nieuwste eerst (S04E11)', $parsed['episodes'][0]['code'] === 'S04E11', (string) $parsed['episodes'][0]['code']);
check('feed: duur 01:09:55 = 4195 s', $parsed['episodes'][0]['duration'] === 4195);
check('feed: mp3-enclosure', str_ends_with($parsed['episodes'][0]['audio'], '.mp3'));
$yt = Feed::parseYouTube($fixtureYt);
check('youtube: koppeling op code S04E11', ($yt['videos']['S04E11']['id'] ?? '') === 'okvES8MG7QE');
check('youtube: video zonder code genegeerd', !in_array('e4xRJhju7e4', array_column($yt['videos'], 'id'), true));

$stop = json_decode(file_get_contents($root . '/public/site.json'), true)['guests']['not_names'];
$cases = [
    'Gian van Veen over Winmau, het EK, en het Dutch Darts Championship' => ['Gian van Veen'],
    "Danny 'The Mullet' Jansen" => ['Danny Jansen'],
    'Benito van de Pas (Zwaantje Masters 2026)' => ['Benito van de Pas'],
    'De 100e aflevering!' => [],
    'Premium Dartshop is geopend!' => [],
    'Vooruitblik Winmau World Masters' => [],
    'Premier League & NDB - Met Maikel Verberk' => ['Maikel Verberk'],
    'WK Darts 2026 Loting - Met Jacques Nieuwlaat & Koert Westerman' => ['Jacques Nieuwlaat', 'Koert Westerman'],
    'World Series of Darts Finals & WDF World Cup met bondscoach Ray de Vries & Alexander Merkx' => ['Ray de Vries', 'Alexander Merkx'],
    'Met Jerry Hendriks - De WK-aflevering' => ['Jerry Hendriks'],
    'Q-school 2026 met Jimmy van Schie (en meer)' => ['Jimmy van Schie'],
    'De laatste' => [],
];
foreach ($cases as $rest => $want) {
    $got = Titles::guests($rest, $stop);
    check("gasten: $rest", $got === $want, json_encode($got, JSON_UNESCAPED_UNICODE));
}
check('kop: naam + ondertitel', Titles::headline('Gian van Veen over Winmau, het EK', $stop) === ['Gian van Veen', 'over Winmau, het EK']);
check('kop: geen naam → hele titel', Titles::headline('De 100e aflevering!', $stop) === ['De 100e aflevering!', '']);
check('duur: 1 u 9 min', View::duration(4195) === '1 u 9 min');
check('duur: 50 min', View::duration(3008) === '50 min');
check('url: javascript: geweigerd', Feed::url('javascript:alert(1)') === '' && Feed::url('https://x.nl/a') === 'https://x.nl/a');

$bad = false;
try {
    Feed::parsePodcast('<html><body>Onderhoud</body>');
} catch (RuntimeException $e) {
    $bad = true;
}
check('feed: geen RSS → exception (oude cache blijft)', $bad);

// ---------------------------------------------------------------- 2. render in-process met vaste feed
function renderWith(string $feedXml, string $ytXml, ?string $cache = null): array
{
    $cache ??= tmpdir('render');
    putenv('PODSITE_CACHE_DIR=' . $cache);
    $app = new App(dirname(__DIR__) . '/public');
    $thumb = file_get_contents(__DIR__ . '/fixtures/thumb.jpg');
    $http = function (string $url) use ($feedXml, $ytXml, $thumb, $app): array {
        if ($url === $app->cfg['feed_url']) {
            return [200, $feedXml, ''];
        }
        if ($url === $app->cfg['youtube_url']) {
            return [200, $ytXml, ''];
        }
        return [200, $thumb, ''];
    };
    (new Refresher($app->store, $app->cfg, $http))->run(true);
    $v = View::build($app, $app->data());
    $v['asset_version'] = 'test';
    $v['beacon'] = false;
    $themeCss = View::themeCss($app->site['theme']);
    ob_start();
    require dirname(__DIR__) . '/public/_app/template.php';
    $html = (string) ob_get_clean();
    putenv('PODSITE_CACHE_DIR');
    return [$html, $cache, $v];
}

[$html, $cache, $v] = renderWith($fixtureFeed, $fixtureYt);
check('render: hero = nieuwste (Gian van Veen)', (bool) preg_match('~<h1 id="h-new">Gian van Veen<span class="sub">over Winmau~', $html));
check('render: 6 recente afleveringen', substr_count($html, 'class="ep"') === 6, (string) substr_count($html, 'class="ep"'));
check('render: lokale thumbnail (img.php), geen hotlink', str_contains($html, 'img.php?v=okvES8MG7QE') && !str_contains($html, 'ytimg.com'));
check('render: YouTube klik-om-te-laden', str_contains($html, 'data-yt="okvES8MG7QE"') && !str_contains($html, '<iframe'));
check('render: gastenmuur', str_contains($html, '>Marvin van Velzen</a>') && str_contains($html, '>Maikel Verberk</a>')
    && !str_contains($html, '>Bas Engelen</a>'));
preg_match_all('~<div class="wall">(.*?)</div>~s', $html, $wall);
preg_match_all('~>([^<]+)</a>~', $wall[1][0] ?? '', $names);
check('render: vaste gasten vooraan (Barneveld zonder aflevering in de feed → Spotify)', array_slice($names[1], 0, 2) === ['Raymond van Barneveld', 'Gian van Veen']
    && count(array_keys($names[1], 'Gian van Veen')) === 1 && count($names[1]) <= 12, json_encode($names[1], JSON_UNESCAPED_UNICODE));
check('render: partner PremiumDartshop met lokaal logo', str_contains($html, 'href="https://premiumdartshop.nl/"')
    && str_contains($html, 'src="assets/img/partners/premiumdartshop.webp"'));
check('render: Doe mee zonder Challonge, met knop naar PremiumDartshop', !str_contains($html, 'challonge.com')
    && substr_count($html, 'href="https://premiumdartshop.nl/"') >= 2);
check('render: vaste gast Raymond van Barneveld bij eerdere gasten', str_contains($html, 'Raymond van Barneveld'));
check('render: footer Made by Fannatic met logo en link, geen conceptbalk', str_contains($html, 'href="https://fannatic.shop/"')
    && str_contains($html, 'src="assets/img/partners/fannatic.svg"')
    && !str_contains($html, 'Makelab') && !str_contains($html, 'class="concept'));
preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m);
$ld = json_decode($m[1] ?? '', true);
check('render: schema.org PodcastSeries + PodcastEpisode', ($ld['@graph'][0]['@type'] ?? '') === 'PodcastSeries'
    && ($ld['@graph'][1]['@type'] ?? '') === 'PodcastEpisode');
check('render: Open Graph', str_contains($html, 'property="og:image"') && str_contains($html, 'property="og:title"'));
check('render: geen externe fonts/scripts', !preg_match('~(src|href)="https://(fonts\.|[^"]*\.js)~', $html));
check('render: thumbnails in de cache', is_file("$cache/thumbs/okvES8MG7QE-960.webp") || is_file("$cache/thumbs/okvES8MG7QE.jpg"));
rrmdir($cache);

// XSS: kwaadaardige titel, beschrijving, link en enclosure.
$evil = withNewEpisode($fixtureFeed, 'S04E12 - <script>alert("x")</script><img src=x onerror=alert(1)> "quote\' test',
    '<p>Hoi<script>alert(2)</script><a href="javascript:alert(3)">klik</a></p>', 'javascript:alert(4)', 'javascript:alert(5)');
[$html, $cache] = renderWith($evil, $fixtureYt);
check('xss: geen <script>alert in de pagina', !preg_match('~<script>alert|<img src=x~i', $html));
check('xss: titel ge-escaped zichtbaar', str_contains($html, '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;'));
check('xss: geen javascript:-URL', stripos($html, 'javascript:') === false);
preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m);
check('xss: JSON-LD zonder ruwe < of </script', isset($m[1]) && !str_contains($m[1], '<') && json_decode($m[1]) !== null);
rrmdir($cache);

// ---------------------------------------------------------------- 3. over HTTP: nieuwe aflevering, kapotte feed
$php = PHP_BINARY;
$base = (int) (getenv('E2E_PORT') ?: 18080);
$sitePort = $base + 7;
$feedPort = $base + 8;
$feedDir = tmpdir('feed');
$cache = tmpdir('cache');
file_put_contents("$feedDir/feed.xml", $fixtureFeed);
file_put_contents("$feedDir/youtube.xml", $fixtureYt);
$ttl = 3;
$env = array_merge(getenv(), [
    'FEED_DIR' => $feedDir,
    'PODSITE_CACHE_DIR' => $cache,
    'PODSITE_FEED_URL' => "http://127.0.0.1:$feedPort/feed.xml",
    'PODSITE_YOUTUBE_URL' => "http://127.0.0.1:$feedPort/youtube.xml",
    'PODSITE_THUMB_BASE' => "http://127.0.0.1:$feedPort/vi/",
    'PODSITE_TTL' => (string) $ttl,
]);
$procs = [];
foreach ([[$feedPort, __DIR__ . '/feed-server.php', __DIR__], [$sitePort, __DIR__ . '/router.php', $root . '/public']] as [$port, $router, $docroot]) {
    $procs[] = proc_open([$php, '-S', "127.0.0.1:$port", '-t', $docroot, $router], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w']], $pipes, null, $env + ['PHP_CLI_SERVER_WORKERS' => '3']);
}
register_shutdown_function(function () use (&$procs, $feedDir, $cache) {
    foreach ($procs as $p) {
        // php -S met workers: eerst de kinderen, dan de hoofdprocessen.
        $pid = proc_get_status($p)['pid'];
        @exec('pkill -TERM -P ' . (int) $pid);
        proc_terminate($p);
    }
    rrmdir($feedDir);
    rrmdir($cache);
});
function http(int $port, string $path, string $method = 'GET'): array
{
    $t = microtime(true);
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'method' => $method]]);
    $body = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
    $status = isset($http_response_header[0]) ? (int) explode(' ', $http_response_header[0])[1] : 0;
    return [$status, (string) $body, microtime(true) - $t, $http_response_header ?? []];
}
function hero(string $html): string
{
    return preg_match('~<div class="new">.*?<span>([^<]*)</span></div>~s', $html, $m) ? $m[1] : '';
}
foreach ([$sitePort, $feedPort] as $port) {
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100000);
    }
}
/** Wat site.js doet als de pagina data-refresh heeft: een POST naar refresh.php (de PHP-CLI-server heeft geen FPM). */
function beacon(int $port, string $html): bool
{
    if (!str_contains($html, 'data-refresh="refresh.php"')) {
        return false;
    }
    [$st] = http($port, '/refresh.php', 'POST');
    return $st === 204;
}

[$st, $html, , $hdrs] = http($sitePort, '/');
check('http: eerste bezoek (koude cache) 200 met S04E11', $st === 200 && str_starts_with(hero($html), 'S04E11'), "$st " . hero($html));
check('http: koude start → beacon haalt de thumbnails na het antwoord', beacon($sitePort, $html)
    && count(glob("$cache/thumbs/*")) > 0);
$h = strtolower(implode("\n", $hdrs));
check('http: security-headers', str_contains($h, 'content-security-policy:') && str_contains($h, "x-content-type-options: nosniff")
    && str_contains($h, 'referrer-policy:'));

// Nieuwe aflevering verschijnt in de feed.
file_put_contents("$feedDir/feed.xml", withNewEpisode($fixtureFeed, 'S04E12 - Michael van Gerwen over het WK'));
[, $html] = http($sitePort, '/');
check('nieuw: binnen de TTL nog de cache (S04E11)', str_starts_with(hero($html), 'S04E11'));
sleep($ttl + 1);
[, $html, $secs] = http($sitePort, '/');
check('nieuw: na de TTL eerst nog de oude pagina, zonder wachten (stale-while-revalidate)',
    str_starts_with(hero($html), 'S04E11') && $secs < 1.0, sprintf('%.2fs %s', $secs, hero($html)));
check('nieuw: verlopen pagina vraagt om verversen (beacon)', beacon($sitePort, $html));
[, $html] = http($sitePort, '/');
check('nieuw: S04E12 staat bovenaan, zonder deploy', str_starts_with(hero($html), 'S04E12')
    && str_contains($html, '<h1 id="h-new">Michael van Gerwen<span class="sub">over het WK'), hero($html));
check('nieuw: vorige hero schuift het raster in', (bool) preg_match('~class="code">S04E11 ·~', $html));

// Kapotte feed: 500 en daarna HTML in plaats van RSS → de laatste goede cache blijft.
foreach (['500', 'garbage'] as $mode) {
    file_put_contents("$feedDir/mode", $mode);
    $state = json_decode(file_get_contents("$cache/state.json"), true);
    $state['feed']['fetched_at'] = time() - $ttl - 1; // TTL verlopen
    file_put_contents("$cache/state.json", json_encode($state));
    [, $html] = http($sitePort, '/');
    beacon($sitePort, $html);
    [$st, $html] = http($sitePort, '/');
    [, $status] = http($sitePort, '/status.json');
    $s = json_decode($status, true);
    check("kapot ($mode): pagina 200 met de laatste goede cache (S04E12)", $st === 200 && str_starts_with(hero($html), 'S04E12'), hero($html));
    check("kapot ($mode): status.json meldt de fout", !empty($s['last_error']['msg']) && ($s['latest']['code'] ?? '') === 'S04E12',
        json_encode($s['last_error'] ?? null));
}
// Trage feed: de bezoeker merkt het niet (verversen gebeurt na het antwoord, met de feed-timeout).
file_put_contents("$feedDir/mode", 'slow');
$state = json_decode(file_get_contents("$cache/state.json"), true);
$state['feed']['fetched_at'] = time() - $ttl - 1;
$state['feed']['last_attempt'] = time() - 120;
file_put_contents("$cache/state.json", json_encode($state));
// De beacon loopt parallel (eigen verzoek) en wacht op de trage feed; de pagina zelf niet.
$bg = proc_open([PHP_BINARY, '-r', 'file_get_contents("http://127.0.0.1:' . $sitePort . '/refresh.php", false, stream_context_create(["http" => ["method" => "POST", "timeout" => 10]]));'],
    [], $pipes);
usleep(300000);
[$st, $html, $secs] = http($sitePort, '/');
check('traag: pagina direct uit de cache terwijl het verversen loopt', $st === 200 && $secs < 1.0 && str_starts_with(hero($html), 'S04E12'),
    sprintf('%.2fs', $secs));
$t = microtime(true);
proc_close($bg);
check('traag: verversen stopt op de feed-timeout (± 3 s)', microtime(true) - $t < 4.5);
file_put_contents("$feedDir/mode", 'ok');
[$st] = http($sitePort, '/refresh.php');
check('refresh.php: alleen POST', $st === 405);

[$st, $body, , $hdrs] = http($sitePort, '/status.json');
$s = json_decode($body, true);
check('status.json: velden', $st === 200 && isset($s['ok'], $s['latest']['code'], $s['cache_age_s'], $s['ttl_s']) && array_key_exists('last_error', $s));
[$st, $body] = http($sitePort, '/sitemap.xml');
check('sitemap.xml', $st === 200 && str_contains($body, '<loc>https://dartpraat.nl/</loc>'));
[$st, $body] = http($sitePort, '/robots.txt');
check('robots.txt', $st === 200 && str_contains($body, 'Sitemap: https://dartpraat.nl/sitemap.xml'));
[$st, , , $hdrs] = http($sitePort, '/img.php?v=../../etc/passwd&w=960');
check('img.php: ongeldige id → fallback-beeld', $st === 200 && in_array('Content-Type: image/webp', $hdrs, true));
[$st] = http($sitePort, '/_app/boot.php');
check('_app/ niet bereikbaar', $st === 404);

$hits = file_exists("$feedDir/hits.log") ? file("$feedDir/hits.log", FILE_IGNORE_NEW_LINES) : [];
check('thumbnails via de eigen server opgehaald, niet per bezoek', count(array_filter($hits, fn ($l) => str_starts_with($l, '/vi/'))) <= 10);

echo "\n$passes ok, $failures fout\n";
exit($failures ? 1 : 0);
