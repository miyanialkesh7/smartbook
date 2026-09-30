/**
 * SmartBook public scripts.
 *
 * Relies on the `sbPublic` object localized by
 * SmartBook\Assets\FrontendAssetLoader (ajaxUrl, restUrl, nonce).
 */
( function ( window, document ) {
	'use strict';

	if ( typeof window.sbPublic === 'undefined' ) {
		return;
	}

	/**
	 * Progressively enhance every "[data-sb-gallery]" grid (see
	 * Frontend\BookContentDisplay::render_gallery(), the book page's
	 * photo gallery) so clicking a thumbnail opens its full-size image
	 * in an on-page modal -- with Previous/Next through that same
	 * gallery's other photos -- instead of navigating away. Without
	 * JavaScript, each thumbnail is still a plain link to its full-size
	 * image (opens in a new tab).
	 */
	function sb_initGalleryModal() {
		var sb_galleries = document.querySelectorAll( '[data-sb-gallery]' );

		if ( 0 === sb_galleries.length ) {
			return;
		}

		var sb_modal = document.createElement( 'div' );
		sb_modal.className = 'sb-gallery-modal sb-hidden';
		sb_modal.setAttribute( 'role', 'dialog' );
		sb_modal.setAttribute( 'aria-modal', 'true' );
		sb_modal.setAttribute( 'aria-label', 'Gallery image' );
		sb_modal.innerHTML =
			'<div class="sb-gallery-modal__backdrop" data-sb-gallery-close></div>' +
			'<div class="sb-gallery-modal__content">' +
			'<button type="button" class="sb-gallery-modal__close" data-sb-gallery-close aria-label="Close">&times;</button>' +
			'<button type="button" class="sb-gallery-modal__nav sb-gallery-modal__nav--prev" data-sb-gallery-prev aria-label="Previous image">&#8249;</button>' +
			'<img class="sb-gallery-modal__image" alt="" />' +
			'<button type="button" class="sb-gallery-modal__nav sb-gallery-modal__nav--next" data-sb-gallery-next aria-label="Next image">&#8250;</button>' +
			'</div>';

		document.body.appendChild( sb_modal );

		var sb_image = sb_modal.querySelector( '.sb-gallery-modal__image' );
		var sb_items = [];
		var sb_index = 0;

		var sb_show = function ( sb_i ) {
			if ( 0 === sb_items.length ) {
				return;
			}

			sb_index = ( sb_i + sb_items.length ) % sb_items.length;

			var sb_item = sb_items[ sb_index ];
			sb_image.src = sb_item.getAttribute( 'data-sb-gallery-full' );
			sb_image.alt = sb_item.getAttribute( 'data-sb-gallery-alt' ) || '';
		};

		var sb_open = function ( sb_galleryItems, sb_startIndex ) {
			sb_items = sb_galleryItems;
			sb_show( sb_startIndex );
			sb_modal.classList.remove( 'sb-hidden' );
			document.body.classList.add( 'sb-gallery-modal-open' );
		};

		var sb_close = function () {
			sb_modal.classList.add( 'sb-hidden' );
			document.body.classList.remove( 'sb-gallery-modal-open' );
			sb_image.src = '';
		};

		sb_modal.addEventListener( 'click', function ( sb_event ) {
			if ( sb_event.target.hasAttribute( 'data-sb-gallery-close' ) ) {
				sb_close();
			} else if ( sb_event.target.closest( '[data-sb-gallery-prev]' ) ) {
				sb_show( sb_index - 1 );
			} else if ( sb_event.target.closest( '[data-sb-gallery-next]' ) ) {
				sb_show( sb_index + 1 );
			}
		} );

		document.addEventListener( 'keydown', function ( sb_event ) {
			if ( sb_modal.classList.contains( 'sb-hidden' ) ) {
				return;
			}

			if ( 'Escape' === sb_event.key ) {
				sb_close();
			} else if ( 'ArrowLeft' === sb_event.key ) {
				sb_show( sb_index - 1 );
			} else if ( 'ArrowRight' === sb_event.key ) {
				sb_show( sb_index + 1 );
			}
		} );

		sb_galleries.forEach( function ( sb_gallery ) {
			var sb_galleryItems = Array.prototype.slice.call( sb_gallery.querySelectorAll( '.sb-book-gallery__item' ) );

			sb_galleryItems.forEach( function ( sb_link, sb_i ) {
				sb_link.addEventListener( 'click', function ( sb_event ) {
					sb_event.preventDefault();
					sb_open( sb_galleryItems, sb_i );
				} );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		sb_initGalleryModal();
		document.dispatchEvent( new CustomEvent( 'sb:public:ready', { detail: window.sbPublic } ) );
	} );
} )( window, document );
