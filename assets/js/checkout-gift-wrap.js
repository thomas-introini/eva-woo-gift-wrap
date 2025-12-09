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
    console.warn(
      'EVA Gift Wrap: WooCommerce Blocks checkout API not available.'
    );
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

    // On mount, sync initial checked state from the backend session (via REST).
    useEffect(function () {
      if (!wp || !wp.apiFetch) {
        return;
      }

      wp.apiFetch({
        path: '/eva-gift-wrap/v1/status',
        method: 'GET',
      })
        .then(function (response) {
          if (response && typeof response.enabled === 'boolean') {
            setIsChecked(response.enabled);
          }
        })
        .catch(function (error) {
          // Non-fatal: just log, the checkbox will start unchecked.
          console.warn(
            'EVA Gift Wrap: Failed to read initial state from backend.',
            error
          );
        });
    }, []);

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

        // Use the WooCommerce Store API to persist extension data in the cart session.
        wp.apiFetch({
          path: '/wc/store/v1/cart/update-customer',
          method: 'POST',
          data: {
            extensions: {
              eva: {
                gift_wrap: checked,
              },
            },
          },
        })
          .then(function (cart) {
            // Update the cart store with the response from update-customer
            // (which already contains updated totals and fees).
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
   * Mount using Woo Blocks Slot/Fill when available; fallback to DOM append.
   */
  function mountGiftWrap() {
    // Keep the section before totals when using fallback or when the slot renders at the end.
    function relocateBeforeTotals() {
      var section = document.querySelector(
        '.eva-gift-wrap-section--order-summary'
      );
      var totals = document.querySelector(
        '.wp-block-woocommerce-checkout-order-summary-totals-block'
      );
      if (!section || !totals || !totals.parentNode) return;
      // If already before totals, skip.
      if (section.nextSibling === totals) return;
      totals.parentNode.insertBefore(section, totals);
    }

    // Slot/Fill (preferred, supported API).
    if (
      window.wc &&
      window.wc.blocksCheckout &&
      typeof window.wc.blocksCheckout.registerCheckoutBlockExtension ===
        'function' &&
      typeof window.wp !== 'undefined' &&
      window.wp.element &&
      typeof window.wp.element.createElement === 'function'
    ) {
      try {
        window.wc.blocksCheckout.registerCheckoutBlockExtension(
          'eva-gift-wrap',
          {
            metadata: { name: 'eva-gift-wrap' },
            render: function render(_ref) {
              var Slot = _ref.Slot;
              return createElement(
                Slot,
                { name: 'order-summary' },
                function () {
                  return createElement(
                    'div',
                    {
                      className:
                        'wc-block-components-panel eva-gift-wrap-section eva-gift-wrap-section--order-summary',
                    },
                    createElement(GiftWrapAccordion, null)
                  );
                }
              );
            },
          }
        );
        // After render, ensure placement before totals if needed.
        setTimeout(relocateBeforeTotals, 0);
        var observerSlot = new MutationObserver(relocateBeforeTotals);
        observerSlot.observe(document.body, { childList: true, subtree: true });
        setTimeout(function () {
          try {
            observerSlot.disconnect();
          } catch (e2) {}
        }, 60000);
        return true;
      } catch (e) {
        // Fallback to DOM append below if Slot/Fill fails.
      }
    }

    // Fallback: append to the order summary content.
    var orderSummaryBlock = document.querySelector(
      '.wp-block-woocommerce-checkout-order-summary-block'
    );
    if (!orderSummaryBlock) return false;

    var summaryContent = orderSummaryBlock.querySelector(
      '.wc-block-components-checkout-order-summary__content'
    );
    var target = summaryContent || orderSummaryBlock;

    if (target.querySelector('.eva-gift-wrap-section--order-summary')) {
      return true;
    }

    var container = document.createElement('div');
    container.className =
      'wc-block-components-panel eva-gift-wrap-section eva-gift-wrap-section--order-summary';
    target.appendChild(container);

    if (
      window.wp &&
      window.wp.element &&
      typeof window.wp.element.render === 'function'
    ) {
      window.wp.element.render(
        createElement(GiftWrapAccordion, null),
        container
      );
      relocateBeforeTotals();
      var observerFallback = new MutationObserver(relocateBeforeTotals);
      observerFallback.observe(target, { childList: true, subtree: true });
      setTimeout(function () {
        try {
          observerFallback.disconnect();
        } catch (e3) {}
      }, 60000);
      return true;
    }

    return false;
  }

  // Observe the checkout DOM to mount when elements are available (handles mobile lazy rendering).
  function observeAndMount() {
    var triedImmediate = false;

    function tryMount() {
      var hasSummary = !!document.querySelector(
        '.eva-gift-wrap-section--order-summary'
      );
      if (hasSummary) {
        return true;
      }
      return mountGiftWrap();
    }

    // Try once immediately.
    triedImmediate = tryMount();
    if (triedImmediate) return;

    // Find a stable root to observe.
    var root =
      document.querySelector('.wc-block-checkout') ||
      document.querySelector('#checkout') ||
      document.body;

    var observer = new MutationObserver(function () {
      if (tryMount()) {
        observer.disconnect();
      }
    });

    observer.observe(root, { childList: true, subtree: true });

    // Also attempt to mount when the order summary/drawer is toggled by user clicks.
    var clickHandler = function (e) {
      var toggle =
        e.target && e.target.closest
          ? e.target.closest(
              '.wc-block-components-order-summary__button,' +
                '.wc-block-components-order-summary__toggle,' +
                '.wc-block-components-order-summary-toggle,' +
                '.wc-block-components-drawer__toggle,' +
                '.wc-block-checkout__order-summary-toggle'
            )
          : null;
      if (toggle) {
        // Give the drawer time to render.
        setTimeout(tryMount, 0);
        setTimeout(tryMount, 250);
        setTimeout(tryMount, 750);
      }
    };
    document.addEventListener('click', clickHandler, true);

    // Safety: stop observing after 60s to avoid leaking, and remove click handler.
    setTimeout(function () {
      try {
        observer.disconnect();
      } catch (e) {}
      try {
        document.removeEventListener('click', clickHandler, true);
      } catch (e2) {}
    }, 60000);
  }

  // Wait for DOM to be ready, then observe and mount.
  if (
    document.readyState === 'complete' ||
    document.readyState === 'interactive'
  ) {
    observeAndMount();
  } else {
    document.addEventListener('DOMContentLoaded', observeAndMount);
  }
})();
