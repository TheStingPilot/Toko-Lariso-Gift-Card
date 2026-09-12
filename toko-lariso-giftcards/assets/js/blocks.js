( function ( wp, wc ) {
	'use strict';

	if ( ! wp || ! wc || ! wc.blocksCheckout || ! wc.wcBlocksData ) {
		return;
	}

	var createElement = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useSelect = wp.data.useSelect;
	var __ = wp.i18n.__;
	var registerPlugin = wp.plugins.registerPlugin;
	var ExperimentalDiscountsMeta = wc.blocksCheckout.ExperimentalDiscountsMeta;
	var ExperimentalOrderMeta = wc.blocksCheckout.ExperimentalOrderMeta;
	var extensionCartUpdate = wc.blocksCheckout.extensionCartUpdate;
	var registerCheckoutFilters = wc.blocksCheckout.registerCheckoutFilters || wc.blocksCheckout.__experimentalRegisterCheckoutFilters;
	var processErrorResponse = wc.wcBlocksData.processErrorResponse;
	var cartStore = wc.wcBlocksData.cartStore;

	if ( ! ExperimentalDiscountsMeta || ! extensionCartUpdate || ! cartStore ) {
		return;
	}

	var namespace = 'tokolariso-giftcards';
	var pendingUpdate = false;
	var pendingListeners = [];
	var latestExtensionData = {};
	var latestPlaceOrderDefaultLabel = '';

	function setPendingUpdate( value ) {
		pendingUpdate = value;
		pendingListeners.forEach( function ( listener ) {
			listener( value );
		} );
	}

	function subscribePendingUpdate( listener ) {
		pendingListeners.push( listener );

		return function () {
			pendingListeners = pendingListeners.filter( function ( current ) {
				return current !== listener;
			} );
		};
	}

	function getExtensionData( cartData ) {
		if ( ! cartData || ! cartData.extensions ) {
			return {};
		}
		return cartData.extensions[ namespace ] || {};
	}

	function hasGiftcardExtensionShape( data ) {
		return (
			data &&
			typeof data === 'object' &&
			(
				Array.isArray( data.applied ) ||
				Object.prototype.hasOwnProperty.call( data, 'total_applied' ) ||
				Object.prototype.hasOwnProperty.call( data, 'remaining_total_formatted' ) ||
				Object.prototype.hasOwnProperty.call( data, 'can_apply' )
			)
		);
	}

	function rememberExtensionData( data ) {
		if ( hasGiftcardExtensionShape( data ) ) {
			latestExtensionData = data;
		}
		return data || {};
	}

	function getCurrentCartExtensionData() {
		if ( ! wp.data || ! wp.data.select ) {
			return {};
		}

		var selectedCartStore = wp.data.select( cartStore );
		if ( ! selectedCartStore || ! selectedCartStore.getCartData ) {
			return {};
		}

		return getExtensionData( selectedCartStore.getCartData() );
	}

	function getCheckoutFilterData( extensions, args ) {
		if ( extensions && extensions[ namespace ] ) {
			return rememberExtensionData( extensions[ namespace ] );
		}
		if ( args && args.cart && args.cart.extensions && args.cart.extensions[ namespace ] ) {
			return rememberExtensionData( args.cart.extensions[ namespace ] );
		}

		var currentCartExtensionData = getCurrentCartExtensionData();
		if ( hasGiftcardExtensionShape( currentCartExtensionData ) ) {
			return rememberExtensionData( currentCartExtensionData );
		}

		return latestExtensionData;
	}

	function getPlaceOrderLabel( data, defaultLabel ) {
		if ( data && data.total_applied > 0 && data.remaining_total_formatted ) {
			return ( defaultLabel || latestPlaceOrderDefaultLabel || __( 'Bestellen en betalen', 'toko-lariso-giftcards' ) ) + ' · ' + data.remaining_total_formatted;
		}
		return '';
	}

	function getPlaceOrderButtons() {
		return document.querySelectorAll(
			'.wc-block-components-checkout-place-order-button, .wc-block-checkout__actions_row button[type="submit"], .wc-block-checkout__actions button[type="submit"]'
		);
	}

	function setPlaceOrderButtonOverlayLabel( button, label ) {
		if ( ! button ) {
			return;
		}

		if ( ! latestPlaceOrderDefaultLabel && button.textContent ) {
			latestPlaceOrderDefaultLabel = button.textContent.replace( /\s+/g, ' ' ).trim().replace( /\s+[·-]\s+€\s?[\d.,]+$/, '' );
		}

		if ( label ) {
			if ( ! button.dataset.tokolarisoAriaManaged ) {
				button.dataset.tokolarisoOriginalAria = button.getAttribute( 'aria-label' ) || '';
			}

			button.classList.add( 'tokolariso-giftcard-place-order-button' );
			button.dataset.tokolarisoLabel = label;
			button.dataset.tokolarisoAriaManaged = '1';
			button.setAttribute( 'aria-label', label );
			return;
		}

		if ( button.dataset.tokolarisoAriaManaged ) {
			if ( button.dataset.tokolarisoOriginalAria ) {
				button.setAttribute( 'aria-label', button.dataset.tokolarisoOriginalAria );
			} else {
				button.removeAttribute( 'aria-label' );
			}
		}

		button.classList.remove( 'tokolariso-giftcard-place-order-button' );
		delete button.dataset.tokolarisoLabel;
		delete button.dataset.tokolarisoAriaManaged;
		delete button.dataset.tokolarisoOriginalAria;
	}

	function syncPlaceOrderButtonOverlay( data ) {
		var label = getPlaceOrderLabel( data );
		getPlaceOrderButtons().forEach( function ( button ) {
			setPlaceOrderButtonOverlayLabel( button, label );
		} );
	}

	function useGiftcardExtensionData() {
		return rememberExtensionData(
			getExtensionData(
				useSelect(
					function ( select ) {
						return select( cartStore ).getCartData();
					},
					[]
				)
			)
		);
	}

	function useBusyState() {
		var busyState = useState( pendingUpdate );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];

		useEffect(
			function () {
				return subscribePendingUpdate( setBusy );
			},
			[]
		);

		return busy;
	}

	function runGiftcardUpdate( data, successMessage, setMessage, onSuccess ) {
		if ( pendingUpdate ) {
			return;
		}

		setPendingUpdate( true );
		setMessage( '' );
		extensionCartUpdate( {
			namespace: namespace,
			data: data,
		} )
			.then( function () {
				if ( onSuccess ) {
					onSuccess();
				}
				setMessage( successMessage || '' );
			} )
			.catch( function ( error ) {
				if ( processErrorResponse ) {
					processErrorResponse( error );
				}
				setMessage( __( 'The giftcard could not be applied. Please check the code and try again.', 'toko-lariso-giftcards' ) );
			} )
			.finally( function () {
				setPendingUpdate( false );
			} );
	}

	function GiftcardPanel() {
		var extensionData = useGiftcardExtensionData();
		var applied = Array.isArray( extensionData.applied ) ? extensionData.applied : [];
		var allowMultiple = extensionData.allow_multiple !== false;
		var canApply = extensionData.can_apply !== false;
		var blockedMessage =
			extensionData.blocked_message ||
			__( 'Giftcards cannot be used to buy another giftcard.', 'toko-lariso-giftcards' );
		var renderAppliedInPanel = ! ExperimentalOrderMeta;
		var state = useState( '' );
		var code = state[ 0 ];
		var setCode = state[ 1 ];
		var amountState = useState( '' );
		var maxAmount = amountState[ 0 ];
		var setMaxAmount = amountState[ 1 ];
		var busy = useBusyState();
		var messageState = useState( '' );
		var message = messageState[ 0 ];
		var setMessage = messageState[ 1 ];

		function onApply( event ) {
			event.preventDefault();
			if ( busy || pendingUpdate ) {
				return;
			}
			if ( ! canApply ) {
				setMessage( blockedMessage );
				return;
			}
			if ( ! code.trim() ) {
				setMessage( __( 'Please enter a giftcard code.', 'toko-lariso-giftcards' ) );
				return;
			}
			runGiftcardUpdate(
				{
					action: 'apply',
					code: code.trim(),
					max_amount: maxAmount.trim(),
				},
				__( 'Giftcard applied.', 'toko-lariso-giftcards' ),
				setMessage,
				function () {
					setCode( '' );
					setMaxAmount( '' );
				}
			);
		}

		function onRemove( id ) {
			if ( busy || pendingUpdate ) {
				return;
			}

			runGiftcardUpdate(
				{
					action: 'remove',
					id: id,
				},
				__( 'Giftcard removed.', 'toko-lariso-giftcards' ),
				setMessage
			);
		}

		if ( ! allowMultiple && applied.length > 0 ) {
			if ( ExperimentalOrderMeta ) {
				return null;
			}

			return createElement(
				'div',
				{ className: 'wc-block-components-totals-wrapper tokolariso-giftcard-block' },
				renderApplied( applied, onRemove, busy, extensionData ),
				message ? createElement( 'p', { className: 'tokolariso-giftcard-message' }, message ) : null
			);
		}

		return createElement(
			'div',
			{ className: 'wc-block-components-totals-wrapper tokolariso-giftcard-block' },
			createElement( 'strong', { className: 'tokolariso-giftcard-title' }, __( 'Giftcard', 'toko-lariso-giftcards' ) ),
			! canApply
				? createElement( 'p', { className: 'tokolariso-giftcard-message tokolariso-giftcard-message--blocked' }, blockedMessage )
				: null,
			createElement(
				'form',
				{ className: 'tokolariso-giftcard-form', onSubmit: onApply },
				createElement( 'input', {
					type: 'text',
					value: code,
					onChange: function ( event ) {
						setCode( event.target.value );
					},
					placeholder: __( 'Enter giftcard code', 'toko-lariso-giftcards' ),
					disabled: ! canApply || busy || pendingUpdate,
					autoComplete: 'off',
				} ),
				createElement( 'input', {
					type: 'text',
					value: maxAmount,
					onChange: function ( event ) {
						setMaxAmount( event.target.value );
					},
					placeholder: __( 'Amount to use (optional)', 'toko-lariso-giftcards' ),
					'aria-label': __( 'Maximum giftcard amount to use', 'toko-lariso-giftcards' ),
					disabled: ! canApply || busy || pendingUpdate,
					inputMode: 'decimal',
				} ),
				createElement(
					'button',
					{
						type: 'submit',
						disabled: ! canApply || busy || pendingUpdate,
						className: 'tokolariso-giftcard-apply wc-block-components-button wp-element-button',
						onClick: function ( event ) {
							event.stopPropagation();
						},
					},
					busy ? __( 'Applying...', 'toko-lariso-giftcards' ) : __( 'Apply', 'toko-lariso-giftcards' )
				)
			),
			renderAppliedInPanel ? renderApplied( applied, onRemove, busy, extensionData ) : null,
			message ? createElement( 'p', { className: 'tokolariso-giftcard-message' }, message ) : null
		);
	}

	function GiftcardOrderSummary() {
		var extensionData = useGiftcardExtensionData();
		var applied = Array.isArray( extensionData.applied ) ? extensionData.applied : [];
		var busy = useBusyState();
		var messageState = useState( '' );
		var message = messageState[ 0 ];
		var setMessage = messageState[ 1 ];

		function onRemove( id ) {
			if ( busy || pendingUpdate ) {
				return;
			}

			runGiftcardUpdate(
				{
					action: 'remove',
					id: id,
				},
				__( 'Giftcard removed.', 'toko-lariso-giftcards' ),
				setMessage
			);
		}

		if ( ! applied.length ) {
			return null;
		}

		return createElement(
			'div',
			{ className: 'wc-block-components-totals-wrapper tokolariso-giftcard-summary' },
			renderApplied( applied, onRemove, busy, extensionData ),
			message ? createElement( 'p', { className: 'tokolariso-giftcard-message' }, message ) : null
		);
	}

	function GiftcardCheckoutButtonOverlaySync() {
		var extensionData = useGiftcardExtensionData();

		useEffect(
			function () {
				var observer;
				var run = function () {
					syncPlaceOrderButtonOverlay( extensionData );
				};

				run();
				window.setTimeout( run, 50 );

				if ( window.MutationObserver && document.body ) {
					observer = new window.MutationObserver( run );
					observer.observe( document.body, {
						childList: true,
						subtree: true,
					} );
				}

				return function () {
					if ( observer ) {
						observer.disconnect();
					}
				};
			},
			[ extensionData.total_applied, extensionData.remaining_total_formatted ]
		);

		return null;
	}

	function renderApplied( applied, onRemove, busy, extensionData ) {
		if ( ! applied.length ) {
			return null;
		}

		return createElement(
			'div',
			{ className: 'tokolariso-giftcard-applied' },
			createElement( 'div', { className: 'tokolariso-giftcard-applied-heading' }, __( 'Giftcard partial payment', 'toko-lariso-giftcards' ) ),
			applied.map( function ( card ) {
				return createElement(
					'div',
					{ className: 'tokolariso-giftcard-applied-row', key: card.id },
					createElement(
						'span',
						null,
						card.code,
						' ',
						createElement( 'strong', null, card.amount_formatted ),
						card.remaining_balance_formatted
							? createElement(
									'small',
									{ className: 'tokolariso-giftcard-remaining' },
									' ' + __( 'Remaining:', 'toko-lariso-giftcards' ) + ' ' + card.remaining_balance_formatted
							  )
							: null,
						card.max_amount_formatted
							? createElement(
									'small',
									{ className: 'tokolariso-giftcard-limit' },
									' ' + __( 'Maximum used:', 'toko-lariso-giftcards' ) + ' ' + card.max_amount_formatted
							  )
							: null
					),
					createElement(
						'button',
						{
							type: 'button',
							disabled: busy || pendingUpdate,
							onClick: function () {
								onRemove( card.id );
							},
						},
						__( 'Remove', 'toko-lariso-giftcards' )
					)
				);
			} ),
			extensionData.total_applied_formatted
				? createElement(
						'p',
						{ className: 'tokolariso-giftcard-total' },
						__( 'Paid with giftcard:', 'toko-lariso-giftcards' ) + ' ' + extensionData.total_applied_formatted
				  )
				: null,
			extensionData.remaining_total_formatted
				? createElement(
						'p',
						{ className: 'tokolariso-giftcard-due' },
						__( 'Amount to pay with selected payment method:', 'toko-lariso-giftcards' ) + ' ' + extensionData.remaining_total_formatted
				  )
				: null
		);
	}

	registerPlugin( 'tokolariso-giftcards', {
		render: function () {
			return createElement(
				Fragment,
				null,
				createElement( ExperimentalDiscountsMeta, null, createElement( GiftcardPanel ) ),
				ExperimentalOrderMeta ? createElement( ExperimentalOrderMeta, null, createElement( GiftcardOrderSummary ) ) : null,
				createElement( GiftcardCheckoutButtonOverlaySync )
			);
		},
		scope: 'woocommerce-checkout',
	} );

	if ( registerCheckoutFilters ) {
		registerCheckoutFilters( namespace, {
			placeOrderButtonLabel: function ( defaultValue, extensions, args ) {
				latestPlaceOrderDefaultLabel = defaultValue || latestPlaceOrderDefaultLabel;
				window.setTimeout( function () {
					syncPlaceOrderButtonOverlay( getCheckoutFilterData( extensions, args ) );
				}, 0 );
				return defaultValue;
			},
		} );
	}
} )( window.wp, window.wc );
