# Testing the Remittance Extractor (Foundation Build)

This covers the current foundation build: no auth, no database persistence —
extraction state lives in the cache for ~1 hour per upload.

The LLM provider is **Groq** (`qwen/qwen3.8-27b`), not Gemini — see
"Why Groq, not Gemini" below.

## Prerequisites

- `.env` has a valid `GROQ_API_KEY` (already set).
- Dependencies installed: `composer install`, `npm install`.
- Frontend built at least once: `npm run build` (or run `npm run dev` in a
  separate terminal while testing, so Blade/JS changes hot-reload).

## 1. Start the app

You need **two processes running at the same time**, in two terminals:

**Terminal A — web server**
```
php artisan serve
```
This serves the app at `http://127.0.0.1:8000`.

**Terminal B — queue worker**
This is required because extraction runs as a queued job, not inline.
```
php artisan queue:work
```
Leave this running. Every upload's LLM call happens here. Without it,
uploads will sit at `status: pending` forever.

With Groq, a document typically completes in 5–10 seconds. If it's still
`processing` after 30+ seconds, something's actually wrong — check
`storage/logs/laravel.log`.

## 2. Upload a document through the browser

1. Go to `http://127.0.0.1:8000/extractions`.
2. Choose **1 to 5 images** (jpg/jpeg/png, up to 10MB each) that together
   make up one payment — a single remittance advice, or multiple pages of
   one, or a voucher plus a separate supporting document like a BIR Form
   2307 that shows the CWT amount. All selected files are sent together as
   **one extraction**; the model is instructed to combine data across them
   rather than treat them as separate documents.
3. The row appears immediately with status `pending`, then flips to
   `processing`, then `completed` (or `failed`) — polled automatically every
   ~2 seconds, no page reload.
4. Once `completed`, click **Review** to open the extraction.

## 3. Review page checks

On `/extractions/{uuid}`:
- All uploaded images for this extraction display on the left, stacked.
- Header fields (payment reference, total amount, deposit date) and one row
  per invoice line (SI number, amount, CWT amount) are editable inputs on
  the right — these should reflect data pulled from *any* of the images,
  not just the first one.
- Any field the model flagged as uncertain has a **yellow highlight**
  (`.needs-review`) — check those against the image before trusting them.
- Edit any value, click **Confirm** — it saves via AJAX (no reload) and
  shows "Confirmed." next to the button.

## 4. What to verify it's actually working (not just rendering)

- The extracted values genuinely match the uploaded document (reference
  number, total, date, each SI's amount and CWT — `cwt_amount` should
  always come back positive regardless of how the document displays it).
- A blurry/ambiguous field on your test document gets flagged (yellow) —
  try a document with a smudged or handwritten field to confirm this path.
- Uploading two files back-to-back tracks both rows independently on the
  index page without a reload.
- Run the same document through twice — minor OCR variance on long SI
  numbers is possible (observed in testing); this is a genuine model
  limitation worth watching for, not a bug in the app.

## 5. Testing failure handling

Temporarily break the API key in `.env`:
```
GROQ_API_KEY=invalid
```
Then `php artisan config:clear`, re-upload a document. It should end up
`status: failed` with a generic message ("We couldn't process this
document...") — never a stack trace or the raw API error. Check
`storage/logs/laravel.log` to confirm the real error was logged there
instead. Restore the real key afterward and `php artisan config:clear`
again.

## 6. Testing via curl (no browser)

Useful for quick checks or scripting:

```bash
# 1. Get a CSRF cookie + token
curl -s -c cookies.txt http://127.0.0.1:8000/extractions -o page.html
TOKEN=$(grep -o 'name="_token" value="[^"]*"' page.html | sed 's/.*value="//;s/"$//')

# 2. Upload
curl -s -b cookies.txt -c cookies.txt \
  -F "_token=$TOKEN" \
  -F "image=@/path/to/sample.png;type=image/png" \
  http://127.0.0.1:8000/extractions
# => {"uuid":"...","status":"pending"}

# 3. Poll status
curl -s http://127.0.0.1:8000/extractions/<uuid>/status

# 4. View the review page
curl -s http://127.0.0.1:8000/extractions/<uuid>
```

## Why Groq, not Gemini

The app originally called Google Gemini. On real (non-trivial) document
images, Gemini's **free tier** returned `503 UNAVAILABLE` ("high demand")
on roughly half of all requests, independent of which Gemini model was
tried (`gemini-3.6-flash`, `gemini-flash-latest`), and `gemini-3.6-flash`'s
"thinking" mode alone could take 30–90+ seconds per image. Gemini's
`responseSchema` (constrained JSON output) made things worse, consistently
503ing on realistic images even when a schema-free JSON request with the
same image succeeded.

Groq's `qwen/qwen3.8-27b` (currently the only vision-capable model on
Groq's free API) was tested side-by-side against the same real remittance
image: 3/3 runs succeeded on the first attempt in 3–6 seconds each, versus
Gemini's frequent retries and multi-minute worst cases. `config/services.php`
still has a `gemini` block (unused by the job) in case Gemini's paid tier
is worth revisiting later — the job itself
([ExtractRemittanceData.php](../app/Jobs/ExtractRemittanceData.php)) only
calls Groq now.

## Known quirks

- **Max 5 images per extraction**: enforced both client-side (an alert
  before upload) and server-side (`StoreExtractionRequest`, `images` array
  `max:5`). This is a product decision, not a hard technical ceiling.
- **Images are downscaled before sending to the API**: full-resolution
  phone photos (4000px+, several MB each) pushed a 3-image request past
  Groq's payload size limit (`413`). `ExtractRemittanceData::downscale()`
  resizes anything wider than 1600px down to that width before base64
  encoding — the originals on disk (and shown on the review page) are
  untouched, only the copy sent to the model is resized.
- **PDF is not supported**: only `jpg`/`jpeg`/`png` are accepted by
  `StoreExtractionRequest` — PDF was dropped from validation because Groq's
  vision model only accepts image data URIs (`image_url`), not PDFs.
- **Groq only has one vision-capable model right now**
  (`qwen/qwen3.8-27b`) — check `GET https://api.groq.com/openai/v1/models`
  if extraction quality needs revisiting, in case that changes.
- **Minor OCR variance on long SI numbers**: observed slightly different
  digits on a duplicated invoice line across repeated runs of the same
  image. Not a bug — a real limitation of vision-model OCR on dense
  alphanumeric codes. The `low_confidence_fields` flagging is the intended
  mitigation; always spot-check SI numbers against the source image.
- **Ephemeral by design**: extraction results are cached, not stored in a
  database. They expire after ~1 hour ([ExtractionStore.php](../app/Support/ExtractionStore.php)) and are lost on
  `php artisan cache:clear`. This is intentional for this foundation pass —
  see the plan doc for what's deferred to the next phase (real auth + DB
  persistence).
- **No auth yet**: anyone with a UUID can view/confirm that extraction —
  fine for local testing only, not for any shared/deployed environment.
