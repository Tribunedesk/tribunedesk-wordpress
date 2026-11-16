# Level 4 – Media library

## The sync cycle

1. **Pull.** Call `GET /v3/cms/changes` with the cursor you stored. You get changes oldest first (`limit` is 1 to 500, default 100), a `next_cursor` and `has_more`. While `has_more` is true, keep pulling with the new cursor. Today the feed carries field changes (`kind="fields"`), new files (`kind="new_asset"`) settings changes (`kind="settings"`) and, with `td_deletion_marks_in_cms`, files trashed or restored in Tribune Desk (`kind="deleted"`, `kind="restored"`); skip kinds you do not know. A `kind="settings"` change has no `asset_id` and no values: when you see one, read `GET /v3/cms/settings` again.
2. **Apply.** Apply the changes in your CMS. For a new file, download it from the signed `file_url` (it expires, see `file_url_expires_at`), then call `POST /v3/cms/media/{asset_id}/link` with your own media id so Tribune Desk knows the two are the same file (see Taking files from Tribune Desk).
3. **Acknowledge.** Store `next_cursor` only after you applied the changes. Passing a cursor in the next poll acknowledges everything up to and including that change; repeating an older cursor delivers the later changes again, without side effects. Each change also has its own `cursor`, with the same meaning, so you can stop in the middle of a page: store the `cursor` of the last change you applied, or nothing if it was the first (see below).
4. **Push.** Send what changed on your side: `POST /v3/cms/media` for new files, `POST /v3/cms/media/fields` for field edits and `POST /v3/cms/media/deletions` for deletions; see their sections below. Batches hold up to 100 items; field values are plain text up to 5000 characters, at most one write per field per item. `POST /v3/cms/media/{asset_id}/link` requires `cms_media_id` and `file_url` and takes an optional `reencoded` (true if your CMS re-encoded the file, so Tribune Desk checks that the URL serves an image instead of comparing `sha256`).
5. **Answer import requests.** If your token has `backfill:write`, read `GET /v3/cms/backfill/requests` in the same cycle and answer each listed request (see Historical import): nothing else tells you one is waiting, and an unanswered request stays open.
6. Repeat, for example pull every 5 minutes and send your own changes a minute after an edit. Stay far below the rate limit.

```
# first poll: no cursor means "start from now": no history, an empty list and a cursor
curl -sS "https://api.tribunedesk.com/v3/cms/changes" \
  -H "Authorization: Bearer $TD_CMS_TOKEN"

# later polls
curl -sS "https://api.tribunedesk.com/v3/cms/changes?cursor=$CURSOR&limit=100" \
  -H "Authorization: Bearer $TD_CMS_TOKEN"
```

**A change you cannot apply.** The feed is a single position, not a list of acknowledgements, so you cannot skip one change and acknowledge the next: a later cursor acknowledges everything before it. To retry a change, stop there and keep the cursor before it. It is your decision when to give up on a change you can never apply, so that it does not block the feed forever: move past it after some attempts, for example a handful spread over a day. The server does not count them, so keep the counter next to your cursor. For a `new_asset` that is safe, because a new `push-existing` run offers the file again.

**A field edited on both sides.** If a change from the feed touches a field you edited and have not sent yet, do not apply it and do not take its version. Send your edit with the `base_version` you held; the server decides as in Conflicts and base_version. On `conflict` adopt `winning_value`; on `ok` your value stands. The same holds for a value repeated by a `td_to_cms` resync, which is why you send your edits before running one.

**One client per organization.** A cursor is not tied to a token and the server keeps one acknowledged position per organization, the highest cursor anyone passed, so `pending_changes` and `push-existing` read it. Two clients polling the same organization each see every change but muddle that position. Run one.

The first poll returns no history. It does not start the integration: a super admin does that, and before it `GET /changes` answers `409` with `state_conflict`. The first poll after it is enabled does switch your organization to this API (see Switching to this API). A poll without a cursor always restarts from now and the server cannot tell a first poll from a lost cursor, so keep your cursor and send it every time. If you lost it, changes since then are skipped: poll without a cursor only the very first time; after a loss, run a resync and resume from the cursor it returns. Files that already exist are brought across with `POST /v3/cms/media/push-existing` or by a historical import (see below) run with Tribune Desk.

