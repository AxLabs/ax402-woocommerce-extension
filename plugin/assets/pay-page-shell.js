( function () {
	'use strict';

	function onReady( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	function initInfoTooltip() {
		const root = document.querySelector( '[data-ax402-info]' );
		if ( ! root ) {
			return;
		}
		const btn = root.querySelector( '.ax402-info-btn' );
		if ( ! btn ) {
			return;
		}
		btn.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			const open = root.classList.toggle( 'is-open' );
			btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );
		document.addEventListener( 'click', function ( event ) {
			if ( ! root.contains( event.target ) ) {
				root.classList.remove( 'is-open' );
				btn.setAttribute( 'aria-expanded', 'false' );
			}
		} );
		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				root.classList.remove( 'is-open' );
				btn.setAttribute( 'aria-expanded', 'false' );
			}
		} );
	}

	onReady( initInfoTooltip );
} )();
