<?php

/**
 * Plugin Name: EVA Gift Wrap
 * Plugin URI: https://example.com/eva-gift-wrap
 * Description: Adds a "Confezione regalo" (gift wrap) option to the WooCommerce Checkout Block with a fixed €1.50 fee.
 * Version: 1.1.1
 * Author: Thomas Introini
 * Author URI: https://example.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: eva-gift-wrap
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 *
 * @package EvaGiftWrap
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Plugin constants.
define('EVA_GIFT_WRAP_VERSION', '1.0.0');
define('EVA_GIFT_WRAP_PLUGIN_FILE', __FILE__);
define('EVA_GIFT_WRAP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EVA_GIFT_WRAP_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Autoloader for plugin classes.
 *
 * @param string $class The fully-qualified class name.
 * @return void
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'EvaGiftWrap\\';
    $base_dir = EVA_GIFT_WRAP_PLUGIN_DIR . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Initialize the plugin after plugins are loaded.
 *
 * @return void
 */
function eva_gift_wrap_init(): void
{
    // Load plugin textdomain for translations.
    load_plugin_textdomain(
        'eva-gift-wrap',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );

    // Check if WooCommerce is active.
    if (! class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
?>
            <div class="notice notice-error">
                <p>
                    <?php
                    echo esc_html__(
                        'EVA Gift Wrap richiede WooCommerce per funzionare. Per favore, attiva WooCommerce.',
                        'eva-gift-wrap'
                    );
                    ?>
                </p>
            </div>
        <?php
        });
        return;
    }

    // Check if WooCommerce Blocks is available.
    if (! class_exists('Automattic\WooCommerce\Blocks\Package')) {
        add_action('admin_notices', static function (): void {
        ?>
            <div class="notice notice-error">
                <p>
                    <?php
                    echo esc_html__(
                        'EVA Gift Wrap richiede WooCommerce Blocks per funzionare. Per favore, aggiorna WooCommerce.',
                        'eva-gift-wrap'
                    );
                    ?>
                </p>
            </div>
<?php
        });
        return;
    }

    // Initialize the plugin.
    EvaGiftWrap\Plugin::instance();
}
add_action('plugins_loaded', 'eva_gift_wrap_init');

/**
 * Declare HPOS compatibility.
 *
 * @return void
 */
function eva_gift_wrap_declare_hpos_compatibility(): void
{
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
}
add_action('before_woocommerce_init', 'eva_gift_wrap_declare_hpos_compatibility');
