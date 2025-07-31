#!/usr/bin/env bash
set -e

# 1) przejdź do katalogu i18n
cd /var/www/html/admin/modules/announcementtts/i18n

# 2) z dowolnego .pot w i18n/ zrób announcementtts.pot
POT=$(ls *.pot | head -1)
echo "1) RENAMING POT: $POT → announcementtts.pot"
mv "$POT" announcementtts.pot

# 3) rekurencyjnie po wszystkich .po i .mo w podkatalogach:
find . -type f \( -iname '*.po' -o -iname '*.mo' \) ! -name announcementtts.* \
  -execdir bash -c 'ext="${1##*.}"; echo "  ➜ RENAMING $1 → announcementtts.$ext"; mv "$1" announcementtts."$ext"' _ {} \;

# 4) w każdym katalogu gdzie mamy announcementtts.po, zrób nowy .mo
find . -type f -name announcementtts.po \
  -execdir bash -c 'echo "  ➜ COMPILING $PWD/announcementtts.po"; msgfmt announcementtts.po -o announcementtts.mo' \;

# 5) reload FreePBX
echo "5) RELOADING FreePBX permissions/cache…"
fwconsole chown && fwconsole reload

echo "✅ Gotowe — wszystkie .po/.mo w LC_MESSAGES zostały przemianowane na announcementtts.po/.mo"
