/* ==========================================================================
   ADMIN PANEL — DESIGN SYSTEM JS
   --------------------------------------------------------------------------
   Runtime helpers for the .btn-app + .modal-app components defined in
   tools/css/style.css. Plain JS, no jQuery dependency. Loaded once from
   include/footer.php just before </body>.
   ========================================================================== */
(function () {
    'use strict';

    /* ----------------------------------------------------------------
       1. Auto button-spinner on form submit
       Any <form> whose submit button is a .btn-app gets .is-loading on
       submit, preventing double-submits and giving immediate feedback.
       ---------------------------------------------------------------- */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.nodeName !== 'FORM') return;
        // Don't lock buttons if the form has explicitly opted out
        if (form.hasAttribute('data-no-loading')) return;

        var btn = form.querySelector('button[type="submit"].btn-app, .btn-app[type="submit"]');
        if (btn && !btn.classList.contains('is-loading')) {
            AppBtn.start(btn);
            // Safety net — release after 15s in case the form never navigates
            setTimeout(function () { AppBtn.stop(btn); }, 15000);
        }
    });

    /* ----------------------------------------------------------------
       2. Public spinner API for async actions (fetch, AJAX, etc).
       Usage:  AppBtn.start(btn);  // ...  AppBtn.stop(btn);
       ---------------------------------------------------------------- */
    var AppBtn = {
        start: function (btn) {
            if (!btn) return;
            btn.classList.add('is-loading');
            btn.setAttribute('aria-busy', 'true');
            // Lock interaction without using disabled (so form submits still work
            // when called from inside the submit handler above)
            btn.dataset._wasDisabled = btn.disabled ? '1' : '0';
            btn.disabled = true;
        },
        stop: function (btn) {
            if (!btn) return;
            btn.classList.remove('is-loading');
            btn.removeAttribute('aria-busy');
            btn.disabled = btn.dataset._wasDisabled === '1';
        }
    };
    window.AppBtn = AppBtn;

    /* ----------------------------------------------------------------
       3. Modal open/close via data attributes
       Opener:   <button data-app-modal-open="modalId">…</button>
       Closer:   <button data-app-modal-close>…</button>
       Backdrop click + Escape key both close.
       ---------------------------------------------------------------- */
    function openModal(id) {
        var modal = document.getElementById(id);
        if (modal) {
            modal.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            // focus first focusable
            var focusable = modal.querySelector('input, select, textarea, button:not([disabled]), a[href]');
            if (focusable) setTimeout(function () { focusable.focus(); }, 100);
        }
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('is-open');
        // re-enable scroll only if no other open modal remains
        if (!document.querySelector('.modal-app__backdrop.is-open')) {
            document.body.style.overflow = '';
        }
    }

    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-app-modal-open]');
        if (opener) {
            e.preventDefault();
            openModal(opener.getAttribute('data-app-modal-open'));
            return;
        }

        var closer = e.target.closest('[data-app-modal-close]');
        if (closer) {
            e.preventDefault();
            closeModal(closer.closest('.modal-app__backdrop'));
            return;
        }

        // Click on the backdrop (not its children) closes
        if (e.target.classList && e.target.classList.contains('modal-app__backdrop')) {
            closeModal(e.target);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var open = document.querySelectorAll('.modal-app__backdrop.is-open');
        if (open.length) {
            closeModal(open[open.length - 1]); // close topmost
        }
    });

    // Public API for programmatic control
    window.AppModal = { open: openModal, close: closeModal };
})();
