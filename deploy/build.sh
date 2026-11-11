#!/usr/bin/env bash
# Собирает папку dist/ — ровно то, что нужно залить в корень сайта на REG.RU.
# Служебные файлы (README, HANDOVER, Apps Script, тесты, шаблон конфига) в веб-корень не попадают.
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf dist && mkdir -p dist/api
cp index.html house.html what_to_do.html booking.html contacts.html .htaccess dist/
cp -r assets dist/
cp api/*.php api/.htaccess dist/api/
echo "dist/ готов:"; find dist -type f | sort
