/* Asks before a form that has data-aptu-confirm is sent. */
document.addEventListener( 'submit', function ( event ) {
	var form = event.target;
	var message = form && form.getAttribute ? form.getAttribute( 'data-aptu-confirm' ) : null;

	if ( message && ! window.confirm( message ) ) {
		event.preventDefault();
	}
} );