**What arrives and in which order.** A `fields` change exists only for a paired file and always carries its `cms_media_id`, so it never precedes the link of a `new_asset` file. It may name a file you do not hold, one paired by an article delivery or before the switch: ignore it. Apply by version: skip a change only when its `version` is lower than yours, because a repeated current value (after a link and after a `td_to_cms` resync) has the same version as before and a new value. A field whose version you do not hold yet takes any version. `changed_by` in the feed is `editor` or `model`; such a repeat says `editor`, whoever wrote the text. Changes from the CMS and from imports do not enter the feed, so `cms` and `import` do not appear today; treat every value the same way.

## Cursors and expiry

A cursor is opaque: store and return it, never parse it. Every `200` from `/changes` carries a `next_cursor`, even when there are no changes. Changes are kept 90 days, acknowledged or not. If you stop polling for 24 hours, `/status` shows `stalled`; polling again clears it. If your cursor is older than 90 days (the integration was down for a long time), you get `410` with `cursor_expired`. Recover by resync. The same `410` follows when a super admin disables the integration and enables it again: changes made while it was off are not in the feed, so re-enabling invalidates the cursors from before the break. Run a resync and resume from the cursor it returns.

1. `POST /v3/cms/resync` with `direction` (`cms_to_td` or `td_to_cms`: which side wins) and your current field values, in pages of up to 100 items. Set `is_last` on the final page; send the returned `resync_id` with the next pages. Nothing changes yet; the answer reports the number of differences, counted in fields, not files. An empty `items` with `is_last` is valid. Repeating a page replaces it and does not double the count. `status` is `collecting` until the last page and `ready` on it; a page after that answers `409`, so if you lost the answer to the last page, start a new preview. Items you send are matched on `cms_media_id`; unpaired ids are ignored silently. A field you leave out is not compared and does not change. An empty `value` is compared like any other: with `cms_to_td` it clears the field in Tribune Desk, with `td_to_cms` it brings the Tribune Desk value to you. So for `cms_to_td` leave out empty fields unless you mean to clear them. The integration must be enabled, otherwise you get `409` with `state_conflict`. So does a page sent to a closed or expired session, or with a different direction than the first page, or to a session started before the integration was re-enabled. A preview may hold up to 50 000 differences; a page that would go over that cap is refused and stores nothing.
2. When the preview is `ready`, confirm within 24 hours with `{"resync_id": "...", "confirm": true}`. The response has a `cursor`; resume `/changes` from it. If the answer was lost, confirm again: a resync already `applied` or `held` changes nothing more and gives the same answer, with the same cursor.

**Which side to choose after a long outage.** The API cannot choose for you, and the choice discards one side's edits from the gap. A sound order: first send your unsent edits with `POST /media/fields` (it works with an expired cursor and applies the conflict rule field by field), then run `td_to_cms`. Show the preview to a person before confirming.

**How `td_to_cms` reaches you.** On confirmation each differing value becomes a `kind="fields"` change after the returned cursor, with the version unchanged and `changed_by` `editor`; apply it even though you already know that version.

That cursor is the feed position when the preview started, so changes made meanwhile come through the feed and some may repeat: apply changes idempotently, using field versions. With `cms_to_td`, a field changed in Tribune Desk after the preview started is not overwritten; that newer value reaches you through the feed. This includes a model-generated text filled into an empty field.

Resync covers only files already paired: linked with `/link`, imported from `POST /media` or a historical import, or paired by an article delivery. A file only accepted by `POST /media` is not paired yet. Only the fields enabled in the settings for the direction are compared and applied. It does not restore new files or deletions; use `POST /v3/cms/media/push-existing` for those. Confirming a preview that is not ready or has expired, or any resync started before the integration was re-enabled, gives `409` with `state_conflict`; run a new resync.

A preview with more than `mass_change_threshold` differences (500 by default, and 500 is also the maximum you can set) is not applied on confirmation: the answer has `status` `held`, a `held_batch_id` and the cursor, and nothing changes until you decide the batch (see Held batches).

## Conflicts and base_version

Each field has a version number. It grows only for a change the CMS knows about: one that goes into the feed, or one that came from the CMS. A change that is not in the feed (a field switched off for Tribune Desk to CMS, or an import) leaves the version as it is. Tribune Desk also keeps, per field, the time of its last change; from your side that is the `changed_at` of the field change in the feed, and your write is compared against it. For a field Tribune Desk has never changed, that time is the moment the integration was enabled.

