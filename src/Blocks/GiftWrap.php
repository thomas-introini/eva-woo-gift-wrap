<?php

/**
 * Gift Wrap Block Extension.
 *
 * Handles the WooCommerce Blocks Store API integration for the gift wrap option,
 * including schema extension, data registration, and fee calculation.
 *
 * @package EvaGiftWrap\Blocks
 */

declare(strict_types=1);

namespace EvaGiftWrap\Blocks;

use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;
use EvaGiftWrap\Settings;
use WC_Cart;
use WC_Customer;
use WC_Order;
use WP_REST_Request;
use WP_REST_Response;
use function __;
use function add_action;
use function esc_html;
use function is_admin;
use function html_entity_decode;
use function register_rest_route;
use function wc_get_logger;
use function wc_load_cart;
use function woocommerce_register_additional_checkout_field;
use function wp_strip_all_tags;
use function wc_price;
use function WC;

defined('ABSPATH') || exit;

/**
 * Gift Wrap block extension handler.
 */
final class GiftWrap
{

    /**
     * Extension namespace.
     */
    public const NAMESPACE = 'eva';

    /**
     * Extension field name.
     */
    public const FIELD_NAME = 'gift_wrap';

    /**
     * Initialize the gift wrap extension.
     *
     * @return void
     */
    public function init(): void
    {
        // Register the Store API extension.
        add_action('woocommerce_blocks_loaded', [$this, 'register_store_api_extension']);

        // Add the fee to cart totals.
        add_action('woocommerce_cart_calculate_fees', [$this, 'maybe_add_gift_wrap_fee'], 20);

        // Register custom REST endpoint for gift wrap toggle.
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Register Additional Checkout Field (Blocks-supported UI).
        add_action('woocommerce_init', [$this, 'register_additional_checkout_field']);
    }

    /**
     * Register the additional checkout field via WooCommerce Blocks API.
     *
     * @return void
     */
    public function register_additional_checkout_field(): void
    {
        if (! function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }

        $fee         = Settings::get_fee();
        $formatted   = function_exists('wc_price')
            ? html_entity_decode(wp_strip_all_tags(wc_price($fee)), ENT_QUOTES, 'UTF-8')
            : '€' . number_format($fee, 2, ',', '.');
        $label = Settings::get_label() . ' (+' . $formatted . ')';

        woocommerce_register_additional_checkout_field([
            'id'       => 'eva-gift-wrap/gift_wrap',
            'label'    => $label,
            'location' => 'order',
            'type'     => 'checkbox',
            'required' => false,
            'default'  => $this->get_gift_wrap_value(),
            // Store value in order meta automatically.
            'order_meta' => [
                'key' => '_eva_gift_wrap',
            ],
        ]);
    }

    /**
     * Register custom REST routes.
     *
     * @return void
     */
    public function register_rest_routes(): void
    {
        register_rest_route('eva-gift-wrap/v1', '/toggle', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_toggle_request'],
            'permission_callback' => '__return_true',
            'args'                => [
                'enabled' => [
                    'type'     => 'boolean',
                    'required' => true,
                ],
            ],
        ]);

        // Simple status endpoint used by the frontend to restore checked state on page load.
        register_rest_route('eva-gift-wrap/v1', '/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_status'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Handle the gift wrap toggle REST request.
     *
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response
     */
    public function handle_toggle_request(\WP_REST_Request $request): \WP_REST_Response
    {
        $enabled = (bool) $request->get_param('enabled');

        // WooCommerce log: toggle received.
        $logger   = function_exists('wc_get_logger') ? wc_get_logger() : null;
        $context  = ['source' => 'eva-gift-wrap'];
        $sessionId = (function_exists('WC') && WC()->session) ? WC()->session->get_customer_id() : 'no-session';
        if ($logger) {
            $logger->info(
                sprintf('toggle request: enabled=%s session_id=%s', $enabled ? '1' : '0', (string) $sessionId),
                $context
            );
        }

        // Ensure cart/session are initialized for REST context.
        if (function_exists('wc_load_cart')) {
            wc_load_cart();
        }

        $this->set_gift_wrap_value($enabled);

        // Force cart recalculation.
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->calculate_totals();
            if ($logger) {
                $logger->debug('toggle request: cart totals recalculated', $context);
            }
        }

        return new \WP_REST_Response([
            'success'   => true,
            'gift_wrap' => $enabled,
        ], 200);
    }

