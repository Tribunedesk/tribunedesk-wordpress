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
    public const OPTION_SECRET = 'headlinesforge_webhook_secret';
    public const OPTION_DEFAULT_AUTHOR = 'headlinesforge_default_author_id';
    public const OPTION_PUBLISH = 'headlinesforge_publish_immediately';

    /**
     * Standard Webhooks signing secret (whsec_...). When set, every delivery must carry a valid
     * signature and X-Webhook-Secret is ignored; when empty, the old shared-secret check stays
     * (spec 2026-11-03 open CMS API, D4 "transition").
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

    /**
     * Signature when a signing secret is set (the old secret is then ignored, so it cannot be a
     * way around the signature); otherwise the old X-Webhook-Secret comparison.
     */
    private static function authorized(\WP_REST_Request $request): bool
    {
        $signing_secret = trim((string) get_option(self::OPTION_SIGNING_SECRET, ''));
        if ($signing_secret !== '') {
            return WebhookSignature::verify($signing_secret, $request->get_headers(), $request->get_body());
        }

        $stored_secret   = (string) get_option(self::OPTION_SECRET, '');
        $received_secret = (string) $request->get_header('x_webhook_secret');

        return $stored_secret !== '' && hash_equals($stored_secret, $received_secret);
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
            $edit_url = get_edit_post_link($result['post_id'], 'raw');
            $response = DeliveryAnswer::build(
                $result['post_id'],
                $result['status']->value,
                is_string($edit_url) ? $edit_url : null,
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