When you send a field change (`POST /v3/cms/media/fields`) include `changed_at`, the time of the edit in your CMS, and `base_version`, the version you last saw. Our guidance: always send `base_version`, and send `1` when you do not know it. For a file you sent yourself, `1` is right until you see another version: an import does not move the version, so a field Tribune Desk has never changed takes your write whatever you send (step 4). A write without it skips the version check, so it can overwrite a change in Tribune Desk that you never saw (step 5). For each field the server decides in this order:

1. The field is switched off for CMS to Tribune Desk: `rejected` with reason `field_disabled`.
2. The value is the same as in Tribune Desk: `unchanged`. It does not count towards the threshold.
3. The field in Tribune Desk is non-empty and `keep_nonempty_cms_to_td` is on: `rejected` with reason `keep_nonempty`.
4. The write has a `base_version`, and it equals the current version or Tribune Desk has never changed the field: the write applies.
5. No `base_version` and `changed_at` is not older than the last change in Tribune Desk: the write applies, under every conflict rule. A `changed_at` in the future counts as now.
6. Otherwise the field has moved on and the conflict rule of the organization decides (`last_change`, `cms` or `td`, set in the settings). `cms`: the write applies. `td`: the result is `conflict`, with the current `version` and the `winning_value`. `last_change`: the write applies when its `changed_at` is not older than the last change in Tribune Desk, otherwise `conflict`. A tie goes to the CMS.

On `conflict` adopt `winning_value` in your CMS and do not resend. A field result can also be `ok`, `unchanged`, `rejected` (see `reason`) or `held`, and carries the current `version` of the field. Keep that version after `ok`, `unchanged` and `conflict`. After `rejected` and `held` keep the one you had: you have not seen that value. After `rejected` (`field_disabled`, `keep_nonempty`) there is no `winning_value`: your value stays in your CMS and the two sides differ until the next edit or a resync; do not resend it unchanged. A change applied this way does not come back to you in the feed.

**What values and times may hold.** A value is plain text of up to 5000 Unicode characters, counted as characters, not bytes. It is stored as sent: nothing cleans markup or normalizes Unicode, so do not send markup. An empty string is allowed and clears the field. A NUL character fails the whole request with `422`. `changed_at` is ISO 8601 with a time zone and any precision; a time without a zone gives `422`. A future time counts as now, and there is no other allowance for clock skew, so keep your clock in sync: a slow clock loses under `last_change`.

Item status in `ItemResult`: `ok` applied; `known` the file was already known; `unknown` the file is not paired yet (see Sending new files), not known to Tribune Desk or in its trash; `conflict`, `rejected` (see `reason`) and `held` as above. A file or a field change you repeat answers `known` or `unchanged`.

```
curl -sS -X POST https://api.tribunedesk.com/v3/cms/media/fields \
  -H "Authorization: Bearer $TD_CMS_TOKEN" -H "Content-Type: application/json" \
  -d '{"items":[{"cms_media_id":"doc-42","fields":[
        {"field":"alt","value":"A fire engine at night","base_version":3,
         "changed_at":"2026-10-31T10:00:00Z"}]}]}'
```

A file is matched by `cms_media_id`. One that is unknown, trashed or belongs to another organization answers `unknown`. If the integration is not enabled, the route answers `409` with `state_conflict`.

## Sending new files

`POST /v3/cms/media` takes up to 100 items. Each needs `cms_media_id`, a publicly fetchable `file_url`, `width`, `height` and `mime_type`; the schema marks the last three optional, but an item without them is `rejected` with reason `missing_metadata`. Optional are `sha256` (accepted, not used: the import computes its own), `created_at`, `credit`, `post_title`, `iptc_caption`, `iptc_title` and initial `fields`. Any other property gives `422`. Tribune Desk downloads the file later, at its own pace, up to a daily limit on describing new files.

