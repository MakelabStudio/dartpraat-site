<?php
// De pagina. Alles wat uit de feed of site.json komt gaat door e(); iconen komen uit de code.
declare(strict_types=1);

namespace Podsite;

defined('PODSITE') || exit;

/** @var array $v  uit View::build() */
/** @var string $themeCss */
$s = $v['site'];
$t = $v['tokens'];
$hero = $v['hero'];
$url = rtrim($s['site']['url'], '/') . '/';
$img = function (?array $ep, int $w) use ($s): string {
    return $ep && $ep['has_thumb'] ? View::thumbUrl($ep['video'], $w) : $s['images']['fallback'];
};
$h2 = fn (array $x, string $id) => '<h2 id="' . e($id) . '">' . e($x['title']) . ' <em>' . e($x['em']) . '</em></h2>';
$ext = 'target="_blank" rel="noopener"';
?>
<!doctype html>
<html lang="<?= e($s['site']['lang'] ?? 'nl') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= e($hero ? $s['site']['name'] . ' · ' . ($hero['code'] ? $hero['code'] . ' ' : '') . $hero['head'] : $s['site']['title']) ?></title>
<meta name="description" content="<?= e($s['site']['description']) ?>">
<link rel="canonical" href="<?= e($url) ?>">
<link rel="alternate" type="application/rss+xml" title="<?= e($s['site']['name']) ?>" href="<?= e($s['feed']['url']) ?>">
<link rel="icon" href="<?= e($s['images']['icon']) ?>" type="image/png">
<link rel="apple-touch-icon" href="<?= e($s['images']['icon']) ?>">
<meta name="theme-color" content="<?= e($s['theme']['stage'] ?? '#000000') ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($s['site']['name']) ?>">
<meta property="og:title" content="<?= e($s['site']['title']) ?>">
<meta property="og:description" content="<?= e($hero ? 'Nieuw: ' . $hero['title'] : $s['site']['description']) ?>">
<meta property="og:url" content="<?= e($url) ?>">
<meta property="og:image" content="<?= e($v['og_image'] ?? $url . $s['images']['og']) ?>">
<meta property="og:locale" content="nl_NL">
<meta name="twitter:card" content="summary_large_image">
<link rel="preload" href="assets/fonts/saira-condensed-800.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="assets/fonts/saira-latin.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/site.css?v=<?= e($v['asset_version']) ?>">
<style><?= $themeCss ?></style>
<script type="application/ld+json"><?= $v['jsonld'] ?></script>
</head>
<body<?= $v['beacon'] ? ' data-refresh="refresh.php"' : '' ?>>
<a class="skip" href="#afleveringen">Naar de afleveringen</a>
<nav class="gutter" aria-label="Hoofdmenu">
  <div class="inner">
    <a class="brand" href="./"><img src="<?= e($s['images']['logo']) ?>" alt="" width="38" height="38"><span><?= e($s['site']['name']) ?></span></a>
    <div class="navlinks"><?php foreach ($s['nav']['links'] as $l): ?><a href="<?= e($l['href']) ?>"><?= e($l['label']) ?></a><?php endforeach ?></div>
    <a class="navcta" href="<?= e($s['nav']['cta']['url']) ?>" <?= $ext ?>><?= e($s['nav']['cta']['label']) ?></a>
  </div>
</nav>

<main id="top">
<?php if ($hero): ?>
  <section class="hero gutter" aria-labelledby="h-new">
    <div class="inner">
      <div>
        <div class="new"><span class="live"><?= $v['hero_is_new'] ? 'Nieuw' : 'Laatste' ?></span><span><?= e(implode(' · ', array_filter([$hero['code'], View::longDate($hero['ts']), View::duration($hero['duration'])]))) ?></span></div>
        <h1 id="h-new"><?= e($hero['head']) ?><?php if ($hero['sub'] !== ''): ?><span class="sub"><?= e($hero['sub']) ?></span><?php endif ?></h1>
        <p class="desc"><?= e(View::excerpt($hero['description'])) ?></p>
        <div class="actions">
          <a class="play" href="<?= e($hero['link'] ?: $s['nav']['cta']['url']) ?>" <?= $ext ?><?php if ($hero['audio']): ?> data-audio="<?= e($hero['audio']) ?>" data-title="<?= e(trim(($hero['code'] ?? '') . ' · ' . $hero['head'], ' ·')) ?>"<?php endif ?>><i></i>Luister nu</a>
<?php foreach ($s['listen'] as $l): $href = !empty($l['video']) && $hero['video'] ? 'https://www.youtube.com/watch?v=' . $hero['video'] : $l['url']; ?>
          <a class="ghost" href="<?= e($href) ?>" <?= $ext ?>><?= Icons::svg($l['icon'], 'i-' . $l['icon']) ?><?= e($l['label']) ?></a>
<?php endforeach ?>
        </div>
      </div>
