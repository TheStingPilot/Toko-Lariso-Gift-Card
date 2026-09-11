( function () {
	'use strict';

	function textFromHtml( value ) {
		var element = document.createElement( 'span' );
		element.innerHTML = value || '';
		return element.textContent || element.innerText || '';
	}

	function updateBuilder( builder ) {
		var checkedAmount = builder.querySelector( 'input[name="tokolariso_giftcard_amount_choice"]:checked' );
		var customAmount = builder.querySelector( 'input[name="tokolariso_giftcard_custom_amount"]' );
		var previewAmount = builder.querySelector( '[data-tokolariso-preview-amount]' );
		var checkedImage = builder.querySelector( 'input[name="tokolariso_giftcard_image_id"]:checked' );
		var previewImage = builder.querySelector( '[data-tokolariso-preview-image]' );
		var message = builder.querySelector( '#tokolariso_giftcard_message' );
		var counter = builder.querySelector( '[data-tokolariso-message-count]' );

		if ( previewAmount && checkedAmount ) {
			if ( 'custom' === checkedAmount.value && customAmount && customAmount.value ) {
				previewAmount.textContent = customAmount.value;
			} else {
				previewAmount.textContent = textFromHtml( checkedAmount.getAttribute( 'data-price' ) );
			}
		}

		if ( previewImage && checkedImage && checkedImage.getAttribute( 'data-image' ) ) {
			previewImage.src = checkedImage.getAttribute( 'data-image' );
		}

		if ( counter && message ) {
			counter.textContent = String( message.value.length );
		}
	}

	function cleanupGiftcardZoom() {
		if ( ! document.querySelector( '.product-type-tokolarisogiftcard, body.tokolariso-giftcard-product-page' ) ) {
			return;
		}

		document.querySelectorAll(
			'.woocommerce-product-gallery__trigger, .zoomImg, .zoomContainer, .zoomLens, .zoomWindow'
		).forEach( function ( element ) {
			element.remove();
		} );

		document.querySelectorAll( '.woocommerce-product-gallery__image a' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
			} );
			link.removeAttribute( 'href' );
		} );
	}

	function forceSingleGiftcardQuantity() {
		if ( ! document.querySelector( '.product-type-tokolarisogiftcard, body.tokolariso-giftcard-product-page' ) ) {
			return;
		}

		document.querySelectorAll( 'form.cart input.qty, form.cart input[name="quantity"]' ).forEach( function ( input ) {
			input.value = '1';
			input.setAttribute( 'value', '1' );
			input.setAttribute( 'min', '1' );
			input.setAttribute( 'max', '1' );
		} );
	}

	function cleanupGiftcardPage() {
		cleanupGiftcardZoom();
		forceSingleGiftcardQuantity();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var observer;

		document.querySelectorAll( '.tokolariso-giftcard-fields' ).forEach( function ( builder ) {
			builder.addEventListener( 'input', function () {
				updateBuilder( builder );
			} );
			builder.addEventListener( 'change', function () {
				updateBuilder( builder );
			} );
			updateBuilder( builder );
		} );

		cleanupGiftcardPage();
		if ( window.MutationObserver && document.body ) {
			observer = new window.MutationObserver( cleanupGiftcardPage );
			observer.observe( document.body, {
				childList: true,
				subtree: true,
			} );
		}
	} );
}() );
