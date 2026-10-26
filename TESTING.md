# Integration tests for 17.0.9

## Preparation

Use an isolated FreePBX 17 PBX on Debian 12/PHP 8.2. Take a VM/CT snapshot before installation. The 17.0.2 module restore implementation is defective, so its own backup is not a replacement for a snapshot. Do not run these tests on a customer PBX.

1. Install prerequisites and prepare the protected key file as described in README.md.
2. Install the `fix/freepbx17-tts-reliability` branch through Module Admin. Archive: `https://github.com/maladrill/announcement_tts/archive/refs/heads/fix/freepbx17-tts-reliability.zip`.
3. Run `fwconsole chown`, then `fwconsole reload`. Confirm version 17.0.9 in Module Admin.
4. Prepare a test extension and an inbound route to the announcement. Restrict PBX administration to the management network.

## Scenarios

| Test | Expected result |
| --- | --- |
| Module landing page, then Add (`?display=announcementtts&view=form`) | The empty add form opens without an ID; FreePBX's `extdisplay=false` sentinel must not trigger an invalid-ID exception. |
| Edit with a malformed or nonexistent ID | A handled error message; no uncaught exception and no database change. |
| Fresh installation or retry after the 17.0.4 registration failure | Module Admin completes registration without SQLSTATE 42000 / 1064; existing announcement rows are preserved. |
| New Polish announcement with tts-1 / `fable` | Save succeeds, preview plays valid WAV, and after Apply Config a call hears the full recording and reaches the selected extension. |
| Change description/destination only | No additional TTS request; the new destination is used after Apply Config. |
| New record engine selector | Above Language, with Realtime API selected and Marin as the default voice. |
| New Polish announcement with Realtime API / `marin`, then `cedar` | Both saves create valid local WAV files. Preview the entire recording for verbatim text, numbers and abbreviations; after Apply Config calls play locally without API access. |
| Switch tts-1 / Realtime API before saving | Voice options update immediately. Fable/Nova/Onyx appear only for tts-1; Ballad/Verse/Marin/Cedar only for Realtime. Shared voices stay selected; incompatible voices switch to the engine default. No API request is made until Save. |
| Switch engine with shared voice Alloy and unchanged text | Save still generates a replacement WAV. |
| Reset after switching engine | The saved/default engine and corresponding original voice return together. |
| Upgrade existing tts-1 records | Their engine, voice, text and audio remain unchanged, with no generation requests. |
| Realtime model unavailable, quota exhausted, blocked WebSocket or incomplete response | Visible error, original engine/text/voice/audio preserved; retry after resolving the problem. No automatic fallback or second provider request. |
| Empty text with either engine, including engine switches | No API request or WAV; the call routes directly to the selected destination. |
| Change text or voice | A new file and database reference; the previous WAV remains available to the loaded dialplan. |
| Explicit regeneration | A new file even with identical nonempty text. |
| Refresh after successful save | POST redirects to the list; refresh does not repeat generation. |
| Unreadable key during an edit | Visible error; previous row and audio remain; new entered text stays in the form. |
| Retry after fixing the error | Save succeeds without another text change. |
| Missing WAV with nonempty text | Saving unchanged fields regenerates audio. |
| New announcement with empty text | Save succeeds without API access or a WAV; after Apply Config the call immediately reaches the selected destination. |
| Whitespace-only text | Same behavior as empty text; stored text is normalized to an empty string. |
| Clear an existing recording's text | Audio reference becomes empty; after Apply Config the old recording is skipped. No Answer, Wait or Playback is emitted for this announcement. |
| Empty text with regeneration selected | No API call, no WAV, and direct routing is preserved. |
| Replace empty text with nonempty text | New audio is generated; after Apply Config normal playback resumes. |
| Two simultaneous saves | The second request reports busy; text and audio do not become mixed. |
| Description over 50 characters, text over 4096, nonexistent destination | Rejected server-side even when browser validation is bypassed. |
| Delete using the form | Row disappears and Apply Config is required; GET and tokenless POST do not delete it. |
| Delete directly from the list | Every row shows only the pencil and trash icons, with tooltips/accessibility labels and no visible Delete text or button frame; Cancel leaves the record unchanged, confirmation submits a token-protected POST for that row and returns to the list. |
| Audio preview without module permission | Access denied; no WAV content returned. |
| Change a destination globally in FreePBX | `post_dest` reference is updated correctly. |
| Description containing `${LEN(test)}` | Dialplan contains only `AnnouncementTTS ID <number>`, never the user description. |