    /**
     * Return current gift wrap status (from session) for frontend initialization.
     *
     * @param \WP_REST_Request $request The request object.
     * @return \WP_REST_Response
     */
    public function get_status(\WP_REST_Request $request): \WP_REST_Response
    {
        $enabled = $this->get_gift_wrap_value();

        return new \WP_REST_Response([
            'enabled' => $enabled,
        ], 200);
    }

    /**
     * Register the Store API extension for gift wrap data.
     *
     * @return void
     */
    public function register_store_api_extension(): void
    {
        // Ensure the ExtendSchema class exists.
        if (! class_exists('Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema')) {
            return;
        }

        try {
            $extend = StoreApi::container()->get(\Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema::class);
        } catch (\Throwable $e) {
            return;
        }

        // Register the checkout data callback to expose gift_wrap in the response.
        $extend->register_endpoint_data([
            'endpoint'        => CheckoutSchema::IDENTIFIER,
            'namespace'       => self::NAMESPACE,
            'data_callback'   => [$this, 'get_extension_data'],
            'schema_callback' => [$this, 'get_extension_schema'],
            'schema_type'     => ARRAY_A,
        ]);

        // Also register for the cart schema so we can receive updates.
        $extend->register_endpoint_data([
            'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
            'namespace'       => self::NAMESPACE,
            'data_callback'   => [$this, 'get_extension_data'],
            'schema_callback' => [$this, 'get_extension_schema'],
            'schema_type'     => ARRAY_A,
        ]);

        // Register update callback for cart/update-customer endpoint.
        add_action(
            'woocommerce_store_api_cart_update_customer_from_request',
            [$this, 'handle_cart_update_request'],
            10,
            2
        );

        // Register the update callback for handling incoming gift_wrap data.
        add_action(
            'woocommerce_store_api_checkout_update_order_from_request',
            [$this, 'handle_checkout_order_update'],
            10,
            2
        );
    }

    /**
     * Get the extension data for the checkout response.
     *
     * @return array<string, bool>
     */
    public function get_extension_data(): array
    {
        return [
            self::FIELD_NAME => $this->get_gift_wrap_value(),
        ];
    }

    /**
     * Get the extension schema for the Store API.
     *
     * @return array<string, array<string, mixed>>
     */
    public function get_extension_schema(): array
    {
        return [
            self::FIELD_NAME => [
                'description' => __('Whether gift wrap is requested.', 'eva-gift-wrap'),
                'type'        => 'boolean',
                'context'     => ['view', 'edit'],
                'readonly'    => false,
                'default'     => false,
            ],
        ];
    }

    /**
     * Handle cart update request from the frontend.
     *
     * @param \WC_Customer     $customer The customer object.
     * @param \WP_REST_Request $request  The request object.
     * @return void
     */
    public function handle_cart_update_request($customer, \WP_REST_Request $request): void
    {
        // Additional Checkout Field (Blocks) support.
        $fieldValue = $this->get_gift_wrap_from_request($request);
        if (null !== $fieldValue) {
            $this->set_gift_wrap_value($fieldValue);

            if (function_exists('WC') && WC()->cart) {
                WC()->cart->calculate_totals();
            }
        }

        $extensions = $request->get_param('extensions');

        $logger  = function_exists('wc_get_logger') ? wc_get_logger() : null;
        $context = ['source' => 'eva-gift-wrap'];

        if (
            is_array($extensions) &&
            isset($extensions[self::NAMESPACE][self::FIELD_NAME])
        ) {
            $gift_wrap = (bool) $extensions[self::NAMESPACE][self::FIELD_NAME];
            $this->set_gift_wrap_value($gift_wrap);

            if ($logger) {
                $logger->info(
                    'cart update (update-customer): gift_wrap=' . ($gift_wrap ? '1' : '0'),
                    $context
                );
            }

            // Force cart recalculation.
            if (function_exists('WC') && WC()->cart) {
                WC()->cart->calculate_totals();
                if ($logger) {
                    $logger->debug('cart update (update-customer): cart totals recalculated', $context);
                }
            }
        }
    }

