#!/bin/sh
# Testsuite: php -l op alles, daarna test/run.php. Zoekt PHP in $PHP, dan `php` op PATH, anders een statische PHP 8.1
# (static-php-cli) in ~/.cache/podsite-php (eenmalig downloaden; de server draait 8.1+).
# Met een adres alleen de rooktest, alleen-lezen (GET) tegen die site, bv. na een uitrol:  sh test/run.sh https://dartpraat.nl
set -e
cd "$(dirname "$0")/.."
if [ -z "$PHP" ]; then
  if command -v php >/dev/null 2>&1; then PHP=php
  else
    PHP="$HOME/.cache/podsite-php/php"
    if [ ! -x "$PHP" ]; then
      mkdir -p "$HOME/.cache/podsite-php"
      curl -sSfL https://dl.static-php.dev/static-php-cli/bulk/php-8.1.34-cli-linux-x86_64.tar.gz | tar xz -C "$HOME/.cache/podsite-php"
    fi
  fi
fi
"$PHP" -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || { echo "PHP 8.1+ nodig"; exit 1; }
case "$1" in http://*|https://*) exec "$PHP" test/smoke.php "$@" ;; esac
n=0
for f in $(find public test -name '*.php'); do "$PHP" -l "$f" >/dev/null || exit 1; n=$((n+1)); done
echo "ok  php -l: $n bestanden ($("$PHP" -r 'echo PHP_VERSION;'))"
exec "$PHP" test/run.php
