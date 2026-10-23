# Announcements TTS dla FreePBX 17

Moduł generuje nagranie OpenAI TTS, odtwarza je w Asterisk i przekazuje połączenie do wybranego celu FreePBX. Generowanie odbywa się podczas zapisu konfiguracji, poza obsługą połączeń. Odtwarzanie istniejących nagrań nie wymaga dostępu do API.

## Wymagania

- FreePBX 17, PHP 8.2 lub 8.3, MariaDB/InnoDB.
- Rozszerzenia PHP: `curl`, `mbstring`, `pdo_mysql`; dostępne `proc_open`.
- FFmpeg w `/usr/bin/ffmpeg`.
- Katalog `ASTVARLIBDIR/sounds/custom` zapisywalny dla użytkownika FreePBX/Asterisk.
- Połączenie HTTPS do `api.openai.com` i poprawny systemowy magazyn certyfikatów CA.

Przykładowe przygotowanie Debian 12, jako root:

```bash
apt-get update
apt-get install --no-install-recommends ffmpeg php8.2-curl php8.2-mbstring

# Create the file only when it does not already exist.
if [ ! -e /etc/asterisk/openai.key ]; then
    install -o root -g asterisk -m 0640 /dev/null /etc/asterisk/openai.key
fi
chown root:asterisk /etc/asterisk/openai.key
chmod 0640 /etc/asterisk/openai.key
```

Wprowadź klucz API do `/etc/asterisk/openai.key` za pomocą edytora uruchomionego jako root. Plik ma zawierać wyłącznie klucz. Nie umieszczaj go w poleceniu powłoki, repozytorium ani zgłoszeniu. Moduł nie dodaje klucza do kopii zapasowej. Konto API powinno mieć dostęp wyłącznie do wymaganej usługi i odpowiedni limit wydatków.

Zainstaluj archiwum przez **Admin → Module Admin → Upload Modules**, następnie zainstaluj lub zaktualizuj `announcementtts`. W przypadku utworzenia katalogów przez root wykonaj standardowe `fwconsole chown`.

## Użycie

1. Otwórz **Applications → Announcements TTS**.
2. Podaj unikalny opis do 50 znaków i tekst od 1 do 4096 znaków.
3. Wybierz głos obsługiwany przez `tts-1` i cel po odtworzeniu.
4. Zapisz. Po poprawnym wygenerowaniu nagrania wykonaj **Apply Config**.
5. W edycji komunikatu dostępny jest podgląd WAV i opcja jawnego ponownego generowania.

Pole języka jest metadanymi. Model mówi w języku wprowadzonego tekstu; ustawienie nie tłumaczy tekstu ani nie wymusza języka parametrem API.

Głosy: `alloy`, `ash`, `coral`, `echo`, `fable`, `nova`, `onyx`, `sage`, `shimmer`. Model i endpoint zachowują dotychczasowy wybór `tts-1` / Speech API; przed przyszłą migracją dostawcy należy sprawdzić aktualny cykl życia API.

## Zachowanie przy błędach i bezpieczeństwo

- Nieudane generowanie nie tworzy niekompletnego rekordu i nie zmienia poprzedniego tekstu, celu ani ścieżki nagrania.
- Po błędzie można ponowić zapis. Brakujący plik jest generowany także przy niezmienionym tekście.
- Audio jest weryfikowane jako WAV PCM 16-bit, mono, 8000 Hz i publikowane pod nową nazwą przez atomowe `rename()`.
- Jeden zapis/generowanie/przywracanie na danej centrali może działać jednocześnie. Drugie żądanie otrzyma komunikat o zajętości.
- Mutacje formularza wymagają POST i tokenu sesji; podgląd audio wymaga uprawnień do modułu.
- Opisy nie trafiają do kodu dialplanu. Cele są sprawdzane po stronie serwera.
- Treść komunikatu i odpowiedzi błędów dostawcy nie są zapisywane do logów modułu.

Poprzednie pliki WAV są celowo zachowywane po zmianie lub usunięciu rekordu: załadowany dialplan i rozpoczęte połączenia mogą nadal korzystać z wcześniejszej wersji. Moduł nie usuwa ich automatycznie. Do kopii modułu trafiają aktualne nagrania wskazane w bazie. Retencję poprzednich wersji należy uwzględnić w utrzymaniu przestrzeni dyskowej; nie należy usuwać ich przed udanym przeładowaniem i zakończeniem trwających połączeń.

## Kopie zapasowe

Format kopii zawiera wszystkie pola rekordu oraz aktualne pliki WAV. Przywracanie zachowuje ID i dostosowuje ścieżki do `ASTVARLIBDIR` na docelowej centrali.

Starsze kopie wersji 17.0.2 zawierały jedynie konfigurację. Przy ich odtwarzaniu moduł zgłasza brak nagrania; należy jawnie wygenerować je ponownie. Przywracanie nie wykonuje płatnych żądań API.

## GraphQL

Wymagane zakresy: `gql:announcementtts:read` i/lub `gql:announcementtts:write`.

- `addAnnouncementtts`: `description`, `text`, `post_dest`; opcjonalnie `language`, `voice`.
- `updateAnnouncementtts`: te same pola oraz `announcementtts_id`; opcjonalnie `regenerate`.
- `removeAnnouncementtts`: numeryczne `id`.
- Zapytania: `announcementtts(id: ...)`, `allAnnouncementstts`.

Argumenty ID przyjmują numeryczne ID z `announcementtts_id`, a nie zakodowane Relay `id`. Pola dawnego modułu nagrań (`recording_id`, `allow_skip`, `return_ivr`, `noanswer`, `repeat_msg`) nie są obsługiwane. Zapisy przez GraphQL zaznaczają konieczność przeładowania FreePBX.

## Testy

```bash
php tests/run.php
```

Testy wymagają `pdo_sqlite`, `mbstring` i FFmpeg. Korzystają z tymczasowej bazy SQLite i atrap FreePBX/API; nie potrzebują klucza i nie wykonują żądań do OpenAI. CI uruchamia je na PHP 8.2 i 8.3.

Testy na rzeczywistej centrali, MariaDB i bibliotece GraphQL opisuje [TESTING.md](TESTING.md). Licencja: GPLv3+; zobacz [LICENSE](LICENSE).