    /**
     * Handle order update from checkout request.
     *
     * Stores the gift wrap preference in the session and order meta.
     *
     * @param \WC_Order     $order   The order being placed.
     * @param \WP_REST_Request $request The checkout request.
     * @return void
     */
    public function handle_checkout_order_update(\WC_Order $order, \WP_REST_Request $request): void
    {
        $extensions = $request->get_param('extensions');

        // Prefer Additional Checkout Field value when present (respect explicit false).
        $acfValue = $this->get_gift_wrap_from_request($request);
        if ($acfValue !== null) {
            $gift_wrap = $acfValue;
        } elseif (
            is_array($extensions) &&
            isset($extensions[self::NAMESPACE][self::FIELD_NAME])
        ) {
            // Fallback to extension data.
            $gift_wrap = (bool) $extensions[self::NAMESPACE][self::FIELD_NAME];
        } else {
            // Finally, fall back to session value.
            $gift_wrap = $this->get_gift_wrap_value();
        }

        $logger  = function_exists('wc_get_logger') ? wc_get_logger() : null;
        $context = ['source' => 'eva-gift-wrap'];
        if ($logger) {
            $logger->info(
                sprintf(
                    'checkout update: order_id=%s gift_wrap=%s',
                    method_exists($order, 'get_id') ? (string) $order->get_id() : 'unknown',
                    $gift_wrap ? '1' : '0'
                ),
                $context
            );
        }

        // Save to order meta.
        $order->update_meta_data('_eva_gift_wrap', $gift_wrap ? 'yes' : 'no');

        // Also update session for cart calculations.
        $this->set_gift_wrap_value($gift_wrap);
    }

    /**
     * Register checkout block integration for the gift wrap extension.
     *
     * @param \Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry $integration_registry Integration registry.
     * @return void
     */
    public function register_checkout_block_integration($integration_registry): void
    {
        // We handle script enqueueing in Plugin::enqueue_scripts().
        // This hook is available for more complex integrations if needed.
    }

    /**
     * Add the gift wrap fee to the cart if enabled.
     *
     * @param \WC_Cart $cart The cart object.
     * @return void
     */
    public function maybe_add_gift_wrap_fee(\WC_Cart $cart): void
    {
        if (is_admin() && ! defined('DOING_AJAX')) {
            return;
        }

        // Check if gift wrap feature is enabled in settings.
        if (! Settings::is_enabled()) {
            return;
        }

        $logger  = function_exists('wc_get_logger') ? wc_get_logger() : null;
        $context = ['source' => 'eva-gift-wrap'];

        $gift_wrap_enabled = $this->get_gift_wrap_value();
        if ($logger) {
            $logger->debug(
                'maybe_add_gift_wrap_fee: gift_wrap=' . ($gift_wrap_enabled ? '1' : '0'),
                $context
            );
        }

        if ($gift_wrap_enabled) {
            if ($logger) {
                $logger->info(
                    sprintf(
                        'adding fee: label="%s" amount=%s',
                        (string) Settings::get_label(),
                        (string) Settings::get_fee()
                    ),
                    $context
                );
            }
            $cart->add_fee(
                esc_html(Settings::get_label()),
                Settings::get_fee(),
                false // Not taxable.
            );
        }
    }

    /**
     * Get the stored gift wrap value from session.
     *
     * @return bool
     */
    private function get_gift_wrap_value(): bool
    {
        if (! function_exists('WC')) {
            return false;
        }

        // Ensure the WooCommerce session/cart are initialized (especially in REST context).
        if (! WC()->session && function_exists('wc_load_cart')) {
            wc_load_cart();
        }

        if (! WC()->session) {
            return false;
        }

        return (bool) WC()->session->get('eva_gift_wrap', false);
    }

    /**
     * Set the gift wrap value in the session.
     *
     * @param bool $value The gift wrap value.
     * @return void
     */
    private function set_gift_wrap_value(bool $value): void
    {
        if (! function_exists('WC') || ! WC()->session) {
            return;
        }

        WC()->session->set('eva_gift_wrap', $value);
    }

    /**
     * Extract gift wrap value from Store API request (Additional Checkout Field).
     *
     * @param \WP_REST_Request $request Request.
     * @return bool|null
     */
    private function get_gift_wrap_from_request(\WP_REST_Request $request): ?bool
    {
        $additional = $request->get_param('additional_fields');
        if (! is_array($additional)) {
            return null;
        }

        // Accept both slash and underscore separators just in case.
        $keys = [
            'eva-gift-wrap/gift_wrap',
            'eva_gift_wrap/gift_wrap',
            'eva_gift_wrap',
        ];

        foreach ($keys as $key) {
            if (array_key_exists($key, $additional)) {
                return (bool) $additional[$key];
            }
        }

        return null;
    }
}
