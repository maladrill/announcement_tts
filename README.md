# Announcements TTS for FreePBX 17

Generate OpenAI TTS recordings, play them in Asterisk, and route calls to a selected FreePBX destination. Generation happens when configuration is saved, outside the call path. Playing existing recordings does not require API access.

## Requirements

- FreePBX 17, PHP 8.2 or 8.3, MariaDB/InnoDB.
- PHP extensions: `curl`, `mbstring`, `pdo_mysql`, `openssl`; available `proc_open` and `stream_socket_client`.
- FFmpeg at `/usr/bin/ffmpeg`.
- `ASTVARLIBDIR/sounds/custom` writable by the FreePBX/Asterisk service user.
- Outbound TLS on port 443 to `api.openai.com`, including WebSocket upgrades for Realtime API, and a working system CA certificate store. No additional Python, Node.js or Composer dependency is needed on the PBX.

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

## OpenAI API key

Enter the API key into `/etc/asterisk/openai.key` using an editor running as root. The file must contain only the key.

**Required ownership: `root:asterisk`. Required permissions: `0640` (`-rw-r-----`).** The owner `root` can read and write the file, the `asterisk` group can read it, and other users have no access. The PHP/web service account must be able to read the file; on a standard FreePBX installation this account is `asterisk`.

After saving the key, run as root:

```bash
chown root:asterisk /etc/asterisk/openai.key
chmod 0640 /etc/asterisk/openai.key

# Show ownership and permissions without displaying the key.
stat -c '%A (%a) %U:%G %n' /etc/asterisk/openai.key

# Verify readability as the FreePBX service account without reading or displaying the key.
runuser -u asterisk -- php -r '
clearstatcache(true, "/etc/asterisk/openai.key");
$readable = is_readable("/etc/asterisk/openai.key");
echo $readable ? "OK: key file is readable\n" : "ERROR: key file is not readable\n";
exit($readable ? 0 : 1);
'
```

Expected file metadata: `-rw-r----- (640) root:asterisk /etc/asterisk/openai.key`. If the readability check fails, check the actual PHP service account and directory traversal permissions. Mode `0640` with ownership `root:root` does not allow the standard `asterisk` account to read the key. Do not grant access to other users with modes such as `0644` or `0777`.

Keep credentials out of shell command lines, repositories and issue reports. The module does not include the key in backups. Limit the API account to the required service and configure an appropriate spending limit.

## Module installation

Upload the archive through **Admin → Module Admin → Upload Modules**, then install or upgrade `announcementtts`. Run the standard `fwconsole chown` if directories were created by root.

## Usage

1. Open **Applications → Announcements TTS**.
2. Enter a unique description of up to 50 characters and announcement text of up to 4096 characters.
3. Select **TTS Engine** above **Language**: **tts-1** or **Realtime API**. New records default to Realtime API and the Marin voice. Choose a voice and the destination after playback.
4. Save, then select **Apply Config** after successful generation.
5. The edit form provides a WAV preview and an explicit regeneration option.

Each list row includes icon-only Edit and Delete actions matching the standard Announcements module. Tooltips and accessible labels identify both actions. Delete asks for confirmation and submits a POST request protected by the current session token. The edit form also provides a Delete button. Apply Config after deleting an announcement.

**Empty or whitespace-only text intentionally disables playback.** It is stored as empty text with no audio file reference. Saving it makes no API call and creates no placeholder WAV, even when regeneration is selected. After Apply Config, the announcement routes directly to its destination without `Playback()`, `Answer()` or `Wait()`, preserving the channel's answer state. Clearing previously populated text disables the old recording after the configuration is applied. Entering text again generates new audio.

The language field is metadata. Speech follows the language of the entered text; this setting does not translate text or force the language through an API parameter.

The voice selector updates immediately when the engine changes. A voice available in both engines remains selected; otherwise the selection becomes Marin for Realtime API or Fable for tts-1. Switching engine regenerates nonempty audio on save, even when the voice exists in both engines. Merely changing the selector does not call OpenAI.

| Engine | Model and transport | Voices |
| --- | --- | --- |
| tts-1 | `tts-1`, HTTPS Speech API | alloy, ash, coral, echo, fable, nova, onyx, sage, shimmer |
| Realtime API | `gpt-realtime-2.1-mini`, authenticated server-side WebSocket | alloy, ash, ballad, coral, echo, sage, shimmer, verse, marin, cedar |

Both engines generate a local recording at save time. Realtime is used to produce that recording, with no API connection during calls. It requires model access on the API project associated with the same protected key file. Realtime requests instruct the model to read the text verbatim without answering or translating. As a generative audio model, it can still depart from the supplied text: preview the full recording, including numbers and abbreviations, before Apply Config. Incomplete, refused or failed responses are rejected and the previous saved audio is preserved.

Version 17.0.9 adds engine selection. Upgrading assigns `tts-1` to existing rows, preserving their voices, text and WAV references without API requests. Backups without an engine field are also treated as `tts-1`. The new-record default is applied by the form/application; the database column retains a legacy `tts-1` default for compatibility.

Official API references: [Realtime conversations](https://developers.openai.com/api/docs/guides/realtime-conversations), [WebSocket connections](https://developers.openai.com/api/docs/guides/voice-websockets), [client events](https://developers.openai.com/api/reference/resources/realtime/client-events), [server events](https://developers.openai.com/api/reference/resources/realtime/server-events).

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

- `addAnnouncementtts`: `description`, `text`, `post_dest`; optional `language`, `voice`, `backend` (`tts-1` or `realtime`). New records default to `realtime`/`marin`.
- `updateAnnouncementtts`: the same fields plus `announcementtts_id`; optional `regenerate`. Omitting `backend` on update preserves the saved engine. Omitting `voice` preserves the saved voice for the same engine, or selects the default voice for a newly selected engine.
- `removeAnnouncementtts`: numeric `id`.
- Queries: `announcementtts(id: ...)`, `allAnnouncementstts`.

The `text` field must be supplied, but an empty string is valid and disables playback. ID arguments use the numeric `announcementtts_id`, not the encoded Relay `id`. Fields inherited from the conventional recording module (`recording_id`, `allow_skip`, `return_ivr`, `noanswer`, `repeat_msg`) are not supported. GraphQL changes mark the FreePBX configuration as needing a reload.

## Tests

```bash
php tests/run.php
node tests/list-actions.js
```

Tests require `pdo_sqlite`, `mbstring` and FFmpeg. Realtime fixtures cover event sequencing, chunk assembly, failed/incomplete responses, actual WebSocket framing/handshakes and FFmpeg conversion. They use a temporary SQLite database and FreePBX/API doubles; no API key or OpenAI requests are needed. CI runs the checks on PHP 8.2 and 8.3.

The offline suite also renders the actual list, add and edit templates with FreePBX-style request values, including the `false` sentinel for a missing `extdisplay`. Malformed edit IDs remain invalid and are displayed as handled errors.

See [TESTING.md](TESTING.md) for tests against a real PBX, MariaDB and the GraphQL library. License: GPLv3+; see [LICENSE](LICENSE).

The CI installation test additionally executes `install.php` against an isolated MariaDB schema and verifies subsequent FreePBX-style module registration. Installer variables are scoped inside a closure to preserve FreePBX's legacy global database wrapper and its escaping contract.
