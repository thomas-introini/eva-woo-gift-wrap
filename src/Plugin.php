<?php
/**
 * Main plugin class.
 *
 * Initializes the plugin components and coordinates between the blocks
 * extension and the fee calculation logic.
 *
 * @package EvaGiftWrap
 */

declare(strict_types=1);

namespace EvaGiftWrap;

use EvaGiftWrap\Blocks\GiftWrap;

defined('ABSPATH') || exit;

/**
 * Plugin main class (singleton).
 */
final class Plugin {

    /**
     * Plugin instance.
     *
     * @var Plugin|null
     */
    private static ?Plugin $instance = null;

    /**
     * Gift wrap block extension handler.
     *
     * @var GiftWrap|null
     */
    private ?GiftWrap $gift_wrap = null;

    /**
     * Get the singleton instance.
     *
     * @return Plugin
     */
    public static function instance(): Plugin {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function __construct() {
        $this->init();
    }

    /**
     * Prevent cloning.
     *
     * @return void
     */
    private function __clone() {}

    /**
     * Prevent unserialization.
     *
     * @throws \Exception Always throws to prevent unserialization.
     * @return void
     */
    public function __wakeup(): void {
        throw new \Exception('Cannot unserialize singleton');
    }

    /**
     * Initialize plugin components.
     *
     * @return void
     */
    private function init(): void {
        // Initialize the gift wrap block extension.
        $this->gift_wrap = new GiftWrap();
        $this->gift_wrap->init();

        // Enqueue frontend assets.
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Enqueue frontend scripts for the checkout block.
     *
     * @return void
     */
    public function enqueue_scripts(): void {
        // Only enqueue on the checkout page.
        if (! function_exists('is_checkout') || ! is_checkout()) {
            return;
        }

        // Check if the page has the checkout block.
        if (! $this->has_checkout_block()) {
            return;
        }

        $script_path = EVA_GIFT_WRAP_PLUGIN_DIR . 'assets/js/checkout-gift-wrap.js';
        $script_url  = EVA_GIFT_WRAP_PLUGIN_URL . 'assets/js/checkout-gift-wrap.js';

        // Get file modification time for cache busting.
        $version = file_exists($script_path)
            ? (string) filemtime($script_path)
            : EVA_GIFT_WRAP_VERSION;

        wp_enqueue_script(
            'eva-gift-wrap-checkout',
            $script_url,
            [
                'wp-element',
                'wp-components',
                'wp-i18n',
                'wp-plugins',
                'wp-api-fetch',
                'wp-data',
                'wc-blocks-checkout',
            ],
            $version,
            true
        );

        // Set script translations.
        wp_set_script_translations(
            'eva-gift-wrap-checkout',
            'eva-gift-wrap',
            EVA_GIFT_WRAP_PLUGIN_DIR . 'languages'
        );
    }

    /**
     * Check if the current page contains the checkout block.
     *
     * @return bool
     */
    private function has_checkout_block(): bool {
        global $post;

        if (! $post instanceof \WP_Post) {
            return false;
        }

        return has_block('woocommerce/checkout', $post);
    }
}