<?php if ($hero['video']): ?>
      <a class="feature" href="https://www.youtube.com/watch?v=<?= e($hero['video']) ?>" <?= $ext ?> data-yt="<?= e($hero['video']) ?>" aria-label="<?= e('Kijk de aflevering: ' . trim(($hero['code'] ?? '') . ' ' . $hero['head']) . ' op YouTube') ?>">
        <img src="<?= e($img($hero, 960)) ?>"<?php if ($hero['has_thumb']): ?> srcset="<?= e($img($hero, 480)) ?> 480w, <?= e($img($hero, 960)) ?> 960w" sizes="(max-width: 900px) 100vw, 560px"<?php endif ?> alt="<?= e($hero['head'] . ' aan tafel in de studio') ?>" width="960" height="540" fetchpriority="high">
        <span class="ring" aria-hidden="true"></span>
        <span class="meta"><b>Kijk de aflevering</b><small><?= e(($hero['season'] ? 'Seizoen ' . $hero['season'] . ' · aflevering ' . $hero['number'] . ' · ' : '') . 'laadt YouTube') ?></small></span>
      </a>
<?php else: ?>
      <a class="feature" href="<?= e($hero['link'] ?: $s['nav']['cta']['url']) ?>" <?= $ext ?><?php if ($hero['audio']): ?> data-audio="<?= e($hero['audio']) ?>" data-title="<?= e($hero['head']) ?>"<?php endif ?> aria-label="<?= e('Nu te beluisteren: ' . $hero['title']) ?>">
        <img src="<?= e($s['images']['fallback']) ?>" alt="" width="640" height="360" fetchpriority="high">
        <span class="ring" aria-hidden="true"></span>
        <span class="meta"><b>Nu te beluisteren</b><small><?= e($hero['season'] ? 'Seizoen ' . $hero['season'] . ' · aflevering ' . $hero['number'] : $hero['title']) ?></small></span>
      </a>
<?php endif ?>
    </div>
  </section>

  <section class="stats gutter" aria-label="<?= e($s['site']['name']) ?> in cijfers">
    <div class="inner tiles">
<?php foreach ($s['stats'] as $st): $val = View::fill($st['value'], $t); if (trim($val, 'S~ ') === '') continue; ?>
      <div class="tile"><b><?= e($val) ?><?php if (!empty($st['suffix'])): ?><small><?= e($st['suffix']) ?></small><?php endif ?></b><span><?= e(View::fill($st['label'], $t)) ?></span><div class="pips"><?php foreach (str_split($st['pips'] ?? '') as $p): ?><i<?= $p === 'r' ? ' class="r"' : ($p === 'g' ? ' class="g"' : '') ?>></i><?php endforeach ?></div></div>
<?php endforeach ?>
    </div>
  </section>
<?php endif ?>

  <section class="sec gutter" id="afleveringen" aria-labelledby="h-eps">
    <div class="inner">
      <div class="sec-head"><?= $h2($s['episodes'], 'h-eps') ?><a href="<?= e($s['episodes']['all_url']) ?>" <?= $ext ?>><?= e($s['episodes']['all_label']) ?></a></div>
<?php if ($v['recent']): ?>
      <div class="eps">
<?php foreach ($v['recent'] as $ep): ?>
        <a class="ep" href="<?= e($ep['link'] ?: $s['episodes']['all_url']) ?>" <?= $ext ?>>
          <span class="th"><img src="<?= e($img($ep, 480)) ?>" alt="" width="480" height="270" loading="lazy" decoding="async"><?php if ($ep['duration']): ?><span class="dur"><?= e(View::duration($ep['duration'])) ?></span><?php endif ?></span>
          <span><span class="code"><?= e(implode(' · ', array_filter([$ep['code'], View::shortDate($ep['ts'])]))) ?></span><h3><?= e($ep['card_title']) ?></h3><p><?= e(View::excerpt($ep['description'], 160)) ?></p></span>
        </a>
<?php endforeach ?>
      </div>
<?php elseif (!$hero): ?>
      <p class="empty"><?= e($s['episodes']['empty']) ?></p>
<?php endif ?>
    </div>
  </section>

  <section class="sec gutter" id="aan-tafel" aria-labelledby="h-tafel">
    <div class="inner">
      <div class="sec-head"><?= $h2($s['hosts_title'], 'h-tafel') ?></div>
      <div class="table<?= $v['guests'] ? '' : ' solo' ?>">
        <div class="hosts">
<?php foreach ($s['hosts'] as $h): ?>
          <div class="host"><span class="av"><?= e($h['initials']) ?></span><span><b><?= e($h['name']) ?></b><span><?= e($h['role']) ?></span></span></div>
<?php endforeach ?>
        </div>
<?php if ($v['guests']): ?>
        <div class="guests">
          <h3><?= e($s['guests']['title']) ?></h3>
          <div class="wall">
