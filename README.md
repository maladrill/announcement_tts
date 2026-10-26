# Announcements TTS for FreePBX 17

Generate OpenAI TTS recordings, play them in Asterisk, and route calls to a selected FreePBX destination. Generation happens when configuration is saved, outside the call path. Playing existing recordings does not require API access.

## Requirements

- FreePBX 17, PHP 8.2 or 8.3, MariaDB/InnoDB.
- PHP extensions: `curl`, `mbstring`, `pdo_mysql`; available `proc_open`.
- FFmpeg at `/usr/bin/ffmpeg`.
- `ASTVARLIBDIR/sounds/custom` writable by the FreePBX/Asterisk service user.
- HTTPS access to `api.openai.com` and a working system CA certificate store.

Example preparation on Debian 12, as root:

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

Enter the API key into `/etc/asterisk/openai.key` using an editor running as root. The file must contain only the key. Keep credentials out of shell command lines, repositories and issue reports. The module does not include the key in backups. Limit the API account to the required service and configure an appropriate spending limit.

Upload the archive through **Admin → Module Admin → Upload Modules**, then install or upgrade `announcementtts`. Run the standard `fwconsole chown` if directories were created by root.

## Usage

1. Open **Applications → Announcements TTS**.
2. Enter a unique description of up to 50 characters and announcement text of up to 4096 characters.
3. Select a voice supported by `tts-1` and the destination after playback.
4. Save, then select **Apply Config** after successful generation.
5. The edit form provides a WAV preview and an explicit regeneration option.

Each list row includes Edit and Delete actions. Delete asks for confirmation and submits a POST request protected by the current session token. The edit form also provides a Delete button. Apply Config after deleting an announcement.

**Empty or whitespace-only text intentionally disables playback.** It is stored as empty text with no audio file reference. Saving it makes no API call and creates no placeholder WAV, even when regeneration is selected. After Apply Config, the announcement routes directly to its destination without `Playback()`, `Answer()` or `Wait()`, preserving the channel's answer state. Clearing previously populated text disables the old recording after the configuration is applied. Entering text again generates new audio.

The language field is metadata. Speech follows the language of the entered text; this setting does not translate text or force the language through an API parameter.

Supported voices: `alloy`, `ash`, `coral`, `echo`, `fable`, `nova`, `onyx`, `sage`, `shimmer`. The existing `tts-1` model and Speech API workflow are retained; check the provider's current API lifecycle before a future migration.

## Failure handling and security

- Failed generation creates no incomplete record and preserves the previous text, destination and audio reference.
- A failed operation can be retried. Saving unchanged nonempty text also regenerates a missing file.
- Audio is verified as WAV PCM 16-bit, mono, 8000 Hz, then published under a unique filename using atomic `rename()`.
- One save, generation or restore operation can run at a time. A concurrent request receives a busy error.
- GUI mutations require POST and a session token; audio previews require module access.
- User descriptions do not become dialplan code. Destinations are validated server-side.
- Announcement contents and provider error response bodies are not written to module logs.

Retired WAV files are deliberately retained after a change or deletion: the loaded dialplan and calls already in progress may still use an earlier version. The module does not delete them automatically. Module backups include current audio referenced by the database. Account for retired audio in disk retention procedures; do not delete it before a successful reload and completion of active calls.

## Backup and restore

Backups contain every announcement field and its current WAV. Restore preserves IDs and relocates audio to the destination system's `ASTVARLIBDIR`. Intentionally empty announcements require no WAV and produce no missing-audio warning. Stale audio references in their backups are ignored.

Older 17.0.2 backups contain configuration only. For nonempty text, restore reports missing audio and it must be explicitly regenerated. Restore never issues paid API requests.

## GraphQL

Required scopes: `gql:announcementtts:read` and/or `gql:announcementtts:write`.

- `addAnnouncementtts`: `description`, `text`, `post_dest`; optional `language`, `voice`.
- `updateAnnouncementtts`: the same fields plus `announcementtts_id`; optional `regenerate`.
- `removeAnnouncementtts`: numeric `id`.
- Queries: `announcementtts(id: ...)`, `allAnnouncementstts`.

The `text` field must be supplied, but an empty string is valid and disables playback. ID arguments use the numeric `announcementtts_id`, not the encoded Relay `id`. Fields inherited from the conventional recording module (`recording_id`, `allow_skip`, `return_ivr`, `noanswer`, `repeat_msg`) are not supported. GraphQL changes mark the FreePBX configuration as needing a reload.

## Tests

```bash
php tests/run.php
node tests/list-actions.js
```

Tests require `pdo_sqlite`, `mbstring` and FFmpeg. They use a temporary SQLite database and FreePBX/API doubles; no API key or OpenAI requests are needed. CI runs the checks on PHP 8.2 and 8.3.

The offline suite also renders the actual list, add and edit templates with FreePBX-style request values, including the `false` sentinel for a missing `extdisplay`. Malformed edit IDs remain invalid and are displayed as handled errors.

See [TESTING.md](TESTING.md) for tests against a real PBX, MariaDB and the GraphQL library. License: GPLv3+; see [LICENSE](LICENSE).

The CI installation test additionally executes `install.php` against an isolated MariaDB schema and verifies subsequent FreePBX-style module registration. Installer variables are scoped inside a closure to preserve FreePBX's legacy global database wrapper and its escaping contract.
