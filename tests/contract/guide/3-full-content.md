# Level 3 – Full content

Level 3 uses the same deliveries as level 1 and reads more of their fields. Nothing here needs a new route.

## Internal links

Links in `html` to other articles of your organization point either to your address of that article (when Tribune Desk knows it, from `permalink` or the link back) or to a placeholder: `link_placeholder_prefix` followed by the target's `article_id`. Do not hard-code the prefix; read it from each delivery. When you publish, replace each placeholder with your address of the article with that `article_id`; when you have none, leave the text of the link and drop the link itself. Later, when the target is published, you may go back to earlier posts and replace the placeholder there. `internal_links` lists every such link with its `kind` (`cms` or `placeholder`).

Each entry of `internal_links` has:

- `href`: the address the link points to in `html`, your address or the placeholder;
- `article_id`: the Tribune Desk id of the linked article, the key to find your post;
- `kind`: `cms` when the linked article has an address in your CMS, `placeholder` otherwise; `null`, together with a `null` `article_id`, for a link to a page outside Tribune Desk: leave such a link as it is;
- `anchor_text`, `url` and `headline`: the text of the link, the current address of the linked article and its headline.

Step by step, when a delivery arrives:

1. For each entry with `kind` = `placeholder`, look up your post made from `article_id`.
2. Found and public: replace `href` in `html` with the post's address.
3. Not found or not public: leave the text of the link and drop the link itself.
4. Keep the entry, so you can do step 2 later, when that post is published.

## The “read more” box

`backlink_sources` lists older articles that should link to this one, each with `article_id` and `distance` (lower means closer in topic). Use it to show this article in a “read more” box on those older posts. The rules:

- a missing key means: leave the existing links alone;
- an empty list means: no older article should link here; remove this article from their boxes;
- a list replaces the previous one for this article;
- `null` means the same as a missing key.

How the box looks and where it goes is up to your CMS. Tribune Desk only says which articles belong together.

## SEO, author and language

`seo_title`, `meta_description` and `focus_keyphrase` are the editor's choice for search engines. `author` is who ordered the article; use it as the post author when `sent_by`, who clicked “Send to CMS”, cannot be matched to a user of your CMS. Match a person first by `id` in your own mapping of Tribune Desk users to CMS users, then by `email`; `name` is for display only. Without a match use your default author. `language` is the newsroom's writing language, a BCP 47 tag (for example `pl`), when it is set. `evergreen` is informational: the article stays current, so you may keep it out of date-based archives. Text fields have no length limit on the Tribune Desk side: cut them to the limits of your CMS.

## The AI label

Under the EU AI Act (art. 50(2)) text produced with AI must be marked in a machine-readable way. `ai_provenance` is that mark and the source of truth: `claims` (what is stated: `article_id`, `generated_at`, `content_sha256`, `generator`, `v` (the version of this claims format), `models`, `digital_source_type`) and `jws`, the claims signed as a compact JWS. It is `null` when the newsroom turned marking off.

Show it in the `<head>` of the public page as JSON-LD, for example:

```
<script type="application/ld+json">
{"@context": "https://schema.org", "@type": "NewsArticle",
 "identifier": "<claims.article_id>", "dateCreated": "<claims.generated_at>",
 "digitalSourceType": "<claims.digital_source_type>", "hfProvenanceJws": "<jws>"}
</script>
```

The end of `html` carries the same statement as a `<script type="application/ld+json" data-hf-provenance>` block. It is a copy kept for integrations that publish `html` as it is; when you put the JSON-LD in `<head>`, you may remove the block from the body.

Anyone can check the mark without a token, at `https://api.tribunedesk.com`:

- `GET /.well-known/hf-provenance.json` returns the public key (a JWK Set) to verify `jws`, and who the producer is;
- `POST /v2/verify` with `{"text": "..."}` answers whether the text came from Tribune Desk (`generated`) and the date it first did (`generated_at`). It never says which organization produced it. Send the article body: `html` as delivered or its plain text, without the headline (`title` is not part of `html`). Before the check Tribune Desk drops tags and `<script>` blocks, lowercases the text and collapses whitespace, so markup and the provenance block make no difference, but any change to the words does: an edited article answers `generated: false`. The route allows 30 requests per minute from one IP address and answers `429` above that; `503` means the registry is unavailable for a moment, so try again later.

## Done when

- Placeholders are replaced with your addresses, or the link text is left without a link. Going back to earlier posts when a target is published is optional.
- The “read more” box follows `backlink_sources`, including the missing-key and empty-list rules.
- SEO fields, author and language are stored on the post.
- The AI label is in the `<head>` of every public page made from a delivery whose `ai_provenance` is not `null`.
