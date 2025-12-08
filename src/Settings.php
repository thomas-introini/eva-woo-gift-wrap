<?php
/**
 * Plugin Settings.
 *
 * Handles the WooCommerce settings integration for the gift wrap plugin.
 *
 * @package EvaGiftWrap
 */

declare(strict_types=1);

namespace EvaGiftWrap;

defined('ABSPATH') || exit;

/**
 * Settings handler for the gift wrap plugin.
 */
final class Settings {

    /**
     * Settings section ID.
     */
    public const SECTION_ID = 'eva_gift_wrap';

    /**
     * Option keys.
     */
    public const OPTION_ENABLED = 'eva_gift_wrap_enabled';
    public const OPTION_SECTION_TITLE = 'eva_gift_wrap_section_title';
    public const OPTION_LABEL = 'eva_gift_wrap_label';
    public const OPTION_FEE = 'eva_gift_wrap_fee';
    public const OPTION_CUSTOM_CSS = 'eva_gift_wrap_custom_css';

    /**
     * Default values.
     */
    public const DEFAULT_SECTION_TITLE = 'Extra';
    public const DEFAULT_LABEL = 'Confezione regalo';
    public const DEFAULT_FEE = 1.50;

    /**
     * Initialize the settings.
     *
     * @return void
     */
    public function init(): void {
        // Add settings section to WooCommerce.
        add_filter('woocommerce_get_sections_products', [$this, 'add_settings_section']);
        add_filter('woocommerce_get_settings_products', [$this, 'get_settings'], 10, 2);

        // Output custom CSS on frontend.
        add_action('wp_head', [$this, 'output_custom_css']);
    }

    /**
     * Add a new settings section to the Products tab.
     *
     * @param array<string, string> $sections Existing sections.
     * @return array<string, string>
     */
    public function add_settings_section(array $sections): array {
        $sections[self::SECTION_ID] = __('Gift Wrap', 'eva-gift-wrap');
        return $sections;
    }

    /**
     * Get the settings for the gift wrap section.
     *
     * @param array<int, array<string, mixed>> $settings        Existing settings.
     * @param string                           $current_section Current section ID.
     * @return array<int, array<string, mixed>>
     */
    public function get_settings(array $settings, string $current_section): array {
        if ($current_section !== self::SECTION_ID) {
            return $settings;
        }

        return [
            [
                'title' => __('Gift Wrap Settings', 'eva-gift-wrap'),
                'type'  => 'title',
                'desc'  => __('Configure the gift wrap option that appears at checkout.', 'eva-gift-wrap'),
                'id'    => 'eva_gift_wrap_settings',
            ],
            [
                'title'   => __('Enable Gift Wrap', 'eva-gift-wrap'),
                'desc'    => __('Show the gift wrap option at checkout', 'eva-gift-wrap'),
                'id'      => self::OPTION_ENABLED,
                'type'    => 'checkbox',
                'default' => 'yes',
            ],
            [
                'title'    => __('Section Title', 'eva-gift-wrap'),
                'desc'     => __('The heading shown above the gift wrap checkbox.', 'eva-gift-wrap'),
                'id'       => self::OPTION_SECTION_TITLE,
                'type'     => 'text',
                'default'  => self::DEFAULT_SECTION_TITLE,
                'desc_tip' => true,
            ],
            [
                'title'    => __('Checkbox Label', 'eva-gift-wrap'),
                'desc'     => __('The label shown for the gift wrap checkbox. The fee will be appended automatically.', 'eva-gift-wrap'),
                'id'       => self::OPTION_LABEL,
                'type'     => 'text',
                'default'  => self::DEFAULT_LABEL,
                'desc_tip' => true,
            ],
            [
                'title'             => __('Fee', 'eva-gift-wrap'),
                'desc'              => __('The fee amount for gift wrapping.', 'eva-gift-wrap'),
                'id'                => self::OPTION_FEE,
                'type'              => 'number',
                'default'           => self::DEFAULT_FEE,
                'custom_attributes' => [
                    'step' => '0.01',
                    'min'  => '0',
                ],
                'desc_tip'          => true,
            ],
            [
                'title'    => __('Custom CSS', 'eva-gift-wrap'),
                'desc'     => __('Add custom CSS to style the gift wrap checkbox. Use .eva-gift-wrap-option as the container class.', 'eva-gift-wrap'),
                'id'       => self::OPTION_CUSTOM_CSS,
                'type'     => 'textarea',
                'default'  => '',
                'css'      => 'width: 100%; height: 150px; font-family: monospace;',
                'desc_tip' => true,
            ],
            [
                'type' => 'sectionend',
                'id'   => 'eva_gift_wrap_settings',
            ],
        ];
    }

    /**
     * Output CSS in the frontend head.
     *
     * @return void
     */
    public function output_custom_css(): void {
        if (! is_checkout()) {
            return;
        }

        // Base styles for the gift wrap accordion section.
        $base_css = '
            .eva-gift-wrap-section {
                margin-bottom: 12px;
            }
            .eva-gift-wrap-accordion-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                width: 100%;
                padding: 0;
                margin-bottom: 12px;
                background: none;
                border: none;
                cursor: pointer;
                font-size: inherit;
                font-family: inherit;
                text-align: left;
                color: inherit;
            }
            .eva-gift-wrap-accordion-header:hover {
                opacity: 0.8;
            }
            .eva-gift-wrap-accordion-title {
                font-weight: 600;
                font-size: 0.875em;
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }
            .eva-gift-wrap-chevron {
                flex-shrink: 0;
                color: currentColor;
            }
            .eva-gift-wrap-option .components-checkbox-control__input-container svg {
                display: none !important;
            }
        ';

        $custom_css = self::get_custom_css();

        printf(
            '<style id="eva-gift-wrap-css">%s%s</style>',
            wp_strip_all_tags($base_css),
            wp_strip_all_tags($custom_css)
        );
    }

    /**
     * Check if the gift wrap feature is enabled.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return get_option(self::OPTION_ENABLED, 'yes') === 'yes';
    }

    /**
     * Get the section title.
     *
     * @return string
     */
    public static function get_section_title(): string {
        $title = get_option(self::OPTION_SECTION_TITLE, self::DEFAULT_SECTION_TITLE);
        return is_string($title) && ! empty($title) ? $title : self::DEFAULT_SECTION_TITLE;
    }

    /**
     * Get the gift wrap label.
     *
     * @return string
     */
    public static function get_label(): string {
        $label = get_option(self::OPTION_LABEL, self::DEFAULT_LABEL);
        return is_string($label) && ! empty($label) ? $label : self::DEFAULT_LABEL;
    }

    /**
     * Get the gift wrap fee amount.
     *
     * @return float
     */
    public static function get_fee(): float {
        $fee = get_option(self::OPTION_FEE, self::DEFAULT_FEE);
        return is_numeric($fee) ? (float) $fee : self::DEFAULT_FEE;
    }

    /**
     * Get the custom CSS.
     *
     * @return string
     */
    public static function get_custom_css(): string {
        $css = get_option(self::OPTION_CUSTOM_CSS, '');
        return is_string($css) ? trim($css) : '';
    }
}