- **Identifiers are yours.** `cms_media_id` is any text of 1 to 255 characters, here and on every route. Unique within your organization.
- Per item: `ok` accepted, `known` already received, or `rejected` with a reason: `missing_metadata`; `new_files_disabled`; `before_since` and `below_min_width` (the filters below; `before_since` needs `created_at`); `host` (see Allowed file hosts); `too_small` (under 800 pixels wide; height does not count); `mime` (other than `image/jpeg`, `image/png`, `image/webp`); or `excluded_agency` (`credit`, or the `caption` field when `credit` is empty, names an agency excluded under the agreement of your organization; there is no public list, and most organizations have no such rule).
- **A refusal is not remembered.** Nothing is stored for a rejected item, so you may send it again at any time, and it gets the same answer until the settings change. A `kind="settings"` change is the moment to retry; retrying sooner is harmless and useless.
- The setting `new_files_cms_to_td` and its filters (`new_files_cms_to_td_since`, `new_files_cms_to_td_min_width`) decide what is accepted.
- New files are not counted towards `mass_change_threshold`: they only add files, and downloading has its own pace.
- If the integration or the media library of the organization is not enabled, the route answers `409` with `state_conflict`.

### When a file you sent becomes paired

An accepted item has no file yet. The pair (your `cms_media_id` and a Tribune Desk file) appears only after the import has downloaded, judged and saved it, at its own pace. Until then:

- `POST /media/fields` answers `unknown` for it: keep the edit and send it again later.
- `POST /media/deletions` answers `unknown`: report it again later, because the import may still save the file.
- `POST /resync` ignores it.

To check, send the same item to `POST /media` again: it is safe. `ok`, `known` and `rejected` carry `asset_id` once a live file with this id exists (a refusal by the settings does not remove a file Tribune Desk already holds), and `null` until then. Keep that `asset_id`: you need it to call `/link`, for example when a deleted file comes back. There is no route that lists import results. If `asset_id` stays `null`, the file is not coming: the import does not report why. Reasons are a failed download (a host problem, `404`, not an image), a file judged not to be a photo (a collage, a screenshot, a graphic), the same bytes already held under another record (then that record is not paired with your id, and the file does not come back as a `new_asset` to you) or a file over 25 MB. Tribune Desk does not try again, and sending the item again does not make it try; it answers `known`. So stop checking after a reasonable time, and stop repeating a deletion answered `unknown` with it. The server gives no figure, because the pace depends on the daily limit; a few days is a sound default.

**Initial `fields` are context, not values.** They help describe the file: their `base_version` and `changed_at` are ignored (the schema still requires `changed_at`, so send the time of the edit), they do not count towards the threshold, and they are not written to the fields. The texts of the imported file come from the description made by Tribune Desk, and the caption from `credit` (or your `caption`). To put your texts on the file, send them to `POST /media/fields` once it is paired, with `base_version` `1`. Those writes count towards the threshold.

### Allowed file hosts

A file URL must be `https`, on port 443, without login data, and on one of: the domain of your organization, the host of the article-delivery webhook configured for it in Tribune Desk (nothing here calls it) or a host a super admin added for you. A leading `www.` does not matter, but other subdomains and CDN hosts do: ask your Tribune Desk contact to add them. `image_hosts` in `GET /v3/cms/status` and `GET /v3/cms/settings` lists them together with the Tribune Desk storage hosts; a host missing there shows as `host` or `422`. The rest of the URL is yours: it needs no file extension, and Tribune Desk does not parse it for an id.

## Taking files from Tribune Desk

Images that appear in Tribune Desk after the integration was enabled reach you as `kind="new_asset"` changes, when the settings allow it: `new_files_td_to_cms` together with `td_to_cms_editor_files` for editor uploads or `td_to_cms_graphics` for generated graphics. Reels stay in Tribune Desk.

