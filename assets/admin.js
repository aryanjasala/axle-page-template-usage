/*
 * Axle Page Template Usage admin script.
 *
 * 1. A form with data-aptu-confirm asks first, in place: a box under the form says what
 *    will change and whether it can be undone. {to} in the message becomes the chosen
 *    template. Without this script the form just sends.
 * 2. Notices with class aptu-live are read out by screen readers.
 */
( function () {
	var counter = 0;

	function closeConfirm( form, focusForm ) {
		var box = form.nextElementSibling;
		if ( box && box.classList.contains( 'axle-confirm' ) ) {
			box.parentNode.removeChild( box );
		}
		if ( focusForm ) {
			var first = form.querySelector( 'button[type="submit"]' );
			if ( first ) {
				first.focus();
			}
		}
	}

	function openConfirm( form ) {
		var message = form.getAttribute( 'data-aptu-confirm' );
		var select = form.querySelector( 'select[name="to"]' );
		if ( select && select.selectedOptions && select.selectedOptions[ 0 ] ) {
			message = message.replace( '{to}', select.selectedOptions[ 0 ].textContent );
		}

		var id = 'aptu-confirm-' + ( ++counter );
		var box = document.createElement( 'div' );
		box.className = 'axle-confirm axle-confirm--danger aptu-confirm';
		box.setAttribute( 'role', 'group' );
		box.setAttribute( 'aria-labelledby', id );

		var text = document.createElement( 'p' );
		text.id = id;
		text.textContent = message;

		var actions = document.createElement( 'div' );
		actions.className = 'axle-actions';

		var go = document.createElement( 'button' );
		go.type = 'button';
		go.className = 'button button-primary';
		go.textContent = form.getAttribute( 'data-aptu-confirm-label' ) || 'Continue';

		var cancel = document.createElement( 'button' );
		cancel.type = 'button';
		cancel.className = 'button';
		cancel.textContent = form.getAttribute( 'data-aptu-cancel-label' ) || 'Cancel';

		actions.appendChild( go );
		actions.appendChild( cancel );
		box.appendChild( text );
		box.appendChild( actions );
		form.parentNode.insertBefore( box, form.nextSibling );

		go.addEventListener( 'click', function () {
			form.setAttribute( 'data-aptu-confirmed', '1' );
			form.submit();
		} );
		cancel.addEventListener( 'click', function () {
			closeConfirm( form, true );
		} );
		box.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				closeConfirm( form, true );
			}
		} );

		go.focus();
		if ( window.wp && window.wp.a11y ) {
			window.wp.a11y.speak( message, 'assertive' );
		}
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;

		if ( ! form || ! form.getAttribute || ! form.getAttribute( 'data-aptu-confirm' ) || form.getAttribute( 'data-aptu-confirmed' ) ) {
			return;
		}

		event.preventDefault();
		closeConfirm( form, false );
		openConfirm( form );
	} );

	document.addEventListener( 'DOMContentLoaded', function () {
		var notice = document.querySelector( '.aptu-live' );
		if ( notice && window.wp && window.wp.a11y ) {
			window.wp.a11y.speak( notice.textContent.replace( /\s+/g, ' ' ).trim(), 'polite' );
		}
	} );
}() );
