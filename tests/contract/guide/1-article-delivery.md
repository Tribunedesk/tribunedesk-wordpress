# Level 1 – Article delivery

## Article delivery webhook

This is the one call Tribune Desk makes to you: when an editor clicks “Send to CMS”, Tribune Desk sends the article to the webhook address set for your organization. The OpenAPI document describes it under `webhooks` as `article.delivered`. Your webhook needs no token from this API; it is a separate address on your side.

**The webhook address.** Your Tribune Desk contact sets it for your organization, or a newsroom user sets it in Tribune Desk Settings, Integrations; no route of this API changes it. It must be `https://` on a public host (any port); `http://`, private addresses and `localhost` are refused. Redirects are not followed. To try a receiver on your own machine, put a tunnel in front of it (for example `cloudflared tunnel` or `ngrok http 8080`) and give the `https://` address it prints.

**Several organizations at one address.** Every body carries `org_code`, but you need the right signing secret before you trust the body. Prefer one address per organization (for example `/td/webhook/{org_code}`). With one shared address, read `org_code` from the raw body, pick that organization's secret, verify the signature on the same raw bytes, and only then act on the body.

**The request.** A `POST` with a JSON body (`ArticleDelivery`) and these headers:

- `webhook-id`, `webhook-timestamp`, `webhook-signature`: the signature of the request (see [Verifying the signature](#verifying-the-signature)). Every delivery is signed: an organization without a signing secret gets no deliveries, so a request without these headers is not from Tribune Desk. Check the signature and answer `401` when it does not verify; it is the only proof that a request comes from Tribune Desk.
- `X-Delivery-Id`: the same for every attempt of one send, so you can drop duplicates. Equal to `webhook-id`.
- `X-Delivery-Attempt`: the attempt number, starting at 1.
- `User-Agent`: `ArticleWriter/2`. Deliveries come from no fixed IP address, so do not filter by address; the signature proves the sender.

**Your answer.** Any `2xx` within 30 seconds means delivered. After a network error, a timeout, `5xx`, `408`, `425` or `429`, Tribune Desk tries again after 2, 10, 30, 120, 360 and 840 minutes (about a day). Any other status (another `4xx`, or a `3xx`: redirects are not followed) ends the send at once. The same article comes again with the same `article_id` when an editor sends it again: update the post you created the first time. Answer `401` only for a signature that does not verify: any `4xx` ends the send for good, so a receiver whose clock is more than 5 minutes off loses articles. Keep your clock in sync.

**Order of sends.** A newer send of the same article cancels the pending retries of an older one, and every attempt carries the current state of the article. An attempt of an older send that was already on its way can still arrive after a newer one: keep the `sent_at` you last applied for each `article_id` and skip a body whose `sent_at` is older. `sent_at` is when the editor made the send and stays the same in every attempt of it; the time of the attempt itself is `webhook-timestamp`.

**Store under a unique key.** Two attempts of one send can overlap (Tribune Desk gives up waiting after 30 seconds and tries again later while your first answer is still being made). Keep `article_id` unique in your database and lock the row while you write, or you get two posts.

**Levels.** You do not need every field, and each level works without the ones above it. Level 1 (receive article) makes a draft post from `title`, `html`, `excerpt` and `featured_image`. Level 2 (link back) tells Tribune Desk where the post was published, so other articles can link to it: answer with `permalink`. Level 3 (full content) uses the same deliveries with more fields: internal links and the “read more” box, SEO, author, AI provenance. Level 4 (media library) reuses files your CMS already holds. Fields marked *optional* are editorial extras (social posts, alternative titles, facts) that you may use or ignore. New fields may appear in a minor version, so ignore the ones you do not know.

## Level 1 without a public endpoint: read mode

When your CMS cannot receive requests from the internet, a super admin switches the organization to read mode. “Send to CMS” then makes no request to you. Instead:

1. Poll `GET /v3/cms/changes` with a token that has `articles:read`. A sent article appears as a change of kind `article_sent` with its `article_id`. A token sees only the kinds of its scopes: `article_sent` needs `articles:read`, every other kind `media:read`; the cursor still moves past the kinds you do not see. Read mode needs the integration enabled by a super admin.
2. Fetch the article with `GET /v3/cms/articles/{article_id}`. The body is the same `ArticleDelivery` the webhook carries, built from the current state of the article, with `sent_at` of the send. The `X-Delivery-Id` header names that send; keep it. An article that was never sent gives `404`.
3. Create or update the post, then report the outcome with `POST /v3/cms/articles/{article_id}/result` and a token with `articles:write`. The body (`ArticleResultReport`) is what your webhook would answer with, plus `delivery_id`: the `X-Delivery-Id` from step 2. With it the result closes exactly that send; if a newer send of the article replaced it you get `409` with `state_conflict` and nothing is stored, so fetch the article again. Without `delivery_id` the result closes the newest waiting send. If your CMS could not take the article, send `error` with a short reason instead: the editor sees the send as failed with that text. Unlike a webhook answer the body is validated: a field of the wrong type gives `422`. A field of the right type that Tribune Desk did not store is listed in `ignored_fields` of the answer. An article that was never sent gives `409` and nothing is stored. Repeating the result is safe.
4. After `410 cursor_expired` from `GET /changes`, a token without `media:write` cannot run a resync. List the sends still waiting with `GET /v3/cms/articles/awaiting` (`articles:read`, oldest first, pages with `next_cursor`), handle each as in steps 2 and 3, then poll `GET /changes` again without a cursor. A poll without a cursor starts from now and returns no history, whatever the scopes of the token; a send made between your last page of `GET /articles/awaiting` and that poll is still listed there, so read it once more after the poll. `GET /articles/awaiting` pages by the time of the send: a send made while you page appears on a later page, and a send you report a result for drops out without moving the others. Read until `next_cursor` is `null`.

## Verifying the signature

Tribune Desk signs each delivery following the [Standard Webhooks](https://www.standardwebhooks.com/) specification, so you can use one of its ready libraries instead of writing the check yourself. A super admin generates the signing secret for your organization; it starts with `whsec_` and is shown once. The signing secret is not a credential for any route of this API.

1. Take the raw request body as bytes, before any JSON parsing.
2. Build the signed content: `webhook-id`, a dot, `webhook-timestamp`, a dot, the body: `{webhook-id}.{webhook-timestamp}.{body}`.
3. The key is the base64-decoded part of the secret after `whsec_`. Compute HMAC-SHA256 of the signed content with that key and encode the result in base64.
4. `webhook-signature` holds one or more signatures separated by spaces, each as `v1,` followed by the base64 value. The request is valid when any of them equals yours; compare in constant time.
5. Reject a request whose `webhook-timestamp` (Unix seconds) is more than 5 minutes away from your clock, so a recorded request cannot be replayed later.

**Rotation.** An organization can have two signing secrets at once. While both are active every request carries two signatures, one per secret: add the new secret on your side, then the super admin revokes the old one. Each attempt is signed again with the secrets active at that moment and a new timestamp; `webhook-id` stays the same for every attempt of one send.

## Fields of the request

Fields marked as omitted are absent from the body, not `null`, when they do not apply.

| Field | Level | Meaning |
|---|---|---|
| `article_id` | 1 | Tribune Desk id of the article. Repeated sends of the same article carry the same id: update the post you created the first time. |
| `org_code` | 1 | The newsroom's code in Tribune Desk. |
| `sent_at` | 1 | When the editor made this send (ISO 8601); the same in every attempt of it. Skip a body whose `sent_at` is older than the one you last applied. |
| `title` | 1 | The article's headline. Not repeated in `html`. |
| `html` | 1 | The article body as HTML, without the headline. Internal links are already resolved (see `internal_links`). |
| `excerpt` | 1 | A short summary of the article for the post excerpt (the chosen meta description); null when there is none. |
| `featured_image` | 1 | The main image: the same object as the first entry of `generated_images`; null when the article has no images. |
| `metadata` | 1 | Where the article comes from. |
| `language` | 1 | The newsroom's writing language, a BCP 47 tag of up to 16 characters (today `pl` or `en`). Omitted when not set. |
| `internal_links` | 3 | Links to other articles of the newsroom placed in `html`. |
| `link_placeholder_prefix` | 3 | Every link in `html` to an article that has no address in your CMS yet is `link_placeholder_prefix` followed by that article’s `article_id`. Replace it with your address of that article, or keep only the link text. Do not hard-code the prefix. |
| `evergreen` | 3 | True when the article stays current (not tied to news). |
| `generated_images` | 3 | Images made for the article; the first one is the main image. |
| `seo_title` | 3 | The chosen SEO title. |
| `meta_description` | 3 | The chosen meta description. |
| `focus_keyphrase` | 3 | The focus keyphrase for SEO. |
| `ai_provenance` | 3 | AI provenance statement, also embedded in `html`; null when the newsroom turned marking off. |
| `sent_by` | 3 | Who clicked "Send to CMS"; null for automatic sends. |
| `author` | 3 | Who ordered the article. Use it as the post author when `sent_by` cannot be matched. |
| `backlink_sources` | 3 | Older articles that should link to this one. Omitted = leave existing links alone; an empty list = no article should link here. |
| `topic` | optional | The topic the article was written on. |
| `alternative_titles` | optional | Other headline proposals; may be empty. |
| `twitter_posts` | optional | Proposed posts for X. |
| `reel_captions` | optional | Proposed captions for reels. |
| `custom_content` | optional | Extra text blocks defined by the newsroom. |
| `facebook_title` | optional | The chosen title for Facebook. Use it only on Facebook, not as the post title. |
| `facebook_titles` | optional | All title proposals for Facebook. |
| `facebook_teaser` | optional | The chosen teaser for Facebook. |
| `facebook_teasers` | optional | All teaser proposals for Facebook. |
| `raw_facts` | optional | Facts the article was written from, one per line. |
| `related_topics` | optional | Follow-up topics. |

## The HTML body

`html` is the article body as the writer and the editor left it. Tribune Desk does not reduce it to a fixed set of elements: an editor can edit the raw HTML. Treat it as content from a trusted editor and pass it through the filter your CMS applies to editor content.

- **Usual elements:** `h2`, `h3`, `p`, `strong`, `b`, `em`, `i`, `br`, `ul`, `ol`, `li`, `blockquote` and `a`. The headline is not in `html`: it is `title`.
- **Links:** `<a href="…">`; when the newsroom opens links in a new tab, `target="_blank" rel="noopener"`; a sponsored link carries `rel="sponsored"`. Links to other articles of the newsroom are described in level 3.
- **No media in the body:** Tribune Desk puts no `img`, `figure` or `iframe` in `html`; images come as `featured_image` and `generated_images`. An editor may still paste an embed by hand.
- **The AI label block:** when the newsroom marks AI content, `html` ends with `<script type="application/ld+json" data-hf-provenance>…</script>`, the same statement as `ai_provenance` (see level 3). Keep it if your CMS allows it, or drop it and render `ai_provenance` in the page head instead.

## Fields of your answer

The body of your answer (`ArticleDeliveryResult`) is optional: a `2xx` alone means delivered. Send it as `application/json`, up to 64 KB. Tribune Desk reads each field on its own and ignores one that does not match its type. A field you leave out keeps the value Tribune Desk stored before: an answer with only `edit_url` does not erase the `cms_post_id` from an earlier answer.

| Field | Level | Meaning |
|---|---|---|
| `cms_post_id` | 1 | Your identifier of the post, as text. Tribune Desk stores it with the article. |
| `status` | 1 | `created`, `updated`, or `skipped_already_published` when you left an already published post alone. Informational: Tribune Desk does not act on it today. |
| `edit_url` | 1 | An `http(s)` address where the editor edits the post; shown as "Edit in CMS". Other schemes are ignored. The delivery is a server-to-server call with no editor logged in, so build the address without checking the current user's permissions: a CMS helper that returns the edit link for the current user (in WordPress `get_edit_post_link()`) returns nothing there. |
| `permalink` | 2 | The public address of the post. Tribune Desk uses it to link to this article from other articles. |
| `featured_image` | 4 | The attachment made from the featured image, so Tribune Desk can reuse it next time. |
| `social_queue_exclude` | 2 | `true` keeps the post off the Tribune Desk Facebook queue, `false` puts it back; the same flag as in the link back. Send it together with `permalink` when you publish at once, so a flagged post never enters the queue. Leave it out to keep the stored value. |
| `post_id` | – | Deprecated: the same as `cms_post_id`, read only when that is absent. |

## Where images come from

Image URLs in deliveries come from the Tribune Desk file storage or from your own CMS (an image your CMS already holds). Download images only from these hosts and refuse any other address. `GET /v3/cms/status` (with `articles:read` or `media:read`) and `GET /v3/cms/settings` list them as `image_hosts`; the list is never empty, so treat an empty one as an error and download nothing. Without a token, allow the Tribune Desk storage hosts your contact gives you, the domain of your organization and the host of your webhook. Download in the background if you can: the answer must come within 30 seconds, image or not.

## Example

A working receiver in Python, with no dependencies, is in [`examples/python/level1_receiver.py`](../examples/python/level1_receiver.py). It verifies the signature, makes or updates a draft and answers with the result. Prefer an official Standard Webhooks library in your own code; the example shows what such a library checks. A complete sample body is in [`examples/payloads/article-delivered.json`](../examples/payloads/article-delivered.json).

## Done when

- An editor's “Send to CMS” makes a draft post with `title`, `html`, `excerpt` and `featured_image`.
- Your answer carries `cms_post_id`, `status` and `edit_url`, so the editor gets an “Edit in CMS” link.
- A request whose signature does not verify, or whose `webhook-timestamp` is more than 5 minutes off, gets `401` and changes nothing.
- A repeated attempt with the same `webhook-id` makes no second draft, and a new send of the same `article_id` updates the post you made the first time.
- Fields you do not know are ignored, not rejected; do not validate the body with a schema that rejects unknown properties.
- An attempt of an older send that arrives after a newer one does not overwrite the post.
- Two attempts of one send that overlap make one post, not two.
- Images are downloaded only from the hosts in `image_hosts`; an image on any other host is not fetched.

In read mode, also:

- Every result carries the `delivery_id` of the send it answers, or `error` when your CMS could not take the article.
- After `409 state_conflict` from the result, the article is fetched again and the newer send is answered.
- After `410 cursor_expired`, the sends listed by `GET /v3/cms/articles/awaiting` are handled before polling `GET /v3/cms/changes` again without a cursor.
