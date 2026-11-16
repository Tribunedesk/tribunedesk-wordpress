#!/usr/bin/env bash
# The WordPress of the `tdkit` job (.github/workflows/plugin-ci.yml): installed on the runner,
# not in wp-env, because the tdkit mock and WordPress must reach each other on 127.0.0.1 both
# ways. Configures the plugin for the mock with its public test values (tdkit README).
#
#   ci/tdkit/site.sh <site dir> <site url> <plugin dir>
#
# Needs: wp (WP-CLI) on PATH, MariaDB on 127.0.0.1:3306 (root/root), POLYLANG_VERSION,
# TD_WEBHOOK_SECRET, TD_API_HTTPS (the TLS proxy in front of the mock's API).
set -euo pipefail

site="$1"
url="$2"
plugin="$3"

mkdir -p "$site"
cd "$site"
wp core download --quiet
wp config create --dbname=wordpress --dbuser=root --dbpass=root --dbhost=127.0.0.1:3306 --skip-check --quiet
wp db create --quiet
# Every job of the plugin runs from the commands: tdkit reads the result right after each one.
wp config set DISABLE_WP_CRON true --raw --quiet
wp core install --url="$url" --title=tdkit --admin_user=admin --admin_password=admin \
  --admin_email=admin@example.test --skip-email --quiet

ln -s "$plugin" wp-content/plugins/headlinesforge-receiver
mkdir -p wp-content/mu-plugins
cp "$plugin/ci/tdkit/mu-plugin.php" wp-content/mu-plugins/tdkit.php

# The plugin stores a post's language only through Polylang (Language.php).
wp plugin install polylang --version="$POLYLANG_VERSION" --activate --quiet
wp eval-file "$plugin/ci/tdkit/languages.php"

wp plugin activate headlinesforge-receiver --quiet
editor="$(wp user create editor editor@example.test --role=editor --user_pass=editor --porcelain)"

wp option update headlinesforge_signing_secret "$TD_WEBHOOK_SECRET" --quiet
wp option update headlinesforge_cms_token tdcms_mock --quiet
wp option update headlinesforge_api_base "$TD_API_HTTPS" --quiet
wp option update headlinesforge_default_author_id "$editor" --quiet
# l2-social-flag: the post meta key the plugin reads "do not publish on Facebook" from.
wp option update headlinesforge_social_exclude_key td_social_exclude --quiet
echo "WordPress at $url, plugin configured for the mock at $TD_API_HTTPS"
