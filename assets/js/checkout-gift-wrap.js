/**
 * EVA Gift Wrap - Checkout Block Extension
 *
 * Adds a gift wrap checkbox to the WooCommerce Checkout Block.
 * When enabled, a configurable fee is added to the cart totals.
 *
 * @package EvaGiftWrap
 */

(function () {
    'use strict';

    // Get settings passed from PHP.
    var settings = window.evaGiftWrapSettings || {};
    var sectionTitle = settings.sectionTitle || 'Extra';
    var label = settings.label || 'Confezione regalo';
    var feeFormatted = settings.feeFormatted || '€1,50';

    // Ensure WooCommerce Blocks checkout API is available.
    if (
        typeof window.wc === 'undefined' ||
        typeof window.wc.blocksCheckout === 'undefined'
    ) {
        console.warn('EVA Gift Wrap: WooCommerce Blocks checkout API not available.');
        return;
    }

    // Ensure WordPress plugins API is available.
    if (
        typeof window.wp === 'undefined' ||
        typeof window.wp.plugins === 'undefined' ||
        typeof window.wp.element === 'undefined' ||
        typeof window.wp.components === 'undefined'
    ) {
        console.warn('EVA Gift Wrap: WordPress APIs not available.');
        return;
    }

    var createElement = window.wp.element.createElement;
    var useState = window.wp.element.useState;
    var useEffect = window.wp.element.useEffect;
    var useCallback = window.wp.element.useCallback;
    var CheckboxControl = window.wp.components.CheckboxControl;
    // We will mount our own React tree instead of using registerPlugin.
    var dispatch = window.wp.data.dispatch;

    /**
     * Chevron Icon Component
     */
    function ChevronIcon(props) {
        var isOpen = props.isOpen;
        return createElement(
            'svg',
            {
                xmlns: 'http://www.w3.org/2000/svg',
                viewBox: '0 0 24 24',
                width: '24',
                height: '24',
                className: 'eva-gift-wrap-chevron',
                style: {
                    transform: isOpen ? 'rotate(180deg)' : 'rotate(0deg)',
                    transition: 'transform 0.3s ease',
                },
            },
            createElement('path', {
                d: 'M17.5 11.6L12 16l-5.5-4.4.9-1.2L12 14l4.5-3.6 1 1.2z',
                fill: 'currentColor',
            })
        );
    }

    /**
     * Gift Wrap Accordion Section Component
     *
     * Renders a collapsible accordion section with gift wrap checkbox.
     *
     * @return {Element} The accordion section component.
     */
    function GiftWrapAccordion() {
        // Accordion open/closed state.
        var _useStateOpen = useState(false);
        var isOpen = _useStateOpen[0];
        var setIsOpen = _useStateOpen[1];

        // Local state for the checkbox.
        var _useState = useState(false);
        var isChecked = _useState[0];
        var setIsChecked = _useState[1];

        // Loading state to prevent double-clicks.
        var _useStateLoading = useState(false);
        var isLoading = _useStateLoading[0];
        var setIsLoading = _useStateLoading[1];

        // Build the checkbox label with fee.
        var checkboxLabel = label + ' (+' + feeFormatted + ')';

        // Toggle accordion.
        var toggleAccordion = useCallback(function () {
            setIsOpen(function (prev) {
                return !prev;
            });
        }, []);

        // Handle checkbox change.
        var handleChange = useCallback(
            function (checked) {
                if (isLoading) return;

                setIsChecked(checked);
                setIsLoading(true);

                // Send update to our custom endpoint.
                wp.apiFetch({
                    path: '/eva-gift-wrap/v1/toggle',
                    method: 'POST',
                    data: {
                        enabled: checked,
                    },
                })
                    .then(function () {
                        // Fetch updated cart to refresh totals.
                        return wp.apiFetch({
                            path: '/wc/store/v1/cart',
                            method: 'GET',
                        });
                    })
                    .then(function (cart) {
                        // Update the cart store with fresh data.
                        var cartStore = dispatch('wc/store/cart');
                        if (cartStore && cartStore.receiveCart) {
                            cartStore.receiveCart(cart);
                        }
                    })
                    .catch(function (error) {
                        console.error('EVA Gift Wrap: Failed to update cart.', error);
                        // Revert checkbox on error.
                        setIsChecked(!checked);
                    })
                    .finally(function () {
                        setIsLoading(false);
                    });
            },
            [isLoading]
        );

        // Render the accordion section.
        return createElement(
            'div',
            {
                className: 'wc-block-components-totals-wrapper eva-gift-wrap-section',
            },
            // Accordion header (clickable)
            createElement(
                'button',
                {
                    type: 'button',
                    className: 'eva-gift-wrap-accordion-header',
                    onClick: toggleAccordion,
                    'aria-expanded': isOpen,
                },
                createElement(
                    'span',
                    { className: 'eva-gift-wrap-accordion-title' },
                    sectionTitle
                ),
                createElement(ChevronIcon, { isOpen: isOpen })
            ),
            // Accordion content (show/hide)
            createElement(
                'div',
                {
                    className: 'eva-gift-wrap-accordion-content',
                    style: {
                        display: isOpen ? 'block' : 'none',
                    },
                },
                createElement(
                    'div',
                    {
                        className: 'eva-gift-wrap-option',
                        style: {
                            opacity: isLoading ? 0.7 : 1,
                        },
                    },
                    createElement(CheckboxControl, {
                        label: checkboxLabel,
                        checked: isChecked,
                        onChange: handleChange,
                        disabled: isLoading,
                    })
                )
            )
        );
    }

    /**
     * Mount the gift wrap accordion just above the \"Add coupon\" section.
     */
    function mountGiftWrap() {
        // Try to locate the coupon section row.
        var couponRow =
            document.querySelector('.wc-block-components-totals-coupon') ||
            document.querySelector('.wc-block-components-totals-coupon-link') ||
            document.querySelector('.wc-block-components-totals-row--coupon');

        if (!couponRow || !couponRow.parentNode) {
            return;
        }

        // Create a placeholder container for our React tree.
        var container = document.createElement('div');
        container.className = 'wc-block-components-panel';
        couponRow.parentNode.insertBefore(container, couponRow);

        // Render the accordion into the placeholder.
        if (window.wp && window.wp.element && typeof window.wp.element.render === 'function') {
            window.wp.element.render(
                createElement(GiftWrapAccordion, null),
                container
            );
        }
    }

    // Wait for DOM to be ready, then try to mount.
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        mountGiftWrap();
    } else {
        document.addEventListener('DOMContentLoaded', mountGiftWrap);
    }
})();
