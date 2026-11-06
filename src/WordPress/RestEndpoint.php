<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

use TribuneDesk\Protocol\DeliveryAnswer;
use TribuneDesk\Protocol\WebhookSignature;

if (!defined('ABSPATH')) {
    exit;
}

class RestEndpoint
{
    /**
     * Legacy namespace. STAYS FOREVER — it is the one stored in `webhook_url`
     * in our database for every existing installation, and the client may never
     * update the plugin. Removing it would break deliveries silently: the plugin
     * would answer with WordPress's 404, which looks like a failure of the client's site in the logs.
     */
    public const NAMESPACE = 'headlinesforge/v1';

    /**
     * Namespace under the new brand. ADDED ALONGSIDE, not instead. The address under the new name must
     * exist before we give it to anyone — switching `webhook_url`
     * on our side must hit an endpoint that already works, not the other way round.
     */
    public const NAMESPACE_BRAND = 'tribunedesk/v1';

    public const ROUTE = '/receive';
    public const OPTION_DEFAULT_AUTHOR = 'headlinesforge_default_author_id';
    public const OPTION_PUBLISH = 'headlinesforge_publish_immediately';

    /**
     * Standard Webhooks signing secret (whsec_...). Every delivery must carry a valid signature;
     * without this secret every delivery is refused (2.2.0: Tribune Desk stopped sending the old
     * shared-secret header, E7). The old option `headlinesforge_webhook_secret` stays in
     * the database untouched, so going back to 2.1.0 still works; uninstall removes it.
     */
    public const OPTION_SIGNING_SECRET = 'headlinesforge_signing_secret';

    public static function register_routes(): void
    {
        // The SAME callback under both addresses — not a copy, not a wrapper. Two paths
        // to one function cannot drift apart; two functions could.
        foreach ([self::NAMESPACE, self::NAMESPACE_BRAND] as $namespace) {
            register_rest_route($namespace, self::ROUTE, [
                'methods'             => 'POST',
                'callback'            => [self::class, 'handle'],
                'permission_callback' => '__return_true', // we do our own header-based auth
            ]);
        }
    }

    /** A valid Standard Webhooks signature; no signing secret means no delivery gets in. */
    private static function authorized(\WP_REST_Request $request): bool
    {
        $signing_secret = trim((string) get_option(self::OPTION_SIGNING_SECRET, ''));

        return $signing_secret !== ''
            && WebhookSignature::verify($signing_secret, $request->get_headers(), $request->get_body());
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!self::authorized($request)) {
            return new \WP_REST_Response(['error' => 'unauthorized'], 401);
        }

        $payload = $request->get_json_params();
        if (!is_array($payload) || !isset($payload['article_id'], $payload['topic'], $payload['html'])) {
            return new \WP_REST_Response(['error' => 'missing required fields: article_id, topic, html'], 400);
        }

        // Author from the people sent by Tribune Desk, or the default author without them (1.2.0).
        $author_id = AuthorResolver::resolve($payload);

        try {
            $publish = get_option(self::OPTION_PUBLISH, '') === '1';
            $result = PostWriter::write($payload, $author_id, $publish);
            DeliveriesLog::record(
                (string) $payload['article_id'],
                $result['post_id'],
                $result['status']->value
            );
            // URL of the file in the CMS media library (spec HF D7) — on EVERY delivery, not only when
            // the image was set a moment ago. Without the _hf_asset_id marker the key is absent.
            $featured = null;
            $thumb_id = (int) get_post_thumbnail_id($result['post_id']);
            if ($thumb_id > 0) {
                $asset_id = (string) get_post_meta($thumb_id, MediaHandler::META_ASSET_ID, true);
                if ($asset_id !== '') {
                    $url = wp_get_original_image_url($thumb_id);
                    if (!is_string($url) || $url === '') {
                        $url = wp_get_attachment_url($thumb_id);
                    }
                    if (is_string($url) && $url !== '') {
                        $featured = [
                            'asset_id'      => $asset_id,
                            'attachment_id' => $thumb_id,
                            'url'           => $url,
                        ];
                    }
                }
            }
            // Not get_edit_post_link(): it returns null unless the current user may edit the post,
            // and a delivery runs as user 0 (signature auth, no login) — the link never came back.
            $edit_url = admin_url('post.php?post=' . (int) $result['post_id'] . '&action=edit');
            $response = DeliveryAnswer::build(
                $result['post_id'],
                $result['status']->value,
                $edit_url,
                // Only a public post has a permalink; never the wp-admin edit URL (spec HF D11).
                get_post_status($result['post_id']) === 'publish' ? (string) get_permalink($result['post_id']) : null,
                $featured
            );

            return new \WP_REST_Response($response, 200);
        } catch (\Throwable $e) {
            DeliveriesLog::record(
                (string) $payload['article_id'],
                null,
                'error',
                $e->getMessage()
            );
            // The message stays in the deliveries log; the answer carries no internals
            // (spec D10 "Security": 500 without the exception text).
            return new \WP_REST_Response(DeliveryAnswer::internal_error(), 500);
        }
    }
}
