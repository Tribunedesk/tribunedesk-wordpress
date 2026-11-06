# Tribune Desk for WordPress

A WordPress plugin that receives finished articles from [Tribune Desk](https://headlinesforge.com) (a newsroom tool) and creates them as drafts in wp-admin. It implements the public Tribune Desk CMS API at levels 1-4: article delivery, link back, full content and the media library.

**Version:** v2.0.3

**https://updates.headlinesforge.com/wp-plugin/headlinesforge-receiver-2.0.3.zip**

> Version 2.0.3 always returns `edit_url` in the delivery answer, so Tribune Desk can link to the post's edit screen (before, the link was empty for every delivery).

> Version 2.0.2 corrects texts that misled readers (the token's required permissions, the log line after a rejected token) and moves the source to https://github.com/Tribunedesk/tribunedesk-wordpress.

> Version 2.0.1 fills the post excerpt from the delivery's `excerpt` field; with "Excerpt from Facebook teaser" on, the teaser goes first and `excerpt` is the fallback.

> Version 2.0.0 moves the code into `src/Protocol/` (portable protocol logic, plain PHP) and `src/WordPress/` (WordPress adapters). Classes live in the namespace `TribuneDesk\...`; the 1.x `HeadlinesForge\...` class names are kept as aliases. Source strings are English, with a Polish translation in `languages/` (text domain `tribune-desk`). Public filters are renamed to `tribune_desk_*`; the old `headlinesforge_*` names still work. Folder name, option keys, meta keys and tables keep their 1.x names (see "Stored keys and folder name").

---

## For developers

This plugin is the reference integration of the public **Tribune Desk CMS API**. If you are connecting another CMS, start with the API, not with this code:

- Guide: https://github.com/Tribunedesk/tribunedesk-cms-api/tree/v3.2.0-draft/guide (levels 1-4)
- Contract (OpenAPI, with webhooks): https://github.com/Tribunedesk/tribunedesk-cms-api/blob/v3.2.0-draft/openapi.json

The plugin pins the contract version in `tests/contract/VERSION`; CI checks that the copies in `tests/contract/` match that tag byte for byte, and the contract tests run the sample delivery through the real endpoint.

Where to read:

- `src/Protocol/` — the rules of the API in plain PHP, with no WordPress calls (signature check, draft/update/published rules, link placeholders, change feed cursor). Each class names its level, routes and guide chapter. Port these to your CMS.
- `src/WordPress/` — adapters: REST endpoint, cron, post meta, media library, Yoast, Polylang.
- The table below maps each integration level to its classes; "Stored keys and folder name" explains why option and meta keys still carry 1.x names; "Development" shows how to run the tests (wp-env).

---

## What it does — integration levels

The public guide is at https://github.com/Tribunedesk/tribunedesk-cms-api (chapters `guide/1-article-delivery.md` to `guide/4-media-library.md`).

| Level | What the plugin does | Main classes |
|---|---|---|
| 1. Article delivery | Receives the webhook (Standard Webhooks signature, or the older `X-Webhook-Secret`), creates or updates the draft, takes the featured image only from Tribune Desk's image hosts, logs every delivery, and answers with `cms_post_id`, `status` and `edit_url`. | `src/Protocol/WebhookSignature`, `DraftRules`, `DeliveryAnswer`, `ImageHostRule`; `src/WordPress/RestEndpoint`, `PostWriter`, `MediaHandler`, `ImageHosts`, `DeliveriesLog` |
| 2. Link back | Reports the public URL and date of published articles (`permalink` in the answer, `POST /v3/cms/articles/published`, the deprecated `/v2` route as a fallback), also in batches for older posts. | `src/Protocol/LinkBack`; `src/WordPress/PublishNotifier`, `PublishedUrlsClient`, `Backfill` |
| 3. Full content | Resolves internal-link placeholders, keeps the "Read more" links from `backlink_sources`, stores SEO fields, author and language, and puts the AI label in `<head>`. | `src/Protocol/PlaceholderLinks`, `BacklinkSources`, `AuthorOrder`; `src/WordPress/LinkResolver`, `Backlinks`, `ReadMore`, `AuthorResolver`, `SeoMeta`, `Language`, `ProvenanceMeta` |
| 4. Media library | With a CMS token, syncs the media library both ways through `/v3/cms`: change feed, new files, field changes, deletions, resync, held batches, historical import. | `src/Protocol/ChangesFeed`, `Resync`, `ApiErrors`; `src/WordPress/CmsApiClient`, `CmsSync`, `CmsResync`, `CmsDeletions`, `CmsBackfill`, `CmsSettingsScreen`, `MediaPush`, `MediaMetaSync` |

---

## Installation

A step-by-step guide for someone who has never installed a plugin by hand. Everything is clickable, no console needed.

### What you need

- **Access to wp-admin** of your WordPress (`https://your-site.com/wp-admin`) with an administrator account.
- **A made-up "secret"**: any long string (for example `Ftq8-2nm9-pPLx-RR4k-fwn3`). You make it up once, now. Store it safely (for example in a password manager); you need it in two places.
- **5 minutes.**

### Step 1: Download the plugin as a ZIP

Download **one file** from our server. You do not need a GitHub account and you **do not rename the folder**; the archive is already packed correctly:

**https://updates.headlinesforge.com/wp-plugin/headlinesforge-receiver-2.0.3.zip**

**Do not unpack it.** WordPress does that itself.

### Step 2: Upload the plugin to WordPress

1. Log in to **wp-admin** (`https://your-site.com/wp-admin`).
2. In the left menu: **Plugins → Add New**.
3. At the top: **Upload Plugin**.
4. **Choose File** → pick the ZIP → **Install Now**.
5. Click **Activate Plugin**.

This is the only manual install. From now on the plugin updates itself (see "Automatic updates").

### Step 3: Set the webhook secret and author in wp-admin

1. In the left menu click **Settings → Tribune Desk**.
2. Fill in the form (all fields are described under "Settings"). In short:
   - **Endpoint URL** (grey box at the top): do **not** change it. Copy it; you paste it into Tribune Desk in Step 4. It looks like `https://your-site.com/wp-json/tribunedesk/v1/receive`. If you already have an integration on `.../wp-json/headlinesforge/v1/receive`, leave it alone: the old address still works.
   - **Webhook secret**: enter your made-up secret and copy it to the clipboard too.
   - **Default author**: pick the account that all received articles get.
3. Click **Save changes** at the bottom.
4. If you already have published Tribune Desk articles, click **Send URLs of published posts to Tribune Desk** (see "Link back"). Posts published from now on report themselves.

### Step 4: Paste the same data into Tribune Desk

1. Open Tribune Desk: **https://headlinesforge.com/app** (or wherever your UI is hosted).
2. Log in as the newsroom admin.
3. Click **Settings** in the top right corner.
4. In the left menu choose **Integrations**.
5. Fill in two fields:
   - **Webhook URL**: paste the Endpoint URL from Step 3 (for example `https://your-site.com/wp-json/tribunedesk/v1/receive`).
   - **Webhook Secret**: paste the same secret you set in wp-admin.
6. Click **Save**.

### Step 5: Test, send the first article

1. In Tribune Desk pick any finished article (status "Done").
2. A **Send** button should appear in the top right corner, next to "Copy HTML". If it is missing, go back to Step 4 and check that the Webhook URL is saved.
3. Click **Send**.
4. A status appears under the buttons:
   - **"Sent just now"**: success, go to Step 6.
   - **"Error: http 401"**: the secrets differ. Check that both fields hold identical text.
   - **"Error: http 404"** or **"timeout"**: the URL is wrong or WordPress is unreachable.

### Step 6: Check the draft in WordPress

1. Back in **wp-admin**: **Posts → All Posts**, click the **Drafts** filter at the top.
2. You should see your article. Open it and check that:
   - the title is correct,
   - the content (HTML) looks right,
   - tags (panel on the right) contain the article's "related topics",
   - the featured image is set (if the article had a generated image),
   - the Yoast SEO fields ("SEO title", "Meta description", "Focus keyphrase") are filled, provided the article had them in Tribune Desk. The plugin never overwrites a field that already has a value, so your manual edits survive a re-send of the same article.
3. If all is well, click **Publish**. The article is live.

### Step 7: Check the log in wp-admin (optional)

`Settings → Tribune Desk`, at the bottom: the **"Recent deliveries"** table shows the last 20 entries with time, kind (received / URL sent / backfill), article ID, post ID and status. Useful for debugging.

---

## Settings

`Settings → Tribune Desk`.

- **Endpoint URL**: read-only. `https://your-site.com/wp-json/tribunedesk/v1/receive`. The 1.x address `.../wp-json/headlinesforge/v1/receive` (REST alias `headlinesforge/v1`) keeps working.
- **Webhook secret**: shared secret checked in the `X-Webhook-Secret` header. Not checked when a signing secret is set.
- **Signing secret** (since 1.17.0): see "Delivery signature".
- **Default author**: the WordPress account that gets all received articles. The list shows only Administrator and Editor accounts.
- **Publish immediately** (Polish UI: "Publikuj od razu"): when ticked, articles are published at once instead of landing in drafts.
- **Tribune Desk API base** (section "Tribune Desk API"): do **not** change. Default `https://api.headlinesforge.com`. The plugin reports the URLs of published posts there, so Tribune Desk can link to them in later articles.
- **CMS token** (`tdcms_…`): token for `/v3/cms` (published-URL callback, media library, settings and image hosts). Without it the media library sync does not run.
- **Author mapping**: maps authors from Tribune Desk to WordPress accounts; articles of a mapped author go to that account instead of the default author.
- **Theme fields**: extra fields the active theme needs; they are filled from the delivery.
- **Send URLs of published posts to Tribune Desk** (button below the form): click once after installing 0.8.0 or newer if you already have published Tribune Desk articles. It sends their URLs in batches of 100; with more posts click again until the message shows "0 remaining".
- **Updates** section: installed version and the auto-update switch (see "Automatic updates").

Secret fields (webhook secret, signing secret, CMS token) are password fields: the saved value is not in the page source, its last characters are shown under the field, and saving with an empty field leaves it unchanged.

---

## Delivery signature (since 1.17.0)

A Tribune Desk superadmin generates a signing secret `whsec_…` for the newsroom (shown once). Paste it into **Settings → Tribune Desk → Signing secret**. From then on the plugin accepts only deliveries with a valid Standard Webhooks signature (`webhook-id`, `webhook-timestamp`, `webhook-signature`) and a timestamp within 5 minutes of the server clock; a bad signature is a `401`. The webhook secret is not checked then. Without a signing secret the plugin works as before (`X-Webhook-Secret`).

To remove the signing secret, tick **"Remove saved signing secret"** under the field (deliveries are then checked with the webhook secret again). For the other fields: `wp option delete headlinesforge_webhook_secret` or `headlinesforge_cms_token`.

---

## Images and placeholders (since 1.17.0)

Images are downloaded over https only. When a CMS token is set, only from the hosts in `image_hosts` from `GET /v3/cms/settings` (refreshed hourly). Without a token the list cannot be fetched and only https is checked.

An image rejected by the host check leaves a row in the deliveries log (kind media, with the host name). An empty `image_hosts` list from Tribune Desk means "unknown": then only https is checked.

Internal-link placeholders are recognized by the path from the delivery field `link_placeholder_prefix` (today `/app/a/`), not by a hard-coded value.

### Featured image: no duplicates, and `featured_image` in the answer

Optional fields in `generated_images[0]`: `asset_id` (string, the photo's ID in Tribune Desk) and `cms_attachment_id` (int, an attachment Tribune Desk knows from an earlier delivery). The plugin reuses an existing attachment (by `cms_attachment_id`, by `asset_id` stored in the meta `_hf_asset_id`, by the URL of its own media library file, or by a file in uploads without an attachment) and does not download the image a second time. The texts of the existing attachment (alt, caption, description) are left untouched.

The 200 answer to every delivery, when the post's featured image has `_hf_asset_id`, contains
`"featured_image": {"asset_id": "…", "attachment_id": 123, "url": "https://…/file.jpg"}`
(`url` is the original, not the `-scaled` variant). Without `_hf_asset_id` the key is absent.

---

## "Read more" box (since 1.17.0)

Tribune Desk sends `backlink_sources` in the delivery: older articles that should link to the new one. The plugin stores them in the table `{prefix}hf_backlinks`. The box shows, on the page of an older post, the newer already-published posts, newest first:

- shortcode: `[tribune_desk_read_more limit="5" title="Read more"]`
- block: "Read more (Tribune Desk)" in the editor (number of posts and title in the block settings)
- look: class `td-read-more`; the whole HTML can be replaced with the filter `tribune_desk_read_more_html` (`$html`, `$posts`, `$post_id`). The old name `headlinesforge_read_more_html` still works.

The plugin does not insert the box itself, because the theme may already draw its own.

---

## Link back (`published-urls` contract)

For integrators and other CMSes. Tribune Desk learns the URL of a published article in two ways. This plugin implements both and is the **reference implementation** for any other CMS (`src/Protocol/LinkBack`, `src/WordPress/PublishedUrlsClient`, `src/WordPress/PublishNotifier`).

**1. The `permalink` field in the webhook answer.** If the CMS publishes the article at once, return `"permalink": "https://…"` in the JSON answer (2xx, `Content-Type: application/json`, at most 64 KB). The editor URL (`edit_url`) is **not** a permalink.

**2. Callback after publishing (since 1.17.0: via `/v3` with the CMS token).** When the article is published later (draft → published) and the settings hold a `tdcms_…` token with the `articles:write` permission:

```bash
curl -sS -X POST https://api.tribunedesk.com/v3/cms/articles/published \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ${TD_CMS_TOKEN}" \
  -d '{"items":[{"article_id":"514e9e08-c003-42b7-81e8-79f5167ec833","url":"https://your-site.com/article-title/","published_at":"2026-11-04T09:30:00+00:00"}]}'
```

The answer has the same shape (`results` with statuses `ok|not_found|forbidden|invalid_url`). Full description: the Tribune Desk CMS API guide, chapter "Level 2 – Link back". When the token lacks `articles:write` (`401`/`403`), or the `/v3` route answers `404` (a backend without it), the plugin sends the same batch the old way below and records why in the deliveries log. Without a token only the old way works.

**Old route (deprecated; works until all installations have moved):**

```bash
curl -sS -X POST https://api.headlinesforge.com/v2/cms/published-urls \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: ${HF_WEBHOOK_SECRET}" \
  -d '{"articles":[{"article_id":"514e9e08-c003-42b7-81e8-79f5167ec833","url":"https://your-site.com/article-title/"}]}'
```

- The host is **`api.headlinesforge.com`** (not `headlinesforge.com`).
- `X-Webhook-Secret` is the same secret Tribune Desk uses to sign webhooks to you.
- `article_id` is the `article_id` field of the webhook payload (UUID). `articles` has 1-100 items; one shape handles both a single publication and a backfill.
- `url` is the public address of the article (http or https). The last write wins (if the slug changes after publishing, send again).

`200` answer:

```json
{"results": [{"article_id": "514e9e08-c003-42b7-81e8-79f5167ec833", "status": "ok"}]}
```

| Code / status | Meaning | What to do |
|---|---|---|
| `200`, `ok` | URL saved | nothing |
| `200`, `not_found` | Tribune Desk does not know this `article_id` | check you send the `article_id` from the payload, not your own ID |
| `200`, `forbidden` | the article exists but belongs to another newsroom than the one the secret matches | do not retry |
| `200`, `invalid_url` | URL rejected (private host, no scheme, > 2048 characters) | fix the URL |
| `401` | missing header, or the secret matches no newsroom | fix the secret; do not retry automatically |
| `422` | bad body: empty list, > 100 items, `article_id` not a UUID (**the whole batch fails**) | filter the items before sending |
| `429` | limit of 30 requests/min per secret | wait and retry |
| `5xx` | Tribune Desk failure | retry later |

The plugin: on publishing, one item with a 5 s timeout and one retry after 5 min for a network error / `429` / `5xx`; backfill in batches of 100 with a 10 s timeout. The result of every call is in the "Recent deliveries" table (kind `URL sent` or `backfill`).

---

## Media deletions between WordPress and Tribune Desk

Since 1.15.0 deletions are reported, but neither side deletes the other's files.

- **WordPress → Tribune Desk:** deleting an image from the library goes to a queue and is sent in the background. Tribune Desk stops proposing that file; it moves it to the trash only if the publisher's option says so, and never deletes it permanently.
- **Tribune Desk → WordPress:** with the option on, the file gets a hidden marker and can be found with the filter "Usunięte w TD" (deleted in TD) in the library. A file used in published content gets no marker. The plugin deletes nothing; the decision to delete a file belongs to the newsroom.

---

## Photos from WordPress to the Tribune Desk library

With a CMS token the plugin sends photos from the WordPress media library to the newsroom's photo library in Tribune Desk. Since 1.16.0 the only route is `/v3/cms`: without a token nothing goes out.

- **What is sent:** JPEG, PNG and WebP photos uploaded after the first run of 1.9.0 (the plugin does not touch older ones). Together with the photo go its URL, dimensions, texts (alt, caption, title, description, IPTC data) and, if any, the post it belongs to.
- **When:** one minute after a change; an hourly run is the safety net. A photo attached to a post goes only after that post is published; an unattached one, a day after upload.
- **Without a token:** field changes wait in a queue, for at most 500 attachments, and go out once a token is set.
- **Older photos:** since 1.14.0 the backfill is ordered by the Tribune Desk administrator. Every 5 minutes the plugin hands over candidates according to the filters of the order, on its own, with no control on the publisher's screen.
- **What is not sent:** photos that came to WordPress from Tribune Desk do not go back.
- **Switch:** sending is turned on on the Tribune Desk side, per newsroom; while it is off the plugin sends nothing.
- The result of every batch is in the "Recent deliveries" table as "Photos to library" (Polish UI: "Zdjęcia do biblioteki").

---

## Stored keys and folder name

The folder `headlinesforge-receiver`, the main file `headlinesforge-receiver.php`, the option keys `headlinesforge_*`, the post meta `_hf_*` and `_aw_article_id`, the tables `{prefix}headlinesforge_deliveries` and `{prefix}hf_backlinks`, the cron hooks `headlinesforge_*` and the REST alias `headlinesforge/v1` keep their 1.x names **on purpose**.

- WordPress remembers an active plugin by its folder and file name. Renaming them would deactivate the plugin on every site at update.
- Migrating hundreds of thousands of meta rows gains a reader nothing.

---

## Filters

Each filter has a new `tribune_desk_*` name; the old `headlinesforge_*` name still works as an alias.

| Filter | Old name (alias) |
|---|---|
| `tribune_desk_backfill_pause_seconds` | `headlinesforge_backfill_pause_seconds` |
| `tribune_desk_cms_backfill_budget` | `headlinesforge_cms_backfill_budget` |
| `tribune_desk_cms_poll_budget` | `headlinesforge_cms_poll_budget` |
| `tribune_desk_read_more_html` | `headlinesforge_read_more_html` |
| `tribune_desk_reverse_limit` | `headlinesforge_reverse_limit` |

---

## Troubleshooting

**"The plugin will not upload: file size limit message"**
Our ZIP is small (about 50 KB), so this is unlikely. If it happens, ask your hosting provider about `upload_max_filesize` in PHP (usually 2 MB by default, which is plenty).

**"The Send button is there, but clicking it gives HTTP 401"**
The secrets differ. Copy the secret from wp-admin again (select it in the "Webhook secret" field, Ctrl+C) and paste it into Tribune Desk. Trailing whitespace counts too. If a signing secret is set, the signature must match instead.

**"HTTP 404 after clicking Send"**
The URL is wrong. In wp-admin `Settings → Tribune Desk` copy the "Endpoint URL" again; it must be exactly the same as in Tribune Desk.

**"HTTP 500 — Internal Server Error"**
WordPress crashed on our request. Open `Settings → Tribune Desk` → "Recent deliveries" shows the error details in the "Error" column. Report it to the plugin author.

**"HTTPS required" or mixed content**
The Webhook URL must be on HTTPS (not HTTP). If your site runs on `http://`, install an SSL certificate first (Let's Encrypt is free). Tribune Desk also rejects non-https URLs.

**"In Recent deliveries the row 'URL sent' has status unauthorized"**
The secret in wp-admin does not match the one in Tribune Desk → Integrations. The post URL did not arrive; after fixing the secret click "Send URLs of published posts to Tribune Desk".

**"…status forbidden"**
The secret is correct, but the article belongs to another newsroom in Tribune Desk (for example a post moved over from a demo installation). This is not a plugin error; that post will not be reported.

**"…status error with the text 'retry in 5 min'"**
Tribune Desk did not answer within 5 s or answered 5xx. The plugin tries once more after five minutes (WP-Cron, so on a site with no traffic it may take longer). If the second row also has an error, use the backfill button.

**"A link in the article leads to headlinesforge.com/app/a/…"**
This is an internal-link placeholder: the article it points to had no URL yet when this text was written. The plugin replaces it by itself once that article is published in this WordPress. If that article is published elsewhere (another CMS), enter its URL in Tribune Desk in the "Link to article" box.

---

## Automatic updates

Since 0.7.1 the plugin checks for a newer version itself and **installs it by default**. You do not need to download or upload anything.

### How to check which version you have

`Settings → Tribune Desk`: the installed version is at the top of the **"Updates"** section. The same is shown in the list `Plugins → Installed Plugins`.

### How to turn off automatic updates

Two places, **the same setting**; a change in one shows at once in the other:

- `Settings → Tribune Desk` → "Updates" section → untick "Install new plugin versions automatically".
- `Plugins → Installed Plugins` → the "Automatic updates" column at Tribune Desk Receiver → "Disable auto-updates".

After turning it off, WordPress still **shows** that a newer version is available and the "Update now" button; it just does not install it by itself.

### What to do when an update fails

**"The checksum of the downloaded package … does not match the manifest"** (Polish UI: "Suma kontrolna pobranego pakietu … nie zgadza sie z manifestem"): this is our release error, not your site's. The plugin deliberately **aborted** the update and left the working version untouched, so nothing stopped working. Report it to us; we fix the release and the update arrives by itself.

**Download error / timeout**: your server could not connect to `updates.headlinesforge.com`. The attempt failed, but nothing was replaced halfway; the working version stays untouched. It usually passes on the next attempt (WordPress checks for updates several times a day). If it repeats, ask your hosting to allow outgoing HTTPS connections to that address.

**A new version does not appear at all**: nothing is broken, WordPress just has not checked yet. Click "Check again" on `Dashboard → Updates`. WordPress caches the check result for a few hours.

**"Could not fetch the Tribune Desk manifest, so the package could not be verified…"** (Polish UI: "Nie udalo sie pobrac manifestu Tribune Desk, wiec pakietu nie mozna bylo zweryfikowac…"): our manifest server was unreachable at that moment. Nothing on your site broke or changed; the plugin deliberately **refuses** to install a package it cannot verify. It resolves itself: WordPress retries several times a day. If it lasts longer than a day, report it to us.

**"The Tribune Desk manifest points to a different package than the one WordPress is trying to download — the update list is stale…"** (Polish UI: "Manifest Tribune Desk wskazuje inny pakiet niz ten, ktory probuje pobrac WordPress — lista aktualizacji jest nieaktualna…"): WordPress remembered an update offer that we have since replaced with another (for example after withdrawing a faulty release). Go to `Dashboard → Updates` → "Check again" and try once more. Here too nothing on the site was changed.

### Emergency manual install

Always possible and safe:

1. Download the ZIP of the chosen version:
   `https://updates.headlinesforge.com/wp-plugin/headlinesforge-receiver-<version>.zip`
2. `Plugins → Add New → Upload Plugin` → pick the ZIP → "Install Now".
3. WordPress shows a comparison screen; click **"Replace current with uploaded"**.

> **WARNING: NEVER click "Delete".** WordPress then runs `uninstall.php`, which
> **deletes the webhook secret, the chosen author and the whole deliveries history table**.
> An earlier version of this guide promised that "settings survive"; that
> was untrue and cost one newsroom its secret (2026-08-05).
> An update, automatic or manual, **never** does this; only the "Delete" button does.

### Once, before the first automatic update

Check that the plugin folder is named exactly `headlinesforge-receiver`. Did you install from our ZIP (Step 1)? The name is correct. Did you once install a ZIP downloaded straight from GitHub and rename the folder following the old guide? Also correct. Did you install a ZIP from GitHub and NOT rename it? It is probably wrong. If in doubt, do not guess; ask us.

A wrong name does not show at once: the automatic update installs the new version under the name `headlinesforge-receiver`, and WordPress, because the active-plugin entry still points to the old folder, then shows it as **inactive** in the "Plugins" list, and articles stop arriving. Fix: `Plugins → Installed Plugins` → click **"Activate"** at Tribune Desk Receiver. The webhook secret, the chosen author and the deliveries history are in the database and survived; nothing needs to be entered again. Report this case to us so we can verify it.

### Rolling back a faulty version (our side)

WordPress updates only **upwards**, so pointing the manifest at an older number does not roll back an already installed version. Procedure:

1. `gh workflow run publish-manifest.yml -f version=<previous>`: stops the faulty version from spreading to installations that have not taken it yet.
2. A fixing release **upwards with the previous version's code** (for example `0.7.3` containing the code of `0.7.1`): tag → `Release` → `publish-manifest.yml -f version=0.7.3`.
3. For a single installation that needs it at once: install the ZIP manually (see "Emergency manual install").

---

## Development

### Local testing

Requires Docker + Node.js.

```bash
# Every session
npx @wordpress/env start
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/tribunedesk-wordpress bash -c "composer install --no-interaction"
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/tribunedesk-wordpress ./vendor/bin/phpunit

# Stop
npx @wordpress/env stop
```

**Call `npx @wordpress/env`, not `npx wp-env`.** The short name is deprecated and since `@wordpress/env` 11.x it only prints "Please run the command 'npx @wordpress/env <command>' instead." and **exits with code 0**, so without reading the output it looks like success while the environment does not come up.

The local WP is at http://localhost:8888 (admin / password). The plugin settings page: `Settings → Tribune Desk`.

**Tests run as a Polish site**: `tests/bootstrap.php` loads the Polish translation, so assertions on UI strings expect the Polish text.

**`--env-cwd` uses the name of your clone's folder** (`tribunedesk-wordpress` when cloned as is): wp-env mounts the plugin under the name of the **working directory**, so a clone or worktree in `…/my-branch` ends up in `wp-content/plugins/my-branch`. Check it with `npx @wordpress/env run tests-cli ls wp-content/plugins`.

### Releasing a new version

1. Bump the version in **six** places and check locally:

   ```bash
   bash bin/check-version.sh 0.7.2
   ```

   The script checks: the `Version:` header, the `HEADLINESFORGE_VERSION` constant, `Stable tag` in `readme.txt`, `**Version:**` in `README.md`, the presence of a `= 0.7.2 =` section in the `readme.txt` changelog, and the link to the ZIP `headlinesforge-receiver-<version>.zip` in the installation section of `README.md`.

2. Commit, tag, push:

   ```bash
   git tag v0.7.2
   git push origin main
   git push origin v0.7.2
   ```

   The `Release` workflow builds the ZIP and the sidecar, uploads both to R2 and **does not touch** `manifest.json`. At this stage nothing changes for customers.

3. Inspect the artifact (download the ZIP, check its contents), and when it is good, publish:

   ```bash
   gh workflow run publish-manifest.yml -f version=0.7.2
   ```

   Only this step is the deploy to all newsrooms.

### Secrets that must be set in the repo

| Name | Type | What it is |
|---|---|---|
| `R2_ENDPOINT_URL` | secret | R2 account endpoint |
| `R2_BUCKET_NAME` | secret | dedicated plugin-update bucket |
| `R2_PLUGIN_ACCESS_KEY_ID` | secret | R2 token for CI, Object Read & Write, this bucket only |
| `R2_PLUGIN_SECRET_ACCESS_KEY` | secret | as above |
| `R2_PUBLIC_BASE_URL` | variable | `https://updates.headlinesforge.com` |

### Forcing an auto-update for a test (without waiting for cron)

WordPress checks for updates and installs them from cron (`wp_version_check` → `wp_maybe_auto_update`), usually twice a day. To test a release at once, in the container / on the server **as the web server user** (not root: as root `WP_Filesystem` picks FTP and overwrites nothing):

```bash
su -s /bin/sh www-data -c "php -r '
  define(\"DOING_CRON\", true);
  require \"/var/www/html/wp-load.php\";
  delete_site_transient(\"headlinesforge_manifest\");
  delete_site_transient(\"update_plugins\");
  wp_maybe_auto_update();
'"
```

`define("DOING_CRON", true)` is **mandatory**: without it `Plugin_Upgrader` deactivates the plugin for the duration of the update and, outside a browser and cron, nobody turns it back on. If the plugin still vanished from the active list: `Plugins → Activate`; the settings and the deliveries history are untouched.
