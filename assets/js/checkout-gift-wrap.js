/**
 * EVA Gift Wrap - Checkout Block Extension
 *
 * Adds a gift wrap checkbox to the WooCommerce Checkout Block.
 * When enabled, a €1.50 fee is added to the cart totals.
 *
 * @package EvaGiftWrap
 */

(function () {
    'use strict';

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
    var useCallback = window.wp.element.useCallback;
    var CheckboxControl = window.wp.components.CheckboxControl;
    var registerPlugin = window.wp.plugins.registerPlugin;
    var dispatch = window.wp.data.dispatch;

    // Get the experimental slot fill for order meta.
    var ExperimentalOrderMeta = window.wc.blocksCheckout.ExperimentalOrderMeta;

    // Extension namespace and field name.
    var NAMESPACE = 'eva';
    var FIELD_NAME = 'gift_wrap';

    /**
     * Gift Wrap Checkbox Component
     *
     * Renders a checkbox that allows customers to add gift wrapping to their order.
     *
     * @return {Element} The checkbox component.
     */
    function GiftWrapCheckbox() {
        // Local state for the checkbox.
        var _useState = useState(false);
        var isChecked = _useState[0];
        var setIsChecked = _useState[1];

        // Loading state to prevent double-clicks.
        var _useStateLoading = useState(false);
        var isLoading = _useStateLoading[0];
        var setIsLoading = _useStateLoading[1];

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

        // Render the checkbox control.
        return createElement(
            'div',
            {
                className: 'eva-gift-wrap-option',
                style: {
                    padding: '16px 0',
                    borderTop: '1px solid #e0e0e0',
                    opacity: isLoading ? 0.7 : 1,
                },
            },
            createElement(CheckboxControl, {
                label: 'Confezione regalo (+1,50€)',
                checked: isChecked,
                onChange: handleChange,
                disabled: isLoading,
            })
        );
    }

    /**
     * Render function for the plugin slot fill.
     */
    function GiftWrapSlotFill() {
        return createElement(
            ExperimentalOrderMeta,
            null,
            createElement(GiftWrapCheckbox, null)
        );
    }

    // Register the plugin with WooCommerce checkout scope.
    try {
        registerPlugin('eva-gift-wrap', {
            render: GiftWrapSlotFill,
            scope: 'woocommerce-checkout',
        });
    } catch (error) {
        console.error('EVA Gift Wrap: Failed to register plugin.', error);
    }
})();
