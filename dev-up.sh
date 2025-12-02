#!/bin/bash
#
# Start the development environment for EVA Gift Wrap.
# Creates a local WordPress + WooCommerce instance with the plugin installed.
#
# Usage: ./dev-up.sh
#

set -e

SITE_URL="http://localhost:8080"
ADMIN_USER="admin"
ADMIN_PASSWORD="admin"
ADMIN_EMAIL="admin@example.com"

echo "🚀 Starting EVA Gift Wrap development environment..."
echo ""

# Check if Docker is running.
if ! docker info > /dev/null 2>&1; then
    echo "❌ Docker is not running. Please start Docker and try again."
    exit 1
fi

# Start the containers.
echo "📦 Starting Docker containers..."
docker compose up -d

# Wait for WordPress to be ready.
echo "⏳ Waiting for WordPress to be ready..."
sleep 5

# Check if WordPress is already installed.
WP_INSTALLED=$(docker compose run --rm wpcli core is-installed 2>/dev/null && echo "yes" || echo "no")

if [ "$WP_INSTALLED" = "no" ]; then
    echo "🔧 Installing WordPress..."

    # Install WordPress.
    docker compose run --rm wpcli core install \
        --url="$SITE_URL" \
        --title="EVA Gift Wrap Dev" \
        --admin_user="$ADMIN_USER" \
        --admin_password="$ADMIN_PASSWORD" \
        --admin_email="$ADMIN_EMAIL" \
        --skip-email

    # Install and activate WooCommerce.
    echo "🛒 Installing WooCommerce..."
    docker compose run --rm wpcli plugin install woocommerce --activate

    # Run WooCommerce setup (create pages, etc.).
    echo "⚙️  Configuring WooCommerce..."
    docker compose run --rm wpcli wc --user=admin tool run install_pages 2>/dev/null || true

    # Set WooCommerce to use blocks checkout.
    docker compose run --rm wpcli option update woocommerce_checkout_page_id 0 2>/dev/null || true

    # Create a proper checkout page with the checkout block.
    docker compose run --rm wpcli post create \
        --post_type=page \
        --post_title="Checkout" \
        --post_name="checkout" \
        --post_status=publish \
        --post_content='<!-- wp:woocommerce/checkout /-->'

    # Get the new checkout page ID and set it.
    CHECKOUT_ID=$(docker compose run --rm wpcli post list --post_type=page --name=checkout --field=ID 2>/dev/null | tr -d '\r')
    if [ -n "$CHECKOUT_ID" ]; then
        docker compose run --rm wpcli option update woocommerce_checkout_page_id "$CHECKOUT_ID"
    fi

    # Create a cart page with the cart block.
    docker compose run --rm wpcli post create \
        --post_type=page \
        --post_title="Cart" \
        --post_name="cart" \
        --post_status=publish \
        --post_content='<!-- wp:woocommerce/cart /-->'

    CART_ID=$(docker compose run --rm wpcli post list --post_type=page --name=cart --field=ID 2>/dev/null | tr -d '\r')
    if [ -n "$CART_ID" ]; then
        docker compose run --rm wpcli option update woocommerce_cart_page_id "$CART_ID"
    fi

    # Set currency to EUR.
    docker compose run --rm wpcli option update woocommerce_currency EUR

    # Create a sample product for testing.
    echo "📦 Creating sample product..."
    docker compose run --rm wpcli wc product create \
        --user=admin \
        --name="Prodotto di Test" \
        --type=simple \
        --regular_price=10.00 \
        --status=publish \
        2>/dev/null || echo "  (product creation via API skipped)"

    # Activate our plugin.
    echo "🎁 Activating EVA Gift Wrap plugin..."
    docker compose run --rm wpcli plugin activate eva-gift-wrap

    # Flush rewrite rules.
    docker compose run --rm wpcli rewrite flush

    echo ""
    echo "✅ Fresh installation complete!"
else
    echo "✅ WordPress already installed, containers started."

    # Make sure our plugin is activated.
    docker compose run --rm wpcli plugin activate eva-gift-wrap 2>/dev/null || true
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "🌐 Site:      $SITE_URL"
echo "🔐 Admin:     $SITE_URL/wp-admin"
echo "👤 Username:  $ADMIN_USER"
echo "🔑 Password:  $ADMIN_PASSWORD"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "📝 Debug log: docker compose logs -f wordpress"
echo "🛑 Stop:      ./dev-down.sh"
echo ""