<?php foreach ($v['guests'] as $g): ?>
            <a href="<?= e($g['link'] ?: $s['episodes']['all_url']) ?>" <?= $ext ?>><?= e($g['name']) ?></a>
<?php endforeach ?>
          </div>
        </div>
<?php endif ?>
      </div>
    </div>
  </section>

<?php if (!empty($s['join']['cards'])): ?>
  <section class="sec gutter" id="meedoen" aria-labelledby="h-mee">
    <div class="inner">
      <div class="sec-head"><?= $h2($s['join'], 'h-mee') ?></div>
      <div class="join">
<?php foreach ($s['join']['cards'] as $c): ?>
        <a class="jc<?= !empty($c['style']) ? ' ' . e($c['style']) : '' ?>" href="<?= e($c['url']) ?>" <?= $ext ?>>
<?php if (!empty($c['icon'])): ?>
          <?= Icons::svg($c['icon']) ?>
<?php else: ?>
          <span class="board" aria-hidden="true"></span>
<?php endif ?>
<?php if (!empty($c['tag'])): ?><span class="tag"><?= e($c['tag']) ?></span><?php endif ?><h3><?= e($c['title']) ?></h3><p><?= e($c['text']) ?></p><span class="go"><?= e($c['cta']) ?></span>
        </a>
<?php endforeach ?>
      </div>
    </div>
  </section>
<?php endif ?>

<?php if (!empty($s['sponsors'])): ?>
  <section class="sec gutter" id="partners" aria-labelledby="h-partners">
    <div class="inner">
      <div class="sec-head"><h2 id="h-partners">Met dank <em>aan</em></h2></div>
      <div class="sponsors">
<?php foreach ($s['sponsors'] as $sp): ?>
<?php if (!empty($sp['text'])): ?>
        <a class="partner" href="<?= e($sp['url']) ?>" <?= $ext ?>><?php if (!empty($sp['logo'])): ?><img src="<?= e($sp['logo']) ?>" alt="" loading="lazy" width="64" height="64"><?php endif ?><span><b><?= e($sp['name']) ?></b><small><?= e($sp['text']) ?></small><?php if (!empty($sp['cta'])): ?><em><?= e($sp['cta']) ?></em><?php endif ?></span></a>
<?php else: ?>
        <a href="<?= e($sp['url']) ?>" <?= $ext ?>><?php if (!empty($sp['logo'])): ?><img src="<?= e($sp['logo']) ?>" alt="<?= e($sp['name']) ?>" loading="lazy" height="48"><?php else: ?><?= e($sp['name']) ?><?php endif ?></a>
<?php endif ?>
<?php endforeach ?>
      </div>
    </div>
  </section>
<?php endif ?>

  <section class="sec gutter" id="volg" aria-labelledby="h-volg">
    <div class="inner">
      <div class="sec-head"><?= $h2($s['follow_title'], 'h-volg') ?></div>
      <div class="follow">
        <div class="soc">
<?php foreach ($s['social'] as $so): ?>
          <a href="<?= e($so['url']) ?>" <?= $ext ?>><?= Icons::svg($so['network']) ?><span><b><?= e($so['label']) ?></b><small><?= e($so['handle']) ?></small></span></a>
<?php endforeach ?>
        </div>
        <div class="contact">
          <span><?= e($s['contact']['text']) ?></span>
          <div class="mail"><a id="mail" href="mailto:<?= e($s['contact']['email']) ?>"><?= e($s['contact']['email']) ?></a><button class="copy" id="copy" type="button">Kopieer</button></div>
        </div>
      </div>
    </div>
  </section>
</main>

<footer class="gutter">
  <div class="inner">
    <span class="co"><?= e($s['footer']['tagline']) ?> · <?php foreach ($s['footer']['chips'] as $i => $chip): ?><em<?= $i === count($s['footer']['chips']) - 1 ? ' class="b"' : '' ?>><?= e($chip) ?></em><?php endforeach ?></span>
<?php if (!empty($s['footer']['made'])): $m = $s['footer']['made']; ?>
    <a class="made" href="<?= e($m['url']) ?>" <?= $ext ?>><span><?= e($m['label']) ?></span><img src="<?= e($m['logo']) ?>" alt="<?= e($m['name']) ?>" loading="lazy" width="104" height="12"></a>
<?php elseif (!empty($s['footer']['credit'])): ?>
    <span><?= e($s['footer']['credit']) ?></span>
<?php endif ?>
  </div>
</footer>
<div class="player" id="player" hidden><div class="inner"><span class="pt" id="player-title"></span><audio id="audio" controls preload="none"></audio><button class="copy" id="player-close" type="button" aria-label="Speler sluiten">Sluit</button></div></div>
<script src="assets/site.js?v=<?= e($v['asset_version']) ?>" defer></script>
</body>
</html>
