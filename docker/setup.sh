#!/usr/bin/env bash
# Provision a local WordPress + WooCommerce site for testing the plugin.
set -euo pipefail
cd "$(dirname "$0")"

if [ -f .env ]; then set -a; . ./.env; set +a; fi
WP_URL="${WP_URL:-http://localhost:8080}"
WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
WP_ADMIN_PASSWORD="${WP_ADMIN_PASSWORD:-admin}"
WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@example.test}"

DC="docker compose -f docker-compose.yml"
WP="$DC run --rm wpcli wp"

$DC up -d db wordpress

echo "Waiting for WordPress files and database..."
until $WP db check >/dev/null 2>&1; do sleep 3; done

if ! $WP core is-installed >/dev/null 2>&1; then
  $WP core install --url="$WP_URL" --title="DN BFS Dev" \
    --admin_user="$WP_ADMIN_USER" --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="$WP_ADMIN_EMAIL" --skip-email
fi

$WP rewrite structure '/%postname%/' --hard
$WP plugin is-installed woocommerce || $WP plugin install woocommerce
$WP plugin activate woocommerce
$WP option update woocommerce_currency USD
$WP wc tool run install_pages --user="$WP_ADMIN_USER" >/dev/null
$WP option update woocommerce_cod_settings '{"enabled":"yes","title":"Cash on delivery"}' --format=json

COUNT=$($WP post list --post_type=product --post_status=publish --format=count)
i=$((COUNT + 1))
while [ "$i" -le 10 ]; do
  $WP wc product create --name="Test product $i" --regular_price="$((i * 10))" --status=publish --user="$WP_ADMIN_USER" --porcelain >/dev/null
  i=$((i + 1))
done

$WP plugin activate dn-burst-funnel-stats
echo "Ready: $WP_URL (wp-admin user: $WP_ADMIN_USER)"
