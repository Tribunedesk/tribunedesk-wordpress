# Level 2 – Link back

## Reporting the published address

When a post made from a delivered article goes public, call `POST /v3/cms/articles/published` with a token that has `articles:write`. Send `items`, 1 to 100 per request, each with `article_id` (from the delivery), `url` (the public `http(s)` address) and, optionally, `published_at` (ISO 8601 with a time zone; a time without one fails the whole request with `422`). The answer has one result per item, in order: `ok`, `not_found` (no such article in the organization of the token) or `invalid_url`. Older clients may still expect `forbidden`; it is no longer returned. A bad item does not fail the others. The route shares the limit of the token (600 requests per minute).

Answering a delivery with `permalink` (and `social_queue_exclude`, below) does the same for a post published at once; use this route for posts published later. When a post is unpublished, Tribune Desk keeps the last address: no route takes it back today, so send the new address when the post comes back or moves.

## Keeping a post off Facebook

If your CMS has a setting that keeps a post off Facebook, send it in each item as `social_queue_exclude`. With `true`, Tribune Desk does not add the article to its Facebook queue, and an article already waiting there is taken off every queue; posts already published stay. With `false`, the post may go to Facebook again: an article that never reached the queue and was published at most 48 hours ago joins it as a new article, anything else only when an editor adds it. An editor can still add an excluded article by hand. Leaving the field out keeps the stored value, so a client that does not know the field never clears it. Once your CMS has the setting, send the field every time, also as `false`; otherwise clearing the setting never reaches Tribune Desk. Send the item again when the setting changes on a published post; the same address changes nothing else.

A working client in Python is in [`examples/python/level2_link_back.py`](../examples/python/level2_link_back.py).

## Done when

- Every post made from a delivered article is reported once it is public, either by `permalink` in the delivery answer or by `POST /v3/cms/articles/published`.
- Reporting the same post again does no harm, and an item answered `not_found` or `invalid_url` is logged, not retried unchanged.
- Your token for this route is a `tdcms_` token with `articles:write`, not the webhook secret.
- If your CMS has a setting that keeps a post off Facebook, every report carries `social_queue_exclude`, `false` included.
