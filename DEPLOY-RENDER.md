# Deploy on Render (SwissYGO #84 — deck images)

This fork adds **`GET /deck-image`** (see `public/deck-image.php`): it renders the deck
image via the existing `/imageify`, uploads it to **Supabase Storage** under a
deterministic `sha256(deck).png` key, and returns the **public URL** as JSON —
idempotent (existing object → returned without re-rendering). The Supabase key lives
ONLY here; the SwissYGO Raspberry Pi only ever stores the returned URL.

## Render setup
1. **New → Web Service** → connect the repo **`AriesYL/omega-api-decks`** → it detects the
   `Dockerfile` (Docker runtime) → **Free** plan.
2. Health check path: **`/`** (returns 200).
3. Render injects **`PORT`**; the entrypoint binds Apache to it automatically (no action).
4. Set the environment variables below → Create.

> On boot the container downloads the card database (`update-database --database`) since
> free disks are ephemeral; card **images** are fetched on demand per request (we never run
> `populate-cache`, which would pull GBs).

## Environment variables (Render → Environment)
| Var | Value | Notes |
|---|---|---|
| `DATABASE_URL` | `https://raw.githubusercontent.com/mycard/ygopro-database/master/locales/en-US/cards.cdb` | YGOPro **`cards.cdb`** from the actively-maintained MyCard DB (single file, ~7.9MB, updated regularly). Downloaded on boot. |
| `CARD_IMAGE_URL` | `https://images.ygoprodeck.com/images/cards` | Recommended public card-image source. `{CARD_IMAGE_URL}/{passcode}.{ext}`. (Low volume + on-demand cache; if rate-limited, self-host the images.) |
| `CARD_IMAGE_URL_EXT` | `jpg` | |
| `REQUEST_TOKEN` | a long random string | The SwissYGO host (#102) sends `?token=` with it; `/deck-image` and `/imageify` require it. |
| `REQUEST_TOKEN_IN_UI` | `false` | Don't leak the token in the debug UI. |
| `SUPABASE_URL` | `https://<ref>.supabase.co` | From #101. |
| `SUPABASE_SERVICE_KEY` | `sb_secret_…` | From #101 — **secret**, server-side only. |
| `SUPABASE_BUCKET` | `deck-images` | Public bucket from #101. |

`PORT` is set by Render automatically — do not set it.

## Contract (`/deck-image`)
```
GET /deck-image?token=<REQUEST_TOKEN>&list=<deck>     # also: ydke|omega|ydk|names|json ; optional &quality=
→ 200 { "success": true, "data": { "url": "https://<ref>.supabase.co/storage/v1/object/public/deck-images/<sha256>.png", "cached": <bool> } }
→ 4xx/5xx via the standard Http::fail JSON on token/render/storage errors
```
- **Idempotent:** same deck string ⇒ same key ⇒ if already stored, returned with `cached:true` (no render/upload).
- **Pre-warm:** the free service spins down after ~15 min idle; the SwissYGO "Mis Decks" UI should ping `/` (or `/detect`) when opened so the container wakes before the user submits.

## Notes on the card database
`DATABASE_URL` points at MyCard's `en-US/cards.cdb` (raw GitHub), a single ~7.9MB file
re-downloaded on each cold start.

**DB recency does NOT gate image rendering for ydke/omega decks** (what the SwissYGO host
sends). Verified in code: `YdkeFormatDecoder` extracts passcodes straight from the deck
string (`base64` → `unpack("V*")`, no DB), and `ImageCache` fetches each image by passcode
via `CARD_IMAGE_URL` (YGOProDeck) — it never queries the `.cdb`. So a brand-new card still
renders even if the `.cdb` lags, because its passcode comes from the deck string. The `.cdb`
only matters for **name-based** input + `/parse` `/detect` `/convert`. Dueling Book is not a
source (closed format, no `.cdb`).
