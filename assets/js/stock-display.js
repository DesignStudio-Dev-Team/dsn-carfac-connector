/**
 * DSN Powerall — Stock Display Modal
 * No dependencies (no jQuery required).
 */
(function () {
    'use strict';

    function openModal(modal) {
        modal.removeAttribute('hidden');
        modal.focus();
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        modal.setAttribute('hidden', '');
        document.body.style.overflow = '';
    }

    document.addEventListener('click', function (e) {
        // Open modal via "Show more" button
        var trigger = e.target.closest('.dsn-stock__show-more');
        if (trigger) {
            var modalId = trigger.getAttribute('data-modal');
            var modal   = modalId ? document.getElementById(modalId) : null;
            if (modal) {
                openModal(modal);
            }
            return;
        }

        // Close via × button
        if (e.target.closest('.dsn-stock-modal__close')) {
            var modal = e.target.closest('.dsn-stock-modal');
            if (modal) closeModal(modal);
            return;
        }

        // Close by clicking the backdrop
        if (e.target.classList.contains('dsn-stock-modal__backdrop')) {
            closeModal(e.target.closest('.dsn-stock-modal'));
        }
    });

    // Close on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var openModals = document.querySelectorAll('.dsn-stock-modal:not([hidden])');
        openModals.forEach(closeModal);
    });
}());