1. **Download.** The change carries `file_url`, `file_url_expires_at` (24 hours), `mime_type`, `filename`, `sha256`, `width`, `height` and in `fields` the current texts the settings allow. `sha256`, `width` and `height` can be `null` for an older file; the others are there. The URL is a signed link to storage: a plain `GET` with no extra headers (do not send your token there), up to 25 MB. If you already have the file, for example from an article delivery, recognize it by `sha256` among your files not paired yet (a file paired with another `asset_id` stays as it is), skip the download and use it, then link it. Link it rather than sending it to `POST /media`: the import would drop the same bytes as a duplicate and leave your id unpaired.
2. **Add it to your library.** Compare the downloaded bytes with `sha256` when it is present. An empty or failed download is not a new file: stop at this change, keep the cursor before it and retry; see A change you cannot apply in The sync cycle.
3. **Link.** Call `POST /v3/cms/media/{asset_id}/link` with `cms_media_id` (any id of yours) and `file_url` (see Allowed file hosts). `ok` means recorded, `known` means this `cms_media_id`, URL and `reencoded` were already recorded. After a change, a `fields` change with the current texts follows in the feed, so a text changed after you read `new_asset` is not lost.
4. **Fetch the address again if it expired.** Every read of a change renews its `file_url`. If the 24 hours passed before you downloaded the file, pull again from the cursor before that change and use the new address.
5. **Say when you re-encoded the file.** If your CMS processed the file so its bytes differ from `sha256`, send `reencoded: true`. Tribune Desk then checks that your URL answers with an image instead of comparing `sha256`, and keeps its original unless a super admin turned on removing such originals.
6. **What happens after the link.** In the background, on its own schedule, Tribune Desk downloads your `file_url` with a `GET`. Without `reencoded` it compares the checksum; with it, the answer must have an `image/*` type and a body. When the setting `keep_copies_in_td` is off and the check passes, it drops its copy and from then on relies on your URL. A different checksum leaves the copy in place and marks the file; `404` or `410` marks it missing and it is no longer suggested to editors. None of this is reported through the API, and there is nothing to retry: serve the same bytes at the same URL, or send `reencoded: true`.

A file that was trashed, deleted or linked meanwhile is left out of the answer, and so is one the settings no longer allow; the cursor still moves on past it. Errors of `/link`: `404` for an unknown or trashed file, `422` for a URL on a host that is not allowed, `409` with `state_conflict` when the integration is not enabled, the `cms_media_id` belongs to another file, the file already has another one, or Tribune Desk keeps no copy of the file and the URL differs from the one recorded for it: your URL is then the only source of the file, so it cannot be replaced by an address nobody checked. None of these clears by repeating the same call, so do not call again until something on your side changed. The file stays usable on your side, just unpaired.

**Older files.** `POST /v3/cms/media/push-existing` with an optional `since` queues up to 1000 files as `new_asset` changes, oldest first, under the same settings, and answers with `queued` and `next_after`. Send `next_after` back as `after`, with the same `since`, until it is `null`; that ends the run, not `queued`. Poll `GET /v3/cms/changes` at least once first, or you get `409` with `state_conflict`; one poll since the integration was enabled is enough. `since` compares with the original date of the file, or its creation date in Tribune Desk when it has none. The settings in force at the call decide what is queued. A run skips files already linked and files whose change you have not acknowledged yet, so it does not duplicate. A file you acknowledged without linking it is not offered again within a run, so a file you cannot download does not block the rest; a new run, without `after`, offers it again. An invalid `after` gives `422`. Keep polling with your cursor: a poll without a cursor starts from now and skips what was queued. Files only add, so `mass_change_threshold` does not apply and `held_batch_id` stays `null`.

## Deletions

By default a deletion stays in the system where it happened; nothing is ever deleted permanently in the other one.

**Deleted in the CMS.** Send `POST /v3/cms/media/deletions` with up to 100 items of `cms_media_id` and `deleted_at`, each id once (a repeated id fails the whole request with `422`). The file is marked as missing and no longer suggested to editors, whatever the settings say. With `cms_deletion_trashes_in_td` it also goes to the trash. Moves to the trash count towards `mass_change_threshold` in the same 60 minutes as field changes; past it the file only gets the mark, and the item answers `ok` with `reason` `threshold`. Past the threshold is final: nothing moves it to the trash later, and repeating the report answers `known`; an editor can trash the file by hand. Per item: `ok`, `known` (already recorded, safe to repeat) or `unknown` (no such file). `unknown` is final for a file Tribune Desk never had, but a file accepted by `POST /media` and not imported yet answers the same: report it again later (see When a file you sent becomes paired).

**Back in the CMS.** When the file is back in your library, call `POST /v3/cms/media/{asset_id}/link` again with the same `cms_media_id`; that clears the mark. For a file that came from Tribune Desk you hold `asset_id`. For one you sent, `POST /media` with the same id answers `known` (or `rejected`, when the settings now refuse new files) with `asset_id`; that call alone does not clear the mark. A file in the trash has no `asset_id` there and `/link` gives `404`, until an editor restores it. Keep the same URL when Tribune Desk may hold no copy (see the errors of `/link`).

