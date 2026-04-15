/**
 * DSN Powerall — Stock Display Modal
 * Vanilla JS, no dependencies.
 */
( function () {
    'use strict';

    function openModal( modal ) {
        modal.removeAttribute( 'hidden' );
        document.body.style.overflow = 'hidden';

        // Move focus to the close button for accessibility
        var closeBtn = modal.querySelector( '.dsn-stock-modal__close' );
        if ( closeBtn ) closeBtn.focus();
    }

    function closeModal( modal ) {
        modal.setAttribute( 'hidden', '' );
        document.body.style.overflow = '';
    }

    document.addEventListener( 'click', function ( e ) {
        // Prevent default on the "View more" link so the hash doesn't land in the URL
        var trigger = e.target.closest( '.dsn-stock__view-more' );
        if ( trigger ) {
            e.preventDefault();
            var modalId = trigger.getAttribute( 'data-modal' );
            var modal   = modalId ? document.getElementById( modalId ) : null;
            if ( modal ) openModal( modal );
            return;
        }

        // Close via × button
        var closeBtn = e.target.closest( '.dsn-stock-modal__close' );
        if ( closeBtn ) {
            closeModal( closeBtn.closest( '.dsn-stock-modal' ) );
            return;
        }

        // Close by clicking the backdrop
        if ( e.target.classList.contains( 'dsn-stock-modal__backdrop' ) ) {
            closeModal( e.target.closest( '.dsn-stock-modal' ) );
        }
    } );

    // Close on Escape
    document.addEventListener( 'keydown', function ( e ) {
        if ( e.key !== 'Escape' ) return;
        document.querySelectorAll( '.dsn-stock-modal:not([hidden])' ).forEach( closeModal );
    } );
}() );
