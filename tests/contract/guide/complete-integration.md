# A complete integration in any CMS

The level chapters say what each field and route means. This chapter says what a complete integration does with them in production: what runs in the background, how the HTML is filtered, what each new send of an article changes in a post, and how images, the link back and categories behave around the edges. The Tribune Desk plugin for WordPress follows these rules; with them you can build the same behaviour in any CMS, including one you wrote yourself. Where the plugin is known to fall short of a rule, follow the rule here, not the plugin.

Words used below: a post is **not public** when it is a draft, waits for review or is scheduled; it is **public** when it is published. A private post counts as public: leave it alone like a published one.

## What your CMS needs

- **A unique key and a lock per article.** Keep `article_id` unique on the post, and serialise the writes of one article (a database lock or a row lock held while you write). Two attempts of one send can overlap.
- **Background work.** A scheduler or a queue that runs jobs later (cron, a job queue, a timer). Without one, images that failed to download, failed link backs, scheduled posts and the daily category sync never happen. See [Background jobs](#background-jobs).
- **An HTML sanitizer built on a parser**, configured as in [Filtering the HTML](#filtering-the-html). Do not write it with regular expressions.
- **An HTTP client with timeouts** that does not follow redirects when it sends the token.
- **Storage for settings and a short delivery log** (see [Settings and operation](#settings-and-operation)).
- **A server that runs requests in parallel** when you test. A single-threaded development server (for example `php -S`) answers one request at a time, so a test of overlapping attempts passes without proving your lock. Test that case on a server with several workers.

## Background jobs

| Job | When | What it does | Level |
|---|---|---|---|
| Image retry | 1 minute after a delivery that could not download its images in time | Downloads the missing featured image and body images with a longer limit (60 seconds) and puts them on the post. At least one retry; more with back-off are fine. A refused host is not retried. | 1 |
| Link back retry | After a network error, `429` or `5xx` | Sends the report again after `Retry-After`, or after 5 minutes. At least one retry; more with growing gaps are better. After the last one, log it: the backfill sends it later. | 2 |
| Bulk publishing | When an editor publishes many posts at once | Queues one report per post instead of calling Tribune Desk inside the editor's request. Queued reports get the same retries as any other. | 2 |
| Scheduled publishing | When your CMS publishes a scheduled post | Runs the same [publish steps](#when-a-post-becomes-public) as a post published by hand. | 2, 3 |
| Image hosts | Once an hour, when you have a token | Reads `image_hosts` from `GET /v3/cms/status`; after a failed read, keep the last list and try again in an hour, not on every image. | 1 |
| Category sync | Once a day, and once right after a token is saved | A full run of `POST /v3/cms/taxonomy/terms`, plus the queue of single changes. See [Categories and tags](#sending-and-setting-terms). | 3 |
| Media sync | As often as guide 4 says | Polls `GET /v3/cms/changes` and sends your changes. | 4 |

A job that writes a post takes the same lock per article as a delivery, and holds it only while it writes: download first, then take the lock, read the post again and write. A job that holds the lock while it downloads blocks the deliveries of that article, and Tribune Desk sees them fail.

## Filtering the HTML

Treat `html` as content from a trusted editor that still must not run code on your pages. Steps, in this order:

1. Take the AI label block (`<script type="application/ld+json" data-hf-provenance>`) out of the raw `html`. Keep it aside if you publish it in the body; otherwise drop it, since `ai_provenance` carries the same statement (see [Storing the AI label](#storing-the-ai-label)). After this step, the filter may remove every `script`.
2. Pass the rest through your sanitizer with an allow list:
   - **Tags:** at least `p`, `h2`–`h6`, `strong`, `b`, `em`, `i`, `u`, `br`, `hr`, `ul`, `ol`, `li`, `blockquote`, `a`, `figure`, `figcaption`, `img`, `table` with its parts, and `iframe` under the rule below. Remove `script`, `style`, `object`, `embed`, `form`, `svg` and `math` together with their content; any other tag that is not on the list loses the tag and keeps its text.
   - **Attributes:** keep `href`, `target`, `rel` and `title` on `a`; `src`, `alt`, `width`, `height` and every `data-td-*` attribute on `img`; `class` on `figure`. Remove every `on*` attribute and `style` (unless your sanitizer filters CSS).
   - **Addresses** in `href` and `src`: only `https:`, `http:`, `mailto:`, relative addresses and `#` anchors. Remove `javascript:`, `data:`, `vbscript:` and any other scheme, also when it is written with entities, upper case or whitespace inside.
   - **Embeds:** keep an `iframe` only when its `src`, parsed as a URL, is `https`, its host is exactly a host you trust and its path starts with the player path (for example `www.youtube.com` and `/embed/`). Keep only `src`, `width`, `height`, `frameborder`, `allowfullscreen`, `allow` and `title`. An `iframe` with two `src` attributes, or one your parser cannot read, is removed.
3. Resolve internal links (guide 3), then turn library figures into your native images (see [Images](#images-in-detail)).

The delivery runs with no editor logged in. If your CMS filters content again on save by the rights of the current user, make sure that second filter keeps the same embeds, or they vanish on save. If the sanitizer fails, store nothing and answer `500`: Tribune Desk tries again. Never store the unfiltered body.

Use a maintained sanitizer: in PHP `symfony/html-sanitizer` or HTML Purifier, in Python `nh3`, in Node `sanitize-html` or DOMPurify, in Ruby `rails-html-sanitizer`, in Go `bluemonday`, in Java the OWASP Java HTML Sanitizer.

These must not reach the post: `<img src="x" onerror="alert(1)">` (the attribute is removed), `<a href="javascript:alert(1)">`, `<a href="JaVa&#115;cript:alert(1)">`, `<svg onload="alert(1)">`, an `iframe` from `www.youtube.com.example.net`, an `iframe` without `</iframe>`, and an `iframe` from an untrusted host whose `title` holds a trusted address. These must survive: a YouTube player `iframe`, `<figure class="alignleft"><img src="…" data-td-asset-id="…" data-td-cms-media-id="…"></figure>`, `<a href="https://…" target="_blank" rel="noopener">`.

## One delivery, step by step

1. Verify the signature on the raw body (guide 1). Answer `401` only when it does not verify.
2. Parse the body; without `article_id` or `html` answer `400`. Ignore fields you do not know.
3. Take the lock of the article. If it is not free within about 20 seconds, answer `503`: Tribune Desk tries again.
4. Find the post by `article_id`. A post an editor moved to the trash still counts: restore it as a draft and update it, so the article never gets a second post. If its stored `sent_at` is newer than the body's, apply nothing and answer `2xx` with the post's current state (`status` `updated`).
5. Filter the HTML and resolve links as above.
6. Create or update the post as the table below says, then the images, the SEO fields and the AI label.
7. Store `sent_at`, release the lock and answer with `cms_post_id`, `status`, `edit_url`, `publication`, and `permalink` when the post is public. Answer `500` with no internal detail when something failed; keep the detail in your log.

## What each send changes

| Part of the post | New post | Post not public yet | Public post |
|---|---|---|---|
| Title | `title`, or `topic` when there is no title | replaced | left alone |
| Body | filtered `html` | replaced | left alone |
| Author | [chosen](#choosing-the-author) | chosen again from this send | left alone |
| Excerpt | `excerpt` | filled only when the post has none: an excerpt an editor wrote survives | left alone |
| SEO title, meta description, focus keyphrase | set | filled only when empty | filled only when empty |
| Featured image | set | replaced when the delivery's main image differs from the one the last delivery set, unless an editor chose another image by hand | set only when the post has none |
| Body images | converted | converted | left alone |
| AI label (`ai_provenance`) | stored | replaced, or removed when it is `null` | left alone |
| Internal links data | stored | replaced | left alone |
| `backlink_sources` | stored | stored | stored, and the “read more” rows rebuilt |
| Categories and tags | set | set | left alone |
| Language | set | left alone (an editor may have moved the post) | left alone |
| Publication state | [as asked](#publication-state-and-dates) | as asked | left alone |
| Answer `status` | `created` | `updated` | `skipped_already_published` |

The right column is why `skipped_already_published` still matters: a featured image made after the first send, SEO fields left empty and a new “read more” list reach a published post. Its content, title and author stay as the newsroom left them.

## Publication state and dates

- `draft`: the post stays unpublished and has no publication date of its own.
- `publish`: the post is published now and its date is now, also when it was scheduled for later.
- `schedule`: the post is scheduled for `at`, converted from UTC to your site's time zone. An `at` without a time zone offset, or one you cannot read, is ignored like an unknown field. An `at` already past is published now.
- No `publication`: a new post follows your site's own setting (draft by default, or published at once if the site chose so); an existing post keeps its state.

Give the site a way to limit this: a setting that keeps Tribune Desk from publishing (off by default, so Tribune Desk may publish). When it is on, treat `publish` and `schedule` as if the delivery had no `publication`: a new post follows the site's own setting, an existing one keeps its state; `draft` still works. Answer with the state the post really has, so a newsroom whose CMS controls who may publish keeps that control.

Answer `publication` from the post as it is after the write: a draft or a post waiting for review gives `draft` with `at` `null`; a scheduled post `scheduled` with its time; a published post `published` with its time. Leave `publication` out for a private post or any other state.

## Images in detail

**Find before you download.** For the featured image (`generated_images[0]`) and for each body figure with `data-td-asset-id`, look for a file you already have, in this order:

1. Your id from Tribune Desk (`cms_attachment_id` or `cms_media_id` of the image, `data-td-cms-media-id` of a body image). Use it only when that file exists and your records tie it to this image: it carries the same `asset_id`, or it carries none and either its address or the address you downloaded it from equals the image's URL, or you sent the file to Tribune Desk yourself (level 4). A file with another `asset_id`, or one never sent and with another address, is not this image, even when it is an image: ignore the hint and go on.
2. The file you recorded with this `asset_id`.
3. The file you downloaded earlier from the same URL.
4. A file whose URL is in your own media storage.

Record the `asset_id` on the file you found or downloaded. A file you already had keeps its title, alt text, caption and description: the newsroom may have corrected them.

**Download within budget.** Download only `https` addresses on a host in `image_hosts`; log a refused host and do not retry it. Inside a delivery give the featured image about 15 seconds and all body images together about 10 seconds, so the answer leaves within 30 seconds. What does not fit is saved without the image and comes from the [image retry](#background-jobs).

**Name and describe new files.** Name the file from `filename`, or from a slug of the title cut to about 60 characters; take the extension from the URL when it is an image type, else `jpg`. Do not use the last part of the URL: Tribune Desk serves many images under the same file name. Set the title, alt text, caption and description from the image on a file you downloaded now. Mark it as coming from Tribune Desk, so that level 4 does not send it back as a new file.

**Body figures.** A `figure` with exactly one `img` and `data-td-asset-id` becomes your native image with its alt text, caption and alignment (`alignleft`, `alignright`, `aligncenter`). A `figure` without `data-td-asset-id` is converted only when the file already exists in your library; never download a file nobody pointed at. A figure you do not convert stays byte for byte.

## When a post becomes public

Whatever made it public (an editor, your scheduler, bulk publishing, an API call):

1. **Resolve its own links.** Replace each placeholder whose target is public with the target's address. On a public page no placeholder address may stay as a link: it leads to the Tribune Desk app behind a login. Show only the link text, and keep the `internal_links` data, so the link can come back when the target is published.
2. **Repair earlier posts.** Find the public posts whose `internal_links` point to this article and resolve their placeholders. Bound the work of one publication (the WordPress plugin repairs up to 50 posts) and log when you hit the bound; the rest are repaired when they are saved.
3. **Rebuild the “read more” rows** of this post from its stored `backlink_sources`: only sources that are public, never the post itself.
4. **Report the address** with the link back.

Report the address again when a public post's address changes (a new slug or date) and when its keep-off-Facebook setting changes. Other saves of a public post report nothing.

## Reporting addresses

- Send `article_id` in lower case and skip posts whose `article_id` is not a UUID: one bad id fails the whole request with `422`.
- `published_at` is the publication time in UTC with an offset. If your CMS has no UTC time for a post published by the scheduler, convert the local one.
- Inside an editor's request, wait at most about 5 seconds; never block publishing on Tribune Desk. A failure goes to the [retries](#background-jobs).
- Posts that were public before your integration had its token: offer a backfill that sends them in requests of up to 100, oldest first, with a cursor so an interrupted backfill goes on where it stopped. On `429` wait `Retry-After` and send the same request again.
- Log each item's result: `not_found` and `invalid_url` are different faults; do not retry them unchanged.

## Sending and setting terms

**Setting the terms of a delivery** (guide 3): look a term up by `cms_term_id` in the right taxonomy, then by `name` ignoring letter case, and accents too if your database compares that way. A term found only in the other taxonomy is `wrong_taxonomy`; one found nowhere is `unknown_term`. A non-empty list of which no term was found leaves that taxonomy alone: only a genuine empty list clears it (and gives the default category). Terms without a name are dropped. Set the terms before the post is saved and published, because a permalink may contain the category.

**Sending your terms:**

- A full run once a day, and right after a token is saved: pages of up to 1000 terms in order of your id, the same `run_id` on every page, `is_last: true` on the last.
- Store the position after each page Tribune Desk accepts, so an interrupted run goes on from there. A run open for more than 23 hours starts over with a new `run_id`: Tribune Desk refuses the last page of a run open for 24 hours.
- One run at a time: take a lock for the run.
- Send names as plain text (decode the HTML entities your CMS may store) and skip a term without a name.
- Single changes go to a queue that a background job sends without `run_id`: a new or renamed term with its current name, a deleted one with `deleted: true` and the name it had. A row leaves the queue only after Tribune Desk accepts it. On `403` (no `articles:write`) or `501` keep the queue; the next full run carries the terms anyway. If the queue grows too large, drop changes: the full run makes up for them.

## Choosing the author

Try `sent_by`, then `author`. For each person, first your own mapping of Tribune Desk ids to users, then a user with the same e-mail. Only a user allowed to write posts counts: a reader account with the same e-mail does not become the author. No match: the site's default author. Remember the people you have seen (id, name, e-mail, last seen), so the settings screen can offer them for the mapping.

## SEO and other fields

- SEO fields fill only empty values; you may also copy `meta_description` and `facebook_title` into fields your theme reads, under the same rule.
- An option may put `facebook_teaser` into the excerpt ahead of `excerpt`, for a theme that uses the excerpt as the social description.
- `language` is set only on a new post. A language your CMS does not have leaves the default and goes to the log.
- Text from deliveries is plain text: strip tags and collapse whitespace before you store a title, excerpt or SEO field.

## Storing the AI label

Store `ai_provenance` (its `claims` and `jws`) with the post: it is the source of truth, and the block at the end of `html` is a copy of it. When the post gets new content, replace the stored label; a send whose `ai_provenance` is `null`, or one you cannot read, removes it, because an old signature next to new text looks like a forgery. A public post keeps its label. Print it in the `<head>` of the post's page as JSON-LD (guide 3) by encoding the JSON yourself, not by printing text from the delivery, so that `</script>` inside a value cannot end the element. If your SEO layer already describes the article as JSON-LD, add the label fields to that object instead of printing a second one.

## Settings and operation

A settings screen (or a configuration file) with:

- the signing secret and the API token, stored outside source control and never shown again in full;
- the API address, `https://api.tribunedesk.com` by default; refuse an `http` address, and never follow a redirect with the token;
- the default author, the mapping of Tribune Desk people to users, publish at once or keep drafts, and whether Tribune Desk may publish;
- the optional field mappings (SEO fields of your theme, the keep-off-Facebook field, the teaser as excerpt);
- the image hosts in use, a backfill button for the link back and a button for a full category run.

A delivery log of the last few dozen events (deliveries, link backs, image failures, category runs), with media events kept apart so they do not push the deliveries out. Log the reason in words; never log a secret or a token.

If your integration updates itself, check the signature of each package before you install it.

## Done when

- A background job runs the image retries, the link back retries, the reports of bulk and scheduled publishing, the hourly image hosts and the daily category run.
- Two overlapping attempts of one send, tested on a server with several workers, make one post.
- None of the unsafe samples in [Filtering the HTML](#filtering-the-html) reaches the post, and the safe ones survive; the delivery filters the same with no user logged in.
- A new send changes each part of the post as [the table](#what-each-send-changes) says; a published post gets a late featured image and empty SEO fields filled, and keeps its title, body and author.
- A scheduled post published by your scheduler is reported with the link back and its placeholders are resolved; no public page links to a placeholder.
- An image already in your library is reused, not downloaded again; a hint naming a file that is not that image is ignored.
- A failed link back is sent again later, also one from bulk publishing.
- An interrupted category run goes on from its last accepted page, and a run left open starts over before 24 hours pass.
- The settings let the site keep Tribune Desk from publishing: `publish` and `schedule` then count as a missing `publication`, and the answer reports the post's real state.