**Trashed in Tribune Desk.** With `td_deletion_marks_in_cms` the feed carries `kind="deleted"` when a paired file goes to the trash and `kind="restored"` when it comes back. Both have `cms_media_id`, `asset_id` and `changed_at`, and an empty `fields`. Do what suits your CMS, such as a hidden mark; do not delete a file used in published content. A permanent deletion in Tribune Desk is not reported. A trash caused by your own deletion report can come back as `kind="deleted"`; ignore it for a file you no longer have, and for one whose record you keep it only sets the same mark.

## Switching to this API

Before the switch, Tribune Desk sends no metadata edits to the CMS. An organization is switched at the **first `GET /v3/cms/changes` after the integration is enabled** (with or without a cursor), not when it is enabled, because only a poll proves that a client is listening.

- At that poll the edits Tribune Desk recorded before the switch are moved into the feed. A first poll without a cursor returns no rows but a cursor from before them, so you receive them with the next poll; a first poll with a cursor returns them in the same response. Only fields the settings send to the CMS are moved; the rest are dropped.
- From then on every edit goes only through the feed.
- If a super admin disables the integration, nothing is sent to the CMS while it is off. The feed changes you had not acknowledged wait in Tribune Desk, so they are not lost. Enabling it again requires a new first poll, which moves them into the feed, and cursors from before the break give `410`.

## Held batches

To protect against accidents (a bulk edit, a bad import) the organization has a mass-change threshold, `mass_change_threshold` in the settings (500 by default, 1 to 500; a higher value is rejected with `422`), enforced by the server. It applies to `POST /v3/cms/resync`: a confirmation with more differences than the threshold changes nothing and answers `status: held` with `held_batch_id`. It also applies to `POST /v3/cms/media/fields`. There a run lasts 60 minutes from the first change counted from the CMS, and the server counts the whole run, not one request, because a client sends batches of 100. A change identical to the current value does not count. Changes over the threshold are not written: their fields answer `held`, the answer carries `held_batch_id`, and they wait in one batch for the run, which you decide as below. After 60 minutes a new run starts. New files are not counted. A held batch is not an error: do not retry it, decide it. Take a `held` field out of your send queue: the value waits in the batch. Later requests of the run add to the same batch until it is decided, and a decision starts a new one. There is no route or field showing how much of the run is used, so you cannot pace yourself against it: send, and react to `held`. `held_batch_id` can be `null` with `held` fields: a newer value of the same field already waits in a batch you decided, so there is nothing to decide and nothing to resend.

The threshold protects against accidents, so the decision belongs to a person, not to your sync loop: show the batch to an operator. Today every batch has `kind` `fields`; `files` is reserved, because new files are never held. A batch can have `direction` `td_to_cms` only from a resync. List batches with `GET /v3/cms/held` and decide with `POST /v3/cms/held/{held_id}/decision` and `{"decision": "send"}` or `{"decision": "discard"}`. `send` answers `state: sent` at once; the changes are then applied in the background, in portions, so the work is finished when the resync has nothing left to apply, not when the answer arrives. The API does not show that moment: a batch stays `sent` and listed until 24 hours after the end, and its `count` does not fall. With `td_to_cms` you see the values arrive in the feed. The cursor from the resync stays valid. `discard` changes nothing; the two sides stay different until the next edit of a field or a new resync. A batch that is no longer pending gives `409` with `state_conflict`. So does `send` on a stale batch, one older than the change history (after the integration was re-enabled, or after 90 days): discard it and run a new resync. The same goes for `send` on a `cms_to_td` batch older than 7 days; a `td_to_cms` batch has no such limit, and `discard` always works. A sent batch that becomes that old is not applied and turns `discarded`.

## Settings

`GET /v3/cms/settings` returns the sync `variant` (`full`, `cms_to_td_only`, `td_to_cms_only` or `custom`), the individual options and the locked ones. `PUT /v3/cms/settings` needs `variant`; `options` is required exactly when the variant is `custom`, and then every option must be given explicitly. A variant sets four things and leaves every other option as it is: `full` turns on `new_files_cms_to_td`, `new_files_td_to_cms` and all fields both ways; `cms_to_td_only` turns on the first direction and off the second; `td_to_cms_only` the reverse. A write that would change an option locked by Tribune Desk gives `409` with `option_locked`, also when the variant changes it and you did not name it. Every change of the settings, yours or a lock, appears in the feed as `kind="settings"`.

