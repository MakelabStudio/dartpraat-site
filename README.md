# dartpraat-site

Podcastsite uit RSS (template) — eerste klant Dartpraat (Creation HQ). Eén PHP-pagina die de nieuwste aflevering
zelf uit de podcastfeed haalt: geen framework, geen build, geen cron, geen deploy per aflevering. PHP 8.1+.

## Hoe het werkt

```
bezoeker → public/index.php → cache/feed.json (+ youtube.json, thumbs/) → HTML
                                   ↑ verversen ná het antwoord (TTL 15 min, slot, timeout 3 s)
                         anchor.fm-RSS + YouTube-kanaalfeed
```

- **Hero** = nieuwste aflevering, **raster** = de 6 daarna, **gastenmuur** = namen uit de titels
  (`S04E10 - Marvin van Velzen`, `… (Met Niels Zonneveld)`), hosts eruit, nieuwste eerst.
- **Cache** in `public/../cache` (op de server een symlink naar een gedeelde map buiten de release). TTL uit
  `site.json` (`feed.ttl`, 900 s). Stale-while-revalidate: de bezoeker krijgt altijd direct de cache; is die verlopen,
  dan wordt daarna ververst:
  - met PHP-FPM via `fastcgi_finish_request()` in hetzelfde verzoek;
  - anders stuurt de geladen pagina een `sendBeacon` naar `refresh.php`, dat in een eigen verzoek ververst.
  Een slot (`cache/refresh.lock`) voorkomt dubbele fetches; na een fout pas na 60 s opnieuw proberen.
- **Kapotte feed** (timeout, HTTP-fout, geen RSS): de laatste goede cache blijft staan, de fout komt in `status.json`.
- **Koude start** (lege cachemap, alleen bij de allereerste uitrol): één keer synchroon de feed ophalen, max. de
  feed-timeout. Thumbnails komen daarna.
- **YouTube**: kanaalfeed gekoppeld op de code `S04E11` in de titel. Thumbnails worden lokaal gezet
  (`cache/thumbs/<id>-960|480.webp`, via GD; zonder GD de jpg) en geserveerd door `img.php`; geen hotlink. Geen
  match = `assets/img/fallback.webp`. De video laadt pas na een klik, via `youtube-nocookie.com`.
- **Speler**: "Luister nu" speelt de mp3-enclosure in een balk onderaan; zonder JS gewoon de link naar Spotify.
- **Veiligheid**: alles uit de feed gaat door `htmlspecialchars`; URL's alleen `http(s)`; JSON-LD met `JSON_HEX_*`.
  CSP zonder inline scripts (themakleuren via een sha256-hash), `nosniff`, `Referrer-Policy`, `frame-ancestors 'none'`.
- **SEO**: Open Graph (thumbnail van de nieuwste aflevering), schema.org `PodcastSeries` + `PodcastEpisode`,
  `/sitemap.xml`, `/robots.txt`.
- **Bewaking**: `/status.json` → `ok`, laatste feeditem (code, titel, datum), `cache_age_s`, `last_error`, YouTube-stand.

## Een nieuwe podcastsite maken

1. Kopieer de repo, pas `public/site.json` aan: `site` (naam, url, beschrijving), `feed.url`, `youtube.channel_id`
   (leeg = geen YouTube), `theme` (kleuren), `nav`, `listen`, `stats` (tokens `{episodes}`, `{season}`,
   `{avg_duration}`), `hosts`, `guests.not_names` (woorden die geen naam zijn), `join.cards`, `social`, `contact`,
   `sponsors` (`[{name, url, logo?}]`), `footer` (`made: {label, name, logo, url}` of `credit`).
2. Vervang `public/assets/img/` (logo.webp/png, fallback.webp 640×360, og.jpg 1200×630). Fonts staan lokaal in
   `public/assets/fonts/` (geen Google Fonts-verzoek, AVG).
3. `npm test` en kijk lokaal: `PHP=php sh test/serve.sh` → http://127.0.0.1:8080/.

Geen code nodig. Iconen voor `listen`/`social`/`join`: `youtube`, `headphones`, `instagram`, `tiktok`, `x`,
`facebook`, `discord` (`public/_app/view.php`, `Icons`).

## Indeling

| pad | wat |
| --- | --- |
| `public/index.php` | de pagina |
| `public/site.json` | alle inhoud die niet uit de feed komt |
| `public/_app/` | PHP: `boot.php` (config, verversen, headers), `feed.php` (RSS/YouTube, titels), `store.php` (cache, slot, thumbnails), `view.php` (opmaak, JSON-LD, iconen), `template.php`. Niet direct bereikbaar (`.htaccess` + `defined('PODSITE')`) |
| `public/img.php`, `refresh.php`, `sitemap.php`, `robots.php`, `status.php` | thumbnails, beacon-verversen, en de nette URL's via `.htaccess` |
| `public/assets/` | css, js, fonts, beelden |
| `test/` | `run.sh`/`run.php` (PHP-suite), `e2e/` (Playwright WebKit), `feed-server.php` (nagebootste feed), `router.php` (`.htaccess` voor `php -S`) |

## Uitrol (recept `site-publiceer`)

Alleen `public/` gaat de server op (`~/releases/<sha>/public`), met `~/releases/<sha>/cache` → gedeelde cachemap.
Er wordt nergens anders geschreven. Nodig op de server: PHP 8.1+ met `curl`, `simplexml`, `json`, `mbstring`
(GD met WebP aanbevolen voor de thumbnails), Apache met `mod_rewrite` (voor `/sitemap.xml`, `/robots.txt`,
`/status.json`). Op nginx: dezelfde drie rewrites en `/_app/` dicht.

Controle na de uitrol: `/status.json` geeft `"ok": true` en de code van de nieuwste aflevering staat in de HTML.

## Tests

```
npm test                                            # php -l + test/run.php (59 checks)
npm run test:e2e                                    # WebKit 390 en 1440 (installeert Playwright, gebruikt werk-webkit)
```

`npm test` zoekt PHP in `$PHP`, dan `php`, en anders haalt het eenmalig een statische PHP 8.1 op in `~/.cache/podsite-php`.
De suite bewijst: nieuwe aflevering staat na de TTL bovenaan zonder deploy (en de bezoeker wacht niet), kapotte of
trage feed → oude cache, XSS in titel/beschrijving/links wordt geëscaped.
