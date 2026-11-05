<?php
declare(strict_types=1);

namespace TribuneDesk\Protocol;

/**
 * Builds the answer to an article delivery (ArticleDeliveryResult).
 *
 * Level: 1 (permalink: 2, featured_image: 4) · Routes: webhook article.delivered · Guide: guide/1-article-delivery.md#fields-of-your-answer
 *
 * `cms_post_id` is the contract field (text); `post_id` stays for Tribune Desk versions that read
 * it. `permalink` only for a public post: a draft has no public address, and the edit URL is
 * never a permalink. `featured_image` only when the main image came from Tribune Desk (asset_id
 * known), so Tribune Desk can reuse the file next time.
 */
final class DeliveryAnswer
{
    /**
     * @param array{asset_id: string, attachment_id: int, url: string}|null $featured_image
     * @return array<string, mixed>
     */
    public static function build(int $post_id, string $status, ?string $edit_url, ?string $permalink, ?array $featured_image): array
    {
        $answer = [
            'cms_post_id' => (string) $post_id,
            'post_id'     => $post_id,
            'edit_url'    => $edit_url,
            'status'      => $status,
        ];
        if ($permalink !== null && $permalink !== '') {
            $answer['permalink'] = $permalink;
        }
        if ($featured_image !== null) {
            $answer['featured_image'] = $featured_image;
        }

        return $answer;
    }

    /** The answer to a delivery that failed on our side: no internals, the text stays in the log. */
    public static function internal_error(): array
    {
        return ['error' => 'internal_error'];
    }
}
