/**
 * Add/Edit Book pages: WP media library pickers for the cover image and
 * gallery fields.
 *
 * Every picker stores only attachment ids in a hidden input (see
 * SmartBook\Admin\Pages\AbstractBookFormPage::render_media_picker()) --
 * no file ever passes through this form directly, only ids of
 * attachments already in the media library, chosen via wp.media
 * (enqueued by AbstractBookFormPage::enqueue(), which also enqueues this
 * file). A picker's initial hidden value may already be populated on
 * load -- EditBookPage preloads the book's existing cover/gallery -- so
 * sb_initMediaPicker() renders from whatever value is present at init,
 * not just from a live wp.media selection.
 */
( function ( sb_window, sb_document ) {
	'use strict';

	if ( ! sb_window.wp || ! sb_window.wp.media ) {
		return;
	}

	/**
	 * Run a callback once the DOM is ready.
	 */
	function sb_ready( sb_callback ) {
		if ( 'loading' !== sb_document.readyState ) {
			sb_callback();
		} else {
			sb_document.addEventListener( 'DOMContentLoaded', sb_callback );
		}
	}

	/**
	 * Parse a picker's hidden input value ("123" or "123,456") into an
	 * array of positive integer attachment ids.
	 */
	function sb_parseIds( sb_value ) {
		return ( sb_value || '' )
			.split( ',' )
			.map( function ( sb_piece ) {
				return parseInt( sb_piece, 10 );
			} )
			.filter( function ( sb_id ) {
				return sb_id > 0;
			} );
	}

	/**
	 * Build one preview thumbnail: an <img>, plus (multiple mode only) a
	 * small "remove just this one" button. Looks up the attachment by id
	 * via wp.media's own model -- the only way to get a thumbnail URL
	 * back from an id alone, which is what a restored draft (see
	 * sb-admin.js' sb_initFormDraft()) or a page reload leaves us with.
	 */
	function sb_buildThumbnail( sb_id, sb_onRemove ) {
		var sb_figure = sb_document.createElement( 'figure' );
		sb_figure.className = 'sb-media-picker__thumb';

		var sb_img = sb_document.createElement( 'img' );
		sb_figure.appendChild( sb_img );

		if ( sb_onRemove ) {
			var sb_removeButton = sb_document.createElement( 'button' );
			sb_removeButton.type = 'button';
			sb_removeButton.className = 'sb-media-picker__thumb-remove';
			sb_removeButton.setAttribute( 'aria-label', 'Remove image' );
			sb_removeButton.innerHTML = '&times;';
			sb_removeButton.addEventListener( 'click', function () {
				sb_onRemove( sb_id );
			} );
			sb_figure.appendChild( sb_removeButton );
		}

		var sb_attachment = sb_window.wp.media.attachment( sb_id );

		sb_attachment.fetch().done( function () {
			var sb_sizes = sb_attachment.get( 'sizes' ) || {};
			sb_img.src = ( sb_sizes.thumbnail && sb_sizes.thumbnail.url ) || sb_attachment.get( 'url' ) || '';
		} );

		return sb_figure;
	}

	/**
	 * Wire up a single "[data-sb-media-picker]" widget.
	 */
	function sb_initMediaPicker( sb_picker ) {
		var sb_mode = sb_picker.getAttribute( 'data-sb-media-mode' ) || 'single';
		var sb_valueInput = sb_picker.querySelector( '.sb-media-picker__value' );
		var sb_preview = sb_picker.querySelector( '.sb-media-picker__preview' );
		var sb_selectButton = sb_picker.querySelector( '.sb-media-picker__select' );
		var sb_removeButton = sb_picker.querySelector( '.sb-media-picker__remove' );

		if ( ! sb_valueInput || ! sb_preview || ! sb_selectButton ) {
			return;
		}

		var sb_renderPreview = function () {
			var sb_ids = sb_parseIds( sb_valueInput.value );

			sb_preview.innerHTML = '';

			sb_ids.forEach( function ( sb_id ) {
				var sb_onRemove = 'multiple' === sb_mode ? sb_removeOne : null;
				sb_preview.appendChild( sb_buildThumbnail( sb_id, sb_onRemove ) );
			} );

			if ( sb_removeButton ) {
				sb_removeButton.classList.toggle( 'sb-hidden', 0 === sb_ids.length );
			}
		};

		var sb_setIds = function ( sb_ids ) {
			sb_valueInput.value = sb_ids.join( ',' );
			sb_renderPreview();
		};

		function sb_removeOne( sb_id ) {
			sb_setIds( sb_parseIds( sb_valueInput.value ).filter( function ( sb_existingId ) {
				return sb_existingId !== sb_id;
			} ) );
		}

		// Repaint whenever the value changes for any reason other than
		// our own sb_setIds() above -- in particular, a restored draft
		// cookie (sb-admin.js' sb_initFormDraft()) sets .value directly
		// and dispatches "input", which this also catches.
		sb_valueInput.addEventListener( 'input', sb_renderPreview );

		sb_selectButton.addEventListener( 'click', function () {
			var sb_frame = sb_window.wp.media( {
				title: sb_selectButton.textContent,
				multiple: 'multiple' === sb_mode,
				library: { type: 'image' },
				button: { text: sb_selectButton.textContent }
			} );

			sb_frame.on( 'select', function () {
				var sb_selection = sb_frame.state().get( 'selection' ).toJSON();
				var sb_selectedIds = sb_selection.map( function ( sb_attachment ) {
					return sb_attachment.id;
				} );

				if ( 'multiple' === sb_mode ) {
					var sb_merged = sb_parseIds( sb_valueInput.value );

					sb_selectedIds.forEach( function ( sb_id ) {
						if ( -1 === sb_merged.indexOf( sb_id ) ) {
							sb_merged.push( sb_id );
						}
					} );

					sb_setIds( sb_merged );
				} else {
					sb_setIds( sb_selectedIds.slice( 0, 1 ) );
				}
			} );

			sb_frame.open();
		} );

		if ( sb_removeButton ) {
			sb_removeButton.addEventListener( 'click', function () {
				sb_setIds( [] );
			} );
		}

		sb_renderPreview();
	}

	function sb_initMediaPickers( sb_scope ) {
		sb_scope.querySelectorAll( '[data-sb-media-picker]' ).forEach( sb_initMediaPicker );
	}

	sb_ready( function () {
		sb_initMediaPickers( sb_document );
	} );
} )( window, document );