To simulate an unreadable key on TEST, set `/etc/asterisk/openai.key` to mode `0600` with root ownership, attempt a GUI save, then restore mode `0640`. Never send the key file contents. An empty-text save must succeed in this state because it needs no API request.

Inspect the dialplan as root, using the numeric ID shown in the edit URL:

```bash
fwconsole reload
asterisk -rx 'dialplan show app-announcementtts-1'
```

Replace `1` with the actual ID. For an empty announcement expect a constant NoOp and Goto only, preserving the incoming channel's answer state. Test it both from an unanswered inbound route and from an already answered IVR.

For nonempty text, the selected WAV must report `pcm_s16le`, `sample_rate=8000`, `channels=1`:

```bash
ffprobe -v error -select_streams a:0 \
    -show_entries stream=codec_name,sample_rate,channels,bits_per_sample \
    -of default=noprint_wrappers=1 /var/lib/asterisk/sounds/custom/announcementtts_1.wav
```

Use the actual database file path; newly generated filenames have a random suffix.

## Backup/restore

1. Create two audible announcements using different engines, voices, texts and destinations and a third with empty text.
2. Run a FreePBX Backup job including Announcements TTS.
3. Restore to another isolated TEST PBX with the destination modules installed. The API key is not needed to restore or play existing audio, or to route an intentionally empty announcement.
4. Verify IDs, text, language, engine, voice, destinations and both recordings. Run Apply Config and place calls through all three announcements. The empty announcement must produce no missing-audio warning.
5. A 17.0.2 configuration-only backup should warn about missing files for nonempty text; generating them requires an explicit save and a key.

## GraphQL

Use GraphQL Explorer with `gql:announcementtts:write` and separately `gql:announcementtts:read`. The example destination `app-blackhole,hangup,1` must exist on the PBX.

```graphql
mutation {
  addAnnouncementtts(input: {
    description: "GraphQL test"
    text: "This is a test announcement."
    language: "en"
    backend: "realtime"
    voice: "marin"
    post_dest: "app-blackhole,hangup,1"
  }) {
    announcementtts { announcementtts_id description text language backend voice post_dest }
  }
}
```

Use the returned numeric ID for `updateAnnouncementtts` and `removeAnnouncementtts`. Verify the returned update ID, destination changes, reload notification and deletion. Repeat create/update using `text: ""`; it must save without a provider call, including with `regenerate: true`. Operations must be unavailable without the appropriate scope. Also verify resolving `app-announcementtts-ID,s,1` through `destinationConnection`.

## Realtime offline checks and live validation

The automated suite uses provider fixtures and Unix socket pairs, never API credentials. It covers GA response events, exact source text and chosen voice in the request, PCM chunk order, response status, disconnects, malformed payloads, deadline/size limits, WebSocket masking/fragmentation/ping handling, engine-specific form rendering and real FFmpeg conversion. These checks do not establish live project access, proxy compatibility or pronunciation. Complete both engine scenarios on the PBX with the real protected key before merging.

## Results to report

CI also runs `php tests/install.php` against an isolated MariaDB schema on PHP 8.2 and 8.3. It reproduces the legacy database-wrapper replacement failure, then verifies fresh installation, migration from MyISAM, version registration and repeated installation. These tests use Unix-socket authentication and refuse to modify any schema except `announcementtts_install_test`.

Include FreePBX, PHP, Asterisk and FFmpeg versions, the branch commit and results for each scenario. For failures, include the timestamp, record ID and a redacted log excerpt. Never send API keys, tokens, confidential announcement contents or customer information.

To revert the tests, restore the preinstallation snapshot. Keep the PR unmerged until PBX integration tests have passed.
