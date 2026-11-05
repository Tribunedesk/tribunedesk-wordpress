<?php
declare(strict_types=1);

namespace TribuneDesk\WordPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Deletions between the CMS and Tribune Desk (spec HF media sync D7). Towards TD: a queue of IDs
 * from the deletion hook, sent in cron by CmsSync::run_send, because the admin can't wait for HTTP.
 * Towards the CMS: the feed sets or removes a hidden marker and nothing more. The plugin does not delete files
 * or move them to trash; a file used in published content does not get the marker.
 */
class CmsDeletions
{
    public const OPTION_QUEUE       = 'headlinesforge_cms_deletions';
    public const TRANSIENT_OVERFLOW = 'headlinesforge_cms_deletions_overflow';
    public const META_DELETED       = '_hf_deleted_in_td';
    public const QUERY_VAR          = 'hf_deleted_in_td';
    public const MAX_QUEUE          = 5000;

    private const LOCK_WAIT = 5;

    /** Only a local save and scheduling the send: may fire a thousand times in one admin job. */
    public static function on_delete_attachment(int $id): void
    {
        if (!CmsApiClient::has_token() || !str_starts_with((string) get_post_mime_type($id), 'image/')) {
            return;
        }
        self::locked(static function () use ($id): void {
            $queue = self::queue();
            if (isset($queue[$id])) {
                return;
            }
            if (count($queue) >= self::MAX_QUEUE) {
                // The oldest stay, because their time has passed and nothing will recreate them.
                set_transient(self::TRANSIENT_OVERFLOW, (int) get_transient(self::TRANSIENT_OVERFLOW) + 1, WEEK_IN_SECONDS);
                return;
            }
            $queue[$id] = time();
            update_option(self::OPTION_QUEUE, $queue, false);
        });
        CmsSync::schedule_send();
    }

    /**
     * Oldest first: attachment ID => deletion time.
     *
     * @return array<int, int>
     */
    public static function queue(): array
    {
        // Another job may have added a row, and a persistent object cache would hold the old value.
        wp_cache_delete(self::OPTION_QUEUE, 'options');
        wp_cache_delete('notoptions', 'options');
        $queue = get_option(self::OPTION_QUEUE, []);

        return is_array($queue) ? $queue : [];
    }

