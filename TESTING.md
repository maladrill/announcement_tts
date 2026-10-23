# Testy integracyjne 17.0.3

## Przygotowanie

Użyj wydzielonej centrali FreePBX 17 na Debian 12/PHP 8.2. Przed instalacją wykonaj snapshot VM/CT. Dotychczasowy mechanizm przywracania modułu 17.0.2 jest wadliwy, więc jego własna kopia nie zastępuje snapshotu. Nie testuj na centrali obsługującej klientów.

1. Zainstaluj wymagania i przygotuj chroniony plik klucza zgodnie z README.md.
2. Zainstaluj wersję z gałęzi `fix/freepbx17-tts-reliability` przez Module Admin. Archiwum: `https://github.com/maladrill/announcement_tts/archive/refs/heads/fix/freepbx17-tts-reliability.zip`.
3. Wykonaj `fwconsole chown`, następnie `fwconsole reload`. Zweryfikuj wersję w Module Admin.
4. Przygotuj testowy numer wewnętrzny i trasę do komunikatu. Ogranicz dostęp do centrali do sieci administracyjnej.

## Scenariusze

| Test | Oczekiwany wynik |
| --- | --- |
| Nowy komunikat po polsku, głos `fable` | Zapis sukcesem, poprawny WAV w podglądzie, po Apply Config pełne nagranie i przejście do wskazanego numeru. |
| Edycja wyłącznie opisu i celu | Brak nowego żądania TTS; zmiana celu zaczyna działać po Apply Config. |
| Zmiana tekstu lub głosu | Nowy plik i nowa ścieżka w bazie; poprzedni plik pozostaje dostępny do przeładowania. |
| Jawne ponowne generowanie | Nowy plik mimo identycznego tekstu. |
| Zapis po odświeżeniu przeglądarki | Po udanym POST następuje przekierowanie do listy; odświeżenie nie generuje kolejnego nagrania. |
| Błąd odczytu klucza przy edycji | Widoczny błąd, zachowany poprzedni rekord i dźwięk; wpisany nowy tekst pozostaje w formularzu. |
| Ponowienie po usunięciu przyczyny błędu | Poprawny zapis bez konieczności dalszej zmiany tekstu. |
| Brakujący plik WAV | Ponowny zapis niezmienionych pól odtwarza nagranie. |
| Dwa równoczesne zapisy | Drugie żądanie zgłasza zajętość; brak pomieszania tekstów i nagrań. |
| Opis 51 znaków, tekst 4097 znaków, pusty tekst, nieistniejący cel | Odrzucenie po stronie serwera, także po pominięciu walidacji przeglądarki. |
| Usunięcie przez formularz | Rekord znika i pojawia się wymaganie Apply Config; GET ani POST bez tokenu nie usuwają rekordu. |
| Podgląd audio jako administrator bez dostępu do modułu | Odmowa dostępu, brak treści WAV. |
| Zmiana celu globalnie w FreePBX | Referencja `post_dest` zostaje poprawnie zaktualizowana. |
| Opis zawierający `${LEN(test)}` | Dialplan zawiera wyłącznie `AnnouncementTTS ID <numer>`, bez treści opisu. |

Symulacja braku klucza na TEST: zmień uprawnienia `/etc/asterisk/openai.key` na `0600` przy właścicielu root, wykonaj zapis w GUI, następnie przywróć `0640`. Nie przesyłaj zawartości pliku.

Weryfikacja dialplanu jako root, dla ID widocznego w adresie edycji:

```bash
fwconsole reload
asterisk -rx 'dialplan show app-announcementtts-1'
```

Zastąp `1` rzeczywistym ID. Docelowy WAV powinien mieć: `pcm_s16le`, `sample_rate=8000`, `channels=1`. Sprawdź wybrany plik:

```bash
ffprobe -v error -select_streams a:0 \
    -show_entries stream=codec_name,sample_rate,channels,bits_per_sample \
    -of default=noprint_wrappers=1 /var/lib/asterisk/sounds/custom/announcementtts_1.wav
```

Ścieżkę zastąp aktualnym plikiem z rekordu; nowe nagrania mają losowy sufiks.

## Backup/restore

1. Utwórz dwa komunikaty z różnymi tekstami i celami.
2. W module Backup wykonaj kopię obejmującą Announcements TTS.
3. Przywróć ją na drugiej odizolowanej centrali TEST z zainstalowanymi modułami celów. Plik klucza nie jest wymagany do odtworzenia ani odsłuchu istniejących nagrań.
4. Sprawdź ID, teksty, języki, głosy, cele i oba nagrania. Wykonaj Apply Config i połączenia.
5. Dla starej kopii konfiguracji z 17.0.2 oczekuj ostrzeżeń o brakujących plikach; generowanie wymaga jawnego zapisu i klucza.

## GraphQL

Użyj GraphQL Explorer z zakresem `gql:announcementtts:write` i oddzielnie `gql:announcementtts:read`. W przykładzie cel `app-blackhole,hangup,1` musi istnieć na centrali.

```graphql
mutation {
  addAnnouncementtts(input: {
    description: "Test GraphQL"
    text: "Dzień dobry. Test komunikatu."
    language: "pl"
    voice: "fable"
    post_dest: "app-blackhole,hangup,1"
  }) {
    announcementtts { announcementtts_id description text language voice post_dest }
  }
}
```

Otrzymane numeryczne ID wykorzystaj do `updateAnnouncementtts` i `removeAnnouncementtts`. Sprawdź zwracany ID aktualizacji, poprawną zmianę celu, sygnalizację Apply Config i usuwanie. Bez odpowiedniego zakresu operacje nie mogą być dostępne. Potwierdź także rozwiązywanie celu `app-announcementtts-ID,s,1` przez pole `destinationConnection`.

## Wyniki do przekazania

Podaj wersje FreePBX, PHP, Asterisk i FFmpeg, commit gałęzi oraz wynik każdego scenariusza. Przy błędzie dołącz czas wystąpienia, ID rekordu i zanonimizowany fragment logu. Nie przekazuj kluczy, tokenów, treści poufnych komunikatów ani danych klientów.

Powrót do stanu sprzed testów: przywróć wykonany wcześniej snapshot. Nie scalaj zmiany z `main`, dopóki testy centrali nie zostaną zakończone.
