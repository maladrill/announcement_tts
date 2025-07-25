#!/usr/bin/env bash

# 1. Znajdź wszystkie pliki i katalogi zawierające announcement/Announcement
find . -depth \( -name '*Announcement*' -o -name '*announcement*' \) -print0 |
# 2. Dla każdego z nich policz nową nazwę i wykonaj 'git mv'
while IFS= read -r -d '' path; do
  dir=$(dirname "$path")
  base=$(basename "$path")
  # zamiana obu wariantów
  newbase=$(echo "$base" \
    | sed -e 's/Announcement/Announcementtts/g' \
          -e 's/announcement/announcementtts/g')
  # jeśli nazwa się zmieniła, przenieś
  if [ "$base" != "$newbase" ]; then
    git mv "$path" "$dir/$newbase"
  fi
done