`model_texts_to_cms` decides whether texts the Tribune Desk model wrote (alt text, caption) reach your CMS: `empty_only` sends them only to a field that is empty in Tribune Desk, `never` sends none; texts an editor wrote always go, when that field is on in `fields_td_to_cms`.

The server applies every option except one: `keep_nonempty_td_to_cms` is applied by the client, because only the CMS knows the value it holds. When it is on, do not overwrite a non-empty field in the CMS with a value from the feed. A field of only whitespace counts as empty, as it does on the server. Take the version even when you keep your value, and a new file has nothing to keep.

**Without `settings:read`** you do not see the options, the limits or what a `kind="settings"` change means, though the server still enforces all of it except the rule above. Ask for this scope. Without it, skip `kind="settings"` changes, assume the limits of this guide (100 items, 1000 per historical page) and leave `keep_nonempty_td_to_cms` off.

## Historical import

To bring existing files across, an operator at Tribune Desk can start an export request with filters. Tribune Desk does not call your API: your CMS polls `GET /v3/cms/backfill/requests` for requests (with their filters), then answers with pages of candidates, newest first, through `POST /v3/cms/backfill/requests/{request_id}/pages`, up to 1000 per page, setting `is_last` on the final page. Requests with no work for you yield an empty list. If you have no candidates, send one page with an empty `items` and `is_last` true: silence leaves the request open, and an organization can have only one open.

**Filters** are yours to apply when you choose candidates: `date_from` and `date_to` against the creation date of the file, inclusive; `mime_types`; `min_width`; `categories` matching any of them (ignore it if your CMS has none); `only_attached_to_published` as far as you can tell. The server re-checks only `min_width`, which then replaces the default minimum of 800 pixels, and `max_files`, so you need not cut your list at `max_files`.

**Your position** is yours to keep; the server returns neither a cursor nor a count. Keep it in memory or start over: repeating candidates creates no duplicates, and new files moving the order only means some are sent twice.

Each candidate gets a result: `ok` (held for the request), `known` (the file or its identifier is already in Tribune Desk) or `rejected` with a `reason`: `host` (the URL is not on a host allowed for your organization), `too_small`, `mime`, `missing_metadata` (send width, height and type), `agency` (the optional `credit`, or `caption` when `credit` is empty, names an agency excluded for your organization; the same rule as `excluded_agency` in `POST /media`, under another name) or `max_files` (the request is full). `cms_media_id` takes any text up to 255 characters, as elsewhere. Repeating a page is safe: it creates no duplicates and candidates already held answer `ok` again.

The optional `parent_title`, `post_title`, `iptc_caption` and `caption` help describe the file. Send what you have, as plain text.

**After `ok`** nothing is left for you to do. Tribune Desk describes and saves the files later, in its own time, and a saved file is paired with your `cms_media_id`. No change enters the feed for it, because an import is not a change on your side. As with `POST /media`, the file answers `unknown` to `POST /media/fields` until then; `POST /media` with the same id gives you its `asset_id`.

A request closes after its last page and also as soon as it reaches `max_files`; it then leaves the list and further pages answer `409` with `state_conflict`, so treat that as the end of the request, also when you repeat a last page whose answer you lost.

**Paused requests.** An operator can pause a request. It then leaves the list until resumed, and a page with candidates or with `is_last` answers `409` with `state_conflict`, the same as a closed one, and stores nothing. To tell them apart, send an empty page with `is_last` false: `200` with `accepted: 0` means paused (or open), so keep your position and try when the request is listed again; `409` means it has ended.

## Done when

- Your CMS polls `GET /v3/cms/changes` and stores the cursor only after applying the changes it returned.
- New files, field changes and deletions on your side reach Tribune Desk, and a repeated request creates no duplicates.
- A field result `conflict` (in a `200` answer) is resolved by adopting `winning_value`, not by sending yours again.
- An expired cursor (`410`) leads to a resync, not to a fresh start from now.
- Held batches are shown to a person who decides them.