    /**
     * The library grid deletes files in parallel jobs, and reading and writing options is not atomic.
     * A lock held longer than a few seconds does not stop the deletion: the save goes ahead anyway.
     */
    private static function locked(callable $change): void
    {
        global $wpdb;
        $name = 'hf_cms_deletions_' . md5($wpdb->options);
        $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, self::LOCK_WAIT));
        try {
            $change();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    /**
     * Called in the send run, under the exchange lock. A row leaves only after the server replies.
     *
     * @return string error or empty
     */
    public static function send(): string
    {
        for ($i = 0; $i < CmsSync::MAX_PAGES; $i++) {
            $rows = array_slice(self::queue(), 0, CmsSync::BATCH, true);
            if ($rows === []) {
                return '';
            }
            $items = [];
            foreach ($rows as $id => $at) {
                $items[] = ['cms_media_id' => (string) $id, 'deleted_at' => gmdate('c', (int) $at)];
            }

            $response = CmsApiClient::post('/media/deletions', ['items' => $items], CmsSync::WRITE_TIMEOUT);
            if (is_wp_error($response)) {
                return self::send_error($response);
            }
            if (!is_array($response['results'] ?? null)) {
                return CmsSync::invalid_response();
            }
            if (!self::settle($rows, $response['results'])) {
                return CmsSync::invalid_response();
            }
        }
        if (self::queue() !== []) {
            CmsSync::schedule_send();
        }

        return '';
    }

    /** 501 is a backend without this route: not an exchange error. The queue waits for the hourly run. */
    private static function send_error(\WP_Error $error): string
    {
        $data = $error->get_error_data();
        if (is_array($data) && (int) ($data['status'] ?? 0) === 501) {
            return '';
        }

        return CmsSync::send_failure($error);
    }

    /**
     * Every server result finishes a row, including `unknown` and `reason: threshold`: a retry would give the same result.
     * Rows without a result stay in the queue.
     *
     * @param array<int, int> $rows
     * @param array<mixed>    $results
     * @return bool whether anything left the queue
     */
    private static function settle(array $rows, array $results): bool
    {
        $done = [];
        foreach ($results as $result) {
            $id = is_array($result) ? (int) ($result['id'] ?? 0) : 0;
            if (isset($rows[$id]) && is_string($result['status'] ?? null)) {
                $done[$id] = true;
            }
        }
        if ($done === []) {
            return false;
        }
        self::locked(static function () use ($done): void {
            $left = array_diff_key(self::queue(), $done);
            if ($left === []) {
                delete_option(self::OPTION_QUEUE);
            } else {
                update_option(self::OPTION_QUEUE, $left, false);
            }
        });

        return true;
    }

    /** Empty when nothing was lost in the last week. */
    public static function overflow_message(): string
    {
        $lost = (int) get_transient(self::TRANSIENT_OVERFLOW);
        if ($lost < 1) {
            return '';
        }

        /* translators: 1: queue limit, 2: number of files */
        return sprintf(__('The deletion report queue reached its limit of %1$d rows, so Tribune Desk will not learn about newer deletions: %2$d.', 'tribune-desk'), self::MAX_QUEUE, $lost);
    }

    // --- feed -------------------------------------------------------------------------------

    /**
     * `deleted` and `restored` rows. An attachment that is not here has nothing to mark: the row
     * is handled, like a file used in published content.
     *
     * @param array<string, mixed> $change
     */
    public static function apply(array $change): bool
    {
        $raw = $change['cms_media_id'] ?? '';
        if (!is_string($raw) || !ctype_digit($raw) || get_post_type((int) $raw) !== 'attachment') {
            return true;
        }
        $id = (int) $raw;
        if (($change['kind'] ?? '') === 'restored') {
            delete_post_meta($id, self::META_DELETED);
        } elseif (!self::used_in_published($id)) {
            update_post_meta($id, self::META_DELETED, 1);
        }

        return true;
    }

    public static function used_in_published(int $id): bool
    {
        $parent = (int) wp_get_post_parent_id($id);
        if ($parent > 0 && get_post_status($parent) === 'publish') {
            return true;
        }

        global $wpdb;
        if ($wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
             WHERE m.meta_key = '_thumbnail_id' AND m.meta_value = %s AND p.post_status = 'publish' LIMIT 1",
            (string) $id
        ))) {
            return true;
        }

        $base = self::file_base($id);
        if ($base === '') {
            return false;
        }

        // Slash before the name: it is a URL fragment, not an arbitrary word from the content. An excess match
        // leaves a file without a marker, so it errs on the safe side.
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s LIMIT 1",
            '%/' . $wpdb->esc_like($base) . '%'
        ));
    }

    /**
     * Name without extension, so it also catches `-300x200` thumbnails. The attachment of a large image points to
     * the `-scaled` or `-rotated` file, and its thumbnails do not have that suffix.
     */
    private static function file_base(int $id): string
    {
        $file = (string) get_post_meta($id, '_wp_attached_file', true);
        if ($file === '') {
            return '';
        }
        $base = (string) preg_replace('/\.[^.]+$/', '', wp_basename($file));

        return (string) preg_replace('/-(?:scaled|rotated)$/', '', $base);
    }

    // --- library filter ---------------------------------------------------------------------

    public static function has_marked(): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1",
            self::META_DELETED
        ));
    }

    /** List: dropdown in the upload.php form. */
    public static function render_list_filter(string $post_type): void
    {
        $active = self::list_filter_active();
        if ($post_type !== 'attachment' || (!$active && !self::has_marked())) {
            return;
        }
        echo '<select name="' . esc_attr(self::QUERY_VAR) . '" aria-label="' . esc_attr__('Deleted in TD', 'tribune-desk') . '">';
        echo '<option value="">' . esc_html__('All files', 'tribune-desk') . '</option>';
        echo '<option value="1"' . selected($active, true, false) . '>' . esc_html__('Deleted in TD', 'tribune-desk') . '</option>';
        echo '</select>';
    }

    private static function list_filter_active(): bool
    {
        return ($_GET[self::QUERY_VAR] ?? '') === '1';
    }

    public static function filter_list_query(\WP_Query $query): void
    {
        global $pagenow;
        if (!is_admin() || !$query->is_main_query() || $pagenow !== 'upload.php' || !self::list_filter_active()) {
            return;
        }
        $query->set('meta_query', self::with_marker($query->get('meta_query')));
    }

    /**
     * Grid: WordPress rejects unknown `query` keys before this filter, so we read the request.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function filter_grid_query(array $args): array
    {
        $query = $_REQUEST['query'] ?? null;
        if (is_array($query) && ($query[self::QUERY_VAR] ?? '') === '1') {
            $args['meta_query'] = self::with_marker($args['meta_query'] ?? null);
        }

        return $args;
    }

    /**
     * @param mixed $meta_query
     * @return array<int|string, mixed>
     */
    private static function with_marker($meta_query): array
    {
        $meta_query   = is_array($meta_query) ? $meta_query : [];
        $meta_query[] = ['key' => self::META_DELETED, 'compare' => 'EXISTS'];

        return $meta_query;
    }

    /** The grid toolbar is built only by JS, so without a script there is nowhere to put the filter. */
    public static function enqueue_grid_filter(string $hook): void
    {
        if ($hook !== 'upload.php' || !self::has_marked()) {
            return;
        }
        $labels = wp_json_encode([
            'all'     => __('All files', 'tribune-desk'),
            'deleted' => __('Deleted in TD', 'tribune-desk'),
        ]);
        wp_add_inline_script('media-views', '(function(l,v){if(!window.wp||!wp.media||!wp.media.view||!wp.media.view.AttachmentsBrowser){return;}'
            . 'var F=wp.media.view.AttachmentFilters.extend({id:"hf-deleted-in-td-filter",createFilters:function(){this.filters={'
            . 'all:{text:l.all,props:(function(){var p={};p[v]=null;return p;})(),priority:10},'
            . 'deleted:{text:l.deleted,props:(function(){var p={};p[v]="1";return p;})(),priority:20}};}});'
            . 'var B=wp.media.view.AttachmentsBrowser;wp.media.view.AttachmentsBrowser=B.extend({createToolbar:function(){'
            . 'B.prototype.createToolbar.apply(this,arguments);'
            . 'this.toolbar.set("hfDeletedInTd",new F({controller:this.controller,model:this.collection.props,priority:-70}).render());}});'
            . '})(' . $labels . ',' . wp_json_encode(self::QUERY_VAR) . ');');
    }
}
