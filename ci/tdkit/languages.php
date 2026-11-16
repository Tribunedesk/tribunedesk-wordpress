<?php
/**
 * `wp eval-file` of ci/tdkit/site.sh: the Polylang languages the tdkit sample deliveries use
 * (`language` of the contract's sample is `en`). CI only.
 */

declare(strict_types=1);

if (!function_exists('PLL')) {
    WP_CLI::error('Polylang is not active');
}
foreach ([['English', 'en', 'en_US'], ['Polski', 'pl', 'pl_PL']] as $i => [$name, $slug, $locale]) {
    if (PLL()->model->get_language($slug)) {
        continue;
    }
    $result = PLL()->model->add_language(['name' => $name, 'slug' => $slug, 'locale' => $locale, 'rtl' => 0, 'term_group' => $i]);
    if (is_wp_error($result)) {
        WP_CLI::error($result->get_error_message());
    }
}
WP_CLI::success('Polylang languages: en, pl');
