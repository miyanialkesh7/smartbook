/**
 * SmartBook admin scripts.
 *
 * Progressively enhances the server-rendered admin markup (meta box,
 * dashboard, statistics, import/export, settings) — every feature here
 * degrades gracefully to plain HTML if JavaScript never runs. Relies on
 * the `sbAdmin` object localized by SmartBook\Assets\AdminAssetLoader
 * (ajaxUrl, nonce); that object is a pre-existing PHP<->JS data
 * contract kept in its established camelCase form, everything else
 * declared in this file is prefixed sb_.
 */
( function ( sb_window, sb_document ) {
	'use strict';

	if ( typeof sb_window.sbAdmin === 'undefined' ) {
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
	 * Add a dismiss button to every notice that doesn't already have one.
	 */
	function sb_initNotices( sb_scope ) {
		var sb_notices = sb_scope.querySelectorAll( '.sb-notice' );

		sb_notices.forEach( function ( sb_notice ) {
			if ( sb_notice.querySelector( '.sb-notice__dismiss' ) ) {
				return;
			}

			var sb_dismiss = sb_document.createElement( 'button' );
			sb_dismiss.type = 'button';
			sb_dismiss.className = 'sb-notice__dismiss';
			sb_dismiss.setAttribute( 'aria-label', 'Dismiss this notice' );
			sb_dismiss.innerHTML = '&times;';

			sb_dismiss.addEventListener( 'click', function () {
				sb_notice.classList.add( 'sb-hidden' );
			} );

			sb_notice.appendChild( sb_dismiss );
		} );
	}

	/**
	 * Turn each meta box section into a collapsible accordion panel.
	 * Section titles/panels are matched purely by their existing DOM
	 * order (a title immediately followed by a ".sb-meta-box__grid"),
	 * so no server-side markup changes are required; every id this
	 * generates is created here, so it is guaranteed "sb-" prefixed.
	 */
	function sb_initAccordions( sb_scope ) {
		var sb_titles = sb_scope.querySelectorAll( '.sb-meta-box__section-title' );

		sb_titles.forEach( function ( sb_title, sb_index ) {
			var sb_panel = sb_title.nextElementSibling;

			if ( ! sb_panel || ! sb_panel.classList.contains( 'sb-meta-box__grid' ) ) {
				return;
			}

			var sb_panelId = 'sb-section-panel-' + sb_index;
			sb_panel.id = sb_panelId;

			var sb_toggle = sb_document.createElement( 'button' );
			sb_toggle.type = 'button';
			sb_toggle.className = 'sb-accordion__toggle';
			sb_toggle.setAttribute( 'aria-expanded', 'true' );
			sb_toggle.setAttribute( 'aria-controls', sb_panelId );
			sb_toggle.innerHTML = sb_title.textContent + '<span class="sb-accordion__icon" aria-hidden="true"></span>';

			sb_title.textContent = '';
			sb_title.appendChild( sb_toggle );

			sb_toggle.addEventListener( 'click', function () {
				var sb_expanded = 'true' === sb_toggle.getAttribute( 'aria-expanded' );
				sb_toggle.setAttribute( 'aria-expanded', sb_expanded ? 'false' : 'true' );
				sb_panel.classList.toggle( 'sb-hidden', sb_expanded );
			} );
		} );
	}

	/**
	 * Replace the plain "sb_rating" number input with a clickable star
	 * picker. The original input is kept (visually hidden) so the value
	 * still submits normally with the rest of the form.
	 */
	function sb_initRatingFields( sb_scope ) {
		var sb_inputs = sb_scope.querySelectorAll( 'input[type="number"][name="sb_rating"]' );

		sb_inputs.forEach( function ( sb_input ) {
			var sb_max = parseInt( sb_input.getAttribute( 'max' ), 10 ) || 5;
			var sb_value = parseInt( sb_input.value, 10 ) || 0;

			var sb_wrapper = sb_document.createElement( 'div' );
			sb_wrapper.className = 'sb-rating';
			sb_wrapper.setAttribute( 'role', 'radiogroup' );
			sb_wrapper.setAttribute( 'aria-label', 'Rating' );

			var sb_paintStars = function () {
				sb_wrapper.querySelectorAll( '.sb-rating__star' ).forEach( function ( sb_star ) {
					var sb_starValue = parseInt( sb_star.getAttribute( 'data-sb-value' ), 10 );
					sb_star.classList.toggle( 'sb-rating__star--filled', sb_starValue <= sb_value );
					sb_star.setAttribute( 'aria-checked', sb_starValue === sb_value ? 'true' : 'false' );
				} );
			};

			for ( var sb_i = 1; sb_i <= sb_max; sb_i++ ) {
				var sb_star = sb_document.createElement( 'button' );
				sb_star.type = 'button';
				sb_star.className = 'sb-rating__star';
				sb_star.setAttribute( 'data-sb-value', String( sb_i ) );
				sb_star.setAttribute( 'role', 'radio' );
				sb_star.setAttribute( 'aria-label', sb_i + ( 1 === sb_i ? ' star' : ' stars' ) );
				sb_star.innerHTML = '&#9733;';

				sb_star.addEventListener( 'click', function ( sb_event ) {
					var sb_clickedValue = parseInt( sb_event.currentTarget.getAttribute( 'data-sb-value' ), 10 );
					sb_value = sb_clickedValue === sb_value ? 0 : sb_clickedValue;
					sb_input.value = String( sb_value );
					sb_input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					sb_paintStars();
				} );

				sb_wrapper.appendChild( sb_star );
			}

			sb_paintStars();
			sb_input.classList.add( 'sb-hidden' );
			sb_input.insertAdjacentElement( 'afterend', sb_wrapper );
		} );
	}

	/**
	 * Add a live visual bar under the "sb_progress" number input that
	 * updates as the value changes.
	 */
	function sb_initProgressFields( sb_scope ) {
		var sb_inputs = sb_scope.querySelectorAll( 'input[type="number"][name="sb_progress"]' );

		sb_inputs.forEach( function ( sb_input ) {
			var sb_bar = sb_document.createElement( 'div' );
			sb_bar.className = 'sb-progress-bar';

			var sb_track = sb_document.createElement( 'div' );
			sb_track.className = 'sb-progress-bar__track';

			var sb_fill = sb_document.createElement( 'div' );
			sb_fill.className = 'sb-progress-bar__fill';

			var sb_label = sb_document.createElement( 'span' );
			sb_label.className = 'sb-progress-bar__label';

			sb_track.appendChild( sb_fill );
			sb_bar.appendChild( sb_track );
			sb_bar.appendChild( sb_label );

			var sb_updateBar = function () {
				var sb_percent = Math.min( 100, Math.max( 0, parseInt( sb_input.value, 10 ) || 0 ) );
				sb_fill.style.width = sb_percent + '%';
				sb_label.textContent = sb_percent + '%';
			};

			sb_input.addEventListener( 'input', sb_updateBar );
			sb_updateBar();

			sb_input.insertAdjacentElement( 'afterend', sb_bar );
		} );
	}

	/**
	 * Auto-resize every textarea inside the meta box to fit its content.
	 */
	function sb_initTextareaAutosize( sb_scope ) {
		var sb_textareas = sb_scope.querySelectorAll( '.sb-meta-box textarea' );

		sb_textareas.forEach( function ( sb_textarea ) {
			var sb_resize = function () {
				sb_textarea.style.height = 'auto';
				sb_textarea.style.height = sb_textarea.scrollHeight + 'px';
			};

			sb_textarea.addEventListener( 'input', sb_resize );
			sb_resize();
		} );
	}

	/**
	 * Ask for confirmation before submitting a form that uploads a file
	 * (the SmartBook CSV import form), since it can create or update
	 * many books at once.
	 */
	function sb_initImportConfirm( sb_scope ) {
		var sb_fileInputs = sb_scope.querySelectorAll( 'input[type="file"]' );

		sb_fileInputs.forEach( function ( sb_fileInput ) {
			var sb_form = sb_fileInput.closest( 'form' );

			if ( ! sb_form ) {
				return;
			}

			sb_form.addEventListener( 'submit', function ( sb_event ) {
				if ( ! sb_fileInput.files || 0 === sb_fileInput.files.length ) {
					return;
				}

				var sb_message = sb_window.sbAdmin.confirmImportMessage
					|| 'This will create or update books from the uploaded file. Continue?';

				if ( ! sb_window.confirm( sb_message ) ) {
					sb_event.preventDefault();
				}
			} );
		} );
	}

	/**
	 * Ask for confirmation before following any link marked with a
	 * "data-sb-confirm" message (the books table's Trash/Delete
	 * Permanently/Restore row actions).
	 */
	function sb_initConfirmLinks( sb_scope ) {
		var sb_links = sb_scope.querySelectorAll( '[data-sb-confirm]' );

		sb_links.forEach( function ( sb_link ) {
			sb_link.addEventListener( 'click', function ( sb_event ) {
				var sb_message = sb_link.getAttribute( 'data-sb-confirm' );

				if ( sb_message && ! sb_window.confirm( sb_message ) ) {
					sb_event.preventDefault();
				}
			} );
		} );
	}

	/**
	 * Wire up "[data-sb-print]" buttons (the QR label print sheet) to
	 * trigger the browser's print dialog.
	 */
	function sb_initPrintButtons( sb_scope ) {
		var sb_buttons = sb_scope.querySelectorAll( '[data-sb-print]' );

		sb_buttons.forEach( function ( sb_button ) {
			sb_button.addEventListener( 'click', function () {
				sb_window.print();
			} );
		} );
	}

	/**
	 * Turn each "[data-sb-tabs]" group of "[data-sb-tab-panel]" sections
	 * into a tab strip, using each panel's own data-sb-tab-panel value
	 * as the tab key and its (now-hidden) <h2> as the tab label. Every
	 * panel stays in the DOM, just hidden, so this is a pure
	 * display-layer enhancement over markup that already works fully
	 * stacked without it (Import/Export's per-tab forms, Settings'
	 * single shared form).
	 */
	function sb_initTabs( sb_scope ) {
		var sb_containers = sb_scope.querySelectorAll( '[data-sb-tabs]' );

		sb_containers.forEach( function ( sb_container ) {
			var sb_panels = Array.prototype.slice.call( sb_container.querySelectorAll( '[data-sb-tab-panel]' ) );

			if ( sb_panels.length < 2 ) {
				return;
			}

			var sb_nav = sb_document.createElement( 'div' );
			sb_nav.className = 'sb-tabs__nav';
			sb_nav.setAttribute( 'role', 'tablist' );

			var sb_activeKey = sb_container.getAttribute( 'data-sb-active-tab' ) || '';
			var sb_hasActive = sb_panels.some( function ( sb_panel ) {
				return sb_panel.getAttribute( 'data-sb-tab-panel' ) === sb_activeKey;
			} );

			if ( ! sb_hasActive ) {
				sb_activeKey = sb_panels[ 0 ].getAttribute( 'data-sb-tab-panel' );
			}

			var sb_showPanel = function ( sb_key ) {
				sb_panels.forEach( function ( sb_panel ) {
					sb_panel.classList.toggle( 'sb-hidden', sb_panel.getAttribute( 'data-sb-tab-panel' ) !== sb_key );
				} );

				sb_nav.querySelectorAll( '.sb-tabs__tab' ).forEach( function ( sb_tab ) {
					var sb_isActive = sb_tab.getAttribute( 'data-sb-tab' ) === sb_key;
					sb_tab.classList.toggle( 'sb-tabs__tab--active', sb_isActive );
					sb_tab.setAttribute( 'aria-selected', sb_isActive ? 'true' : 'false' );
				} );
			};

			sb_panels.forEach( function ( sb_panel ) {
				var sb_key = sb_panel.getAttribute( 'data-sb-tab-panel' );
				var sb_heading = sb_panel.querySelector( 'h2' );

				if ( ! sb_key || ! sb_heading ) {
					return;
				}

				var sb_tab = sb_document.createElement( 'button' );
				sb_tab.type = 'button';
				sb_tab.className = 'sb-tabs__tab';
				sb_tab.setAttribute( 'data-sb-tab', sb_key );
				sb_tab.setAttribute( 'role', 'tab' );
				sb_tab.textContent = sb_heading.textContent;

				sb_tab.addEventListener( 'click', function () {
					sb_showPanel( sb_key );
				} );

				sb_nav.appendChild( sb_tab );
				sb_heading.classList.add( 'sb-hidden' );
			} );

			sb_container.insertBefore( sb_nav, sb_container.firstChild );
			sb_showPanel( sb_activeKey );
		} );
	}

	/**
	 * Progressively enhance a "user_select" field (see
	 * BookFields::render_field()'s "user_select" case -- currently just
	 * "Borrowed To") into a searchable, scrollable combobox: a text box
	 * to filter by name, a scrollable dropdown of matches, and a small
	 * "x" button that appears once something is typed or selected and
	 * wipes both the search text and the underlying selection. The
	 * original <select> stays in the DOM (just visually hidden) and is
	 * what actually submits with the form -- this only ever assigns to
	 * its .value.
	 */
	function sb_initUserSelects( sb_scope ) {
		var sb_wrappers = sb_scope.querySelectorAll( '[data-sb-user-select]' );

		sb_wrappers.forEach( function ( sb_wrapper ) {
			var sb_native = sb_wrapper.querySelector( '.sb-user-select__native' );

			if ( ! sb_native ) {
				return;
			}

			sb_native.classList.add( 'sb-hidden' );

			var sb_options = Array.prototype.slice.call( sb_native.querySelectorAll( 'option' ) )
				.filter( function ( sb_option ) {
					return '' !== sb_option.value;
				} )
				.map( function ( sb_option ) {
					return { value: sb_option.value, label: sb_option.textContent };
				} );

			var sb_labelFor = function ( sb_value ) {
				var sb_match = sb_options.filter( function ( sb_opt ) {
					return sb_opt.value === sb_value;
				} )[ 0 ];

				return sb_match ? sb_match.label : '';
			};

			var sb_field = sb_document.createElement( 'div' );
			sb_field.className = 'sb-user-select__field';

			var sb_search = sb_document.createElement( 'input' );
			sb_search.type = 'text';
			sb_search.className = 'sb-user-select__input';
			sb_search.setAttribute( 'autocomplete', 'off' );
			sb_search.setAttribute( 'placeholder', 'Search…' );

			var sb_clear = sb_document.createElement( 'button' );
			sb_clear.type = 'button';
			sb_clear.className = 'sb-user-select__clear sb-hidden';
			sb_clear.setAttribute( 'aria-label', 'Clear' );
			sb_clear.innerHTML = '&times;';

			var sb_dropdown = sb_document.createElement( 'ul' );
			sb_dropdown.className = 'sb-user-select__dropdown sb-hidden';

			sb_field.appendChild( sb_search );
			sb_field.appendChild( sb_clear );
			sb_wrapper.appendChild( sb_field );
			sb_wrapper.appendChild( sb_dropdown );

			var sb_renderDropdown = function ( sb_query ) {
				sb_dropdown.innerHTML = '';

				var sb_matches = sb_options.filter( function ( sb_opt ) {
					return '' === sb_query || -1 !== sb_opt.label.toLowerCase().indexOf( sb_query );
				} );

				if ( 0 === sb_matches.length ) {
					var sb_empty = sb_document.createElement( 'li' );
					sb_empty.className = 'sb-user-select__empty';
					sb_empty.textContent = 'No matches.';
					sb_dropdown.appendChild( sb_empty );
					return;
				}

				sb_matches.forEach( function ( sb_opt ) {
					var sb_item = sb_document.createElement( 'li' );
					var sb_button = sb_document.createElement( 'button' );

					sb_button.type = 'button';
					sb_button.className = 'sb-user-select__option';
					sb_button.textContent = sb_opt.label;

					// mousedown (not click) fires before the search
					// input's blur, so the selection registers before
					// the dropdown gets a chance to close underneath it.
					sb_button.addEventListener( 'mousedown', function ( sb_event ) {
						sb_event.preventDefault();
						sb_native.value = sb_opt.value;
						sb_native.dispatchEvent( new Event( 'change', { bubbles: true } ) );
						sb_search.value = sb_opt.label;
						sb_clear.classList.remove( 'sb-hidden' );
						sb_dropdown.classList.add( 'sb-hidden' );
					} );

					sb_item.appendChild( sb_button );
					sb_dropdown.appendChild( sb_item );
				} );
			};

			var sb_wipe = function () {
				sb_native.value = '';
				sb_native.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				sb_search.value = '';
				sb_clear.classList.add( 'sb-hidden' );
				sb_dropdown.classList.add( 'sb-hidden' );
			};

			sb_search.addEventListener( 'focus', function () {
				sb_renderDropdown( sb_search.value.trim().toLowerCase() );
				sb_dropdown.classList.remove( 'sb-hidden' );
			} );

			sb_search.addEventListener( 'input', function () {
				sb_renderDropdown( sb_search.value.trim().toLowerCase() );
				sb_dropdown.classList.remove( 'sb-hidden' );
				sb_clear.classList.toggle( 'sb-hidden', '' === sb_search.value.trim() );
			} );

			sb_search.addEventListener( 'blur', function () {
				sb_dropdown.classList.add( 'sb-hidden' );

				// Nothing was actually picked from the dropdown -- snap
				// the visible text back to whatever is really selected,
				// so leftover typed text never looks selected when it isn't.
				if ( sb_search.value !== sb_labelFor( sb_native.value ) ) {
					sb_search.value = sb_labelFor( sb_native.value );
					sb_clear.classList.toggle( 'sb-hidden', '' === sb_native.value );
				}
			} );

			sb_clear.addEventListener( 'mousedown', function ( sb_event ) {
				sb_event.preventDefault();
				sb_wipe();
				sb_search.focus();
			} );

			// Initial state: reflect whatever the server pre-selected
			// (including a value restored from a draft cookie, since
			// sb_initFormDraft() already ran by the time this does).
			sb_search.value = sb_labelFor( sb_native.value );
			sb_clear.classList.toggle( 'sb-hidden', '' === sb_native.value );
		} );
	}

	/**
	 * Wire up one taxonomy picker's search box, if it has one (only a
	 * picker with at least one existing term gets one -- see
	 * AbstractBookFormPage::render_taxonomy_field()): filters the
	 * checklist to items whose term name contains the typed text,
	 * case-insensitively, and shows/hides the small "x" clear button
	 * alongside it. Re-reads the list live on every keystroke, so it
	 * also covers checkboxes appended afterward by the "+ Add new"
	 * control.
	 */
	function sb_initTaxonomySearch( sb_picker, sb_list ) {
		var sb_search = sb_picker.querySelector( '.sb-taxonomy-picker__search' );
		var sb_clear = sb_picker.querySelector( '.sb-taxonomy-picker__search-clear' );

		if ( ! sb_search || ! sb_list ) {
			return;
		}

		var sb_applyFilter = function () {
			var sb_query = sb_search.value.trim().toLowerCase();

			sb_list.querySelectorAll( 'li' ).forEach( function ( sb_item ) {
				var sb_matches = '' === sb_query || -1 !== sb_item.textContent.toLowerCase().indexOf( sb_query );
				sb_item.classList.toggle( 'sb-hidden', ! sb_matches );
			} );

			if ( sb_clear ) {
				sb_clear.classList.toggle( 'sb-hidden', '' === sb_query );
			}
		};

		sb_search.addEventListener( 'input', sb_applyFilter );

		if ( sb_clear ) {
			sb_clear.addEventListener( 'click', function () {
				sb_search.value = '';
				sb_applyFilter();
				sb_search.focus();
			} );
		}
	}

	/**
	 * Wire up each Add Book taxonomy picker's "+ Add new" control: reveal
	 * the inline mini-form, then on "Add" (or Enter in the text field)
	 * either check an already-listed term with the same name or append a
	 * fresh checked checkbox for it. Purely a client-side convenience --
	 * the appended checkbox posts like any other, and an unrecognised
	 * name is created automatically server-side on save (see
	 * AddBookPage::render_taxonomy_field()'s doc comment). Also wires up
	 * the search box (sb_initTaxonomySearch()) that filters the same list.
	 */
	function sb_initTaxonomyPickers( sb_scope ) {
		var sb_pickers = sb_scope.querySelectorAll( '[data-sb-taxonomy-picker]' );

		sb_pickers.forEach( function ( sb_picker ) {
			var sb_toggle = sb_picker.querySelector( '.sb-taxonomy-picker__toggle' );
			var sb_addPanel = sb_picker.querySelector( '.sb-taxonomy-picker__add' );
			var sb_input = sb_picker.querySelector( '.sb-taxonomy-picker__input' );
			var sb_addButton = sb_picker.querySelector( '.sb-taxonomy-picker__add-button' );
			var sb_list = sb_picker.querySelector( '.sb-taxonomy-picker__list' );

			sb_initTaxonomySearch( sb_picker, sb_list );

			if ( ! sb_toggle || ! sb_addPanel || ! sb_input || ! sb_addButton || ! sb_list ) {
				return;
			}

			sb_toggle.addEventListener( 'click', function () {
				sb_addPanel.classList.remove( 'sb-hidden' );
				sb_input.focus();
			} );

			var sb_addTerm = function () {
				var sb_name = sb_input.value.trim();

				if ( '' === sb_name ) {
					return;
				}

				var sb_existing = null;

				sb_list.querySelectorAll( 'input[type="checkbox"]' ).forEach( function ( sb_checkbox ) {
					if ( sb_checkbox.value.toLowerCase() === sb_name.toLowerCase() ) {
						sb_existing = sb_checkbox;
					}
				} );

				if ( sb_existing ) {
					sb_existing.checked = true;
				} else {
					var sb_fieldName = sb_input.getAttribute( 'data-sb-taxonomy-field' );
					var sb_item = sb_document.createElement( 'li' );
					var sb_label = sb_document.createElement( 'label' );
					var sb_checkbox = sb_document.createElement( 'input' );

					sb_checkbox.type = 'checkbox';
					sb_checkbox.name = sb_fieldName + '[]';
					sb_checkbox.value = sb_name;
					sb_checkbox.checked = true;

					sb_label.appendChild( sb_checkbox );
					sb_label.appendChild( sb_document.createTextNode( ' ' + sb_name ) );
					sb_item.appendChild( sb_label );
					sb_list.appendChild( sb_item );
				}

				sb_input.value = '';
				sb_input.focus();
			};

			sb_addButton.addEventListener( 'click', sb_addTerm );

			sb_input.addEventListener( 'keydown', function ( sb_event ) {
				if ( 'Enter' === sb_event.key ) {
					sb_event.preventDefault();
					sb_addTerm();
				}
			} );
		} );
	}

	/**
	 * Read a browser cookie by name, or null if it isn't set.
	 */
	function sb_readCookie( sb_name ) {
		var sb_match = sb_document.cookie.match( new RegExp( '(?:^|; )' + sb_name + '=([^;]*)' ) );

		return sb_match ? decodeURIComponent( sb_match[ 1 ] ) : null;
	}

	/**
	 * Write a browser cookie using the same path WordPress's own cookies
	 * use (sbAdmin.cookiePath), so a PHP-side setcookie() with that same
	 * path (e.g. AddBookPage::clear_draft_cookie()) can actually clear it.
	 */
	function sb_writeCookie( sb_name, sb_value ) {
		var sb_path = ( sb_window.sbAdmin && sb_window.sbAdmin.cookiePath ) || '/';

		sb_document.cookie = sb_name + '=' + encodeURIComponent( sb_value ) + '; path=' + sb_path + '; max-age=86400';
	}

	/**
	 * Every field inside a form that a draft should track: anything with
	 * a "name", except the nonce/action bookkeeping fields and file
	 * inputs (a file input's value can't be read back or restored by
	 * script, for browser security reasons -- the cover image/gallery
	 * pickers instead keep their selection in a hidden attachment-id
	 * field, which this *does* cover).
	 */
	function sb_draftableFields( sb_form ) {
		var sb_fields = Array.prototype.slice.call( sb_form.querySelectorAll( '[name]' ) );

		return sb_fields.filter( function ( sb_field ) {
			return 'file' !== sb_field.type
				&& '_wpnonce' !== sb_field.name
				&& '_wp_http_referer' !== sb_field.name
				&& 'action' !== sb_field.name;
		} );
	}

	/**
	 * Serialize a form's current, draftable field values into a plain
	 * object keyed by field name (array-named fields, e.g. "authors[]",
	 * collapse to their base name with an array of values).
	 */
	function sb_serializeDraft( sb_form ) {
		var sb_data = {};

		sb_draftableFields( sb_form ).forEach( function ( sb_field ) {
			var sb_isArrayField = sb_field.name.slice( -2 ) === '[]';
			var sb_key = sb_isArrayField ? sb_field.name.slice( 0, -2 ) : sb_field.name;

			if ( 'checkbox' === sb_field.type ) {
				if ( sb_isArrayField ) {
					if ( ! Array.isArray( sb_data[ sb_key ] ) ) {
						sb_data[ sb_key ] = [];
					}
					if ( sb_field.checked ) {
						sb_data[ sb_key ].push( sb_field.value );
					}
				} else {
					sb_data[ sb_key ] = sb_field.checked;
				}
				return;
			}

			sb_data[ sb_key ] = sb_field.value;
		} );

		return sb_data;
	}

	/**
	 * Restore a form's field values from a previously serialized draft
	 * (see sb_serializeDraft()). A checked value with no matching
	 * checkbox yet (a taxonomy term that was only ever added client-side
	 * via sb_initTaxonomyPickers(), in a session before this reload)
	 * gets a fresh checkbox appended the same way that picker would.
	 */
	function sb_restoreDraft( sb_form, sb_data ) {
		Object.keys( sb_data ).forEach( function ( sb_key ) {
			var sb_value = sb_data[ sb_key ];

			if ( Array.isArray( sb_value ) ) {
				var sb_checkboxes = sb_form.querySelectorAll( 'input[type="checkbox"][name="' + sb_key + '[]"]' );
				var sb_list = sb_checkboxes.length ? sb_checkboxes[ 0 ].closest( '.sb-taxonomy-picker__list' ) : null;

				sb_value.forEach( function ( sb_name ) {
					var sb_found = false;

					sb_checkboxes.forEach( function ( sb_checkbox ) {
						if ( sb_checkbox.value === sb_name ) {
							sb_checkbox.checked = true;
							sb_found = true;
						}
					} );

					if ( ! sb_found && sb_list ) {
						var sb_item = sb_document.createElement( 'li' );
						var sb_label = sb_document.createElement( 'label' );
						var sb_checkbox = sb_document.createElement( 'input' );

						sb_checkbox.type = 'checkbox';
						sb_checkbox.name = sb_key + '[]';
						sb_checkbox.value = sb_name;
						sb_checkbox.checked = true;

						sb_label.appendChild( sb_checkbox );
						sb_label.appendChild( sb_document.createTextNode( ' ' + sb_name ) );
						sb_item.appendChild( sb_label );
						sb_list.appendChild( sb_item );
					}
				} );

				return;
			}

			var sb_field = sb_form.querySelector( '[name="' + sb_key + '"]' );

			if ( ! sb_field ) {
				return;
			}

			if ( 'checkbox' === sb_field.type ) {
				sb_field.checked = Boolean( sb_value );
			} else {
				sb_field.value = sb_value;
				sb_field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
		} );
	}

	/**
	 * Mirror every "[data-sb-draft-cookie]" form's field values to a
	 * cookie as the user types, and restore them from that cookie on
	 * load. Protects against losing everything typed to an accidental
	 * reload/back-navigation, or to a server-side validation redirect
	 * that bounces back to a blank copy of the same form (e.g.
	 * AddBookPage::handle_save()'s missing-title check) -- the cookie is
	 * only ever cleared server-side, once the save actually succeeds.
	 */
	function sb_initFormDraft( sb_scope ) {
		var sb_forms = sb_scope.querySelectorAll( '[data-sb-draft-cookie]' );

		sb_forms.forEach( function ( sb_form ) {
			var sb_cookieName = sb_form.getAttribute( 'data-sb-draft-cookie' );
			var sb_raw = sb_readCookie( sb_cookieName );

			if ( sb_raw ) {
				try {
					sb_restoreDraft( sb_form, JSON.parse( sb_raw ) );
				} catch ( sb_error ) {
					// Corrupt/foreign cookie value -- ignore it rather than throw.
				}
			}

			sb_form.addEventListener( 'input', function () {
				sb_writeCookie( sb_cookieName, JSON.stringify( sb_serializeDraft( sb_form ) ) );
			} );

			sb_form.addEventListener( 'change', function () {
				sb_writeCookie( sb_cookieName, JSON.stringify( sb_serializeDraft( sb_form ) ) );
			} );
		} );
	}

	/**
	 * Wire up the QR label selection checklist's "Select All" checkbox.
	 */
	function sb_initSelectAll( sb_scope ) {
		var sb_selectAll = sb_scope.getElementById( 'sb-select-all-books' );

		if ( ! sb_selectAll ) {
			return;
		}

		var sb_checkboxes = sb_scope.querySelectorAll( '.sb-label-select-list__checkbox' );

		sb_selectAll.addEventListener( 'change', function () {
			sb_checkboxes.forEach( function ( sb_checkbox ) {
				sb_checkbox.checked = sb_selectAll.checked;
			} );
		} );
	}

	sb_ready( function () {
		sb_initFormDraft( sb_document );
		sb_initNotices( sb_document );
		sb_initAccordions( sb_document );
		sb_initRatingFields( sb_document );
		sb_initProgressFields( sb_document );
		sb_initTextareaAutosize( sb_document );
		sb_initImportConfirm( sb_document );
		sb_initConfirmLinks( sb_document );
		sb_initPrintButtons( sb_document );
		sb_initSelectAll( sb_document );
		sb_initTabs( sb_document );
		sb_initTaxonomyPickers( sb_document );
		sb_initUserSelects( sb_document );

		sb_document.dispatchEvent( new CustomEvent( 'sb:admin:ready', { detail: sb_window.sbAdmin } ) );
	} );
} )( window, document );
