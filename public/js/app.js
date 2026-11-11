/**
 * Vanilla JavaScript Utilities for SIA PT Mayora Indah Tbk
 * Provides Modal dialogs, Confirm handlers, Double-Submit prevention, and Unsaved Changes checks.
 */

(function () {
    'use strict';

    // 1. Modal Management
    window.openModal = function (modalId) {
        if (!modalId) return;
        var id = typeof modalId === 'string' ? modalId.replace(/^#/, '') : (modalId.id || '');
        var el = typeof modalId === 'string' ? document.getElementById(id) : modalId;
        if (el) {
            el.classList.add('active');
            el.classList.add('show');
            el.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            var firstInput = el.querySelector('input:not([type=hidden]):not([readonly]):not([disabled]), textarea:not([readonly]):not([disabled]), select:not([disabled]), button.btn-primary');
            if (firstInput) {
                setTimeout(function () { firstInput.focus(); }, 100);
            }
        }
    };

    window.closeModal = function (modalId) {
        var el = typeof modalId === 'string' ? document.getElementById(modalId.replace(/^#/, '')) : modalId;
        if (el) {
            el.classList.remove('active');
            el.classList.remove('show');
            el.style.display = 'none';
            // Check if any other modal is still open
            var remaining = document.querySelectorAll('.modal-backdrop.active, .modal-backdrop.show, .modal.active, .modal.show');
            var stillOpen = false;
            for (var i = 0; i < remaining.length; i++) {
                if (remaining[i] !== el && remaining[i].style.display !== 'none') {
                    stillOpen = true;
                    break;
                }
            }
            if (!stillOpen) {
                document.body.style.overflow = '';
            }
        }
    };

    // Global click delegate for modal buttons & backdrop
    document.addEventListener('click', function (e) {
        // Open trigger via data-modal-target or data-target
        var openBtn = e.target.closest('[data-modal-target], [data-target]');
        if (openBtn) {
            var targetId = openBtn.getAttribute('data-modal-target') || openBtn.getAttribute('data-target');
            if (targetId && (targetId.indexOf('modal') !== -1 || document.getElementById(targetId.replace(/^#/, '')))) {
                e.preventDefault();
                openModal(targetId.replace(/^#/, ''));
                return;
            }
        }

        // Close trigger
        var closeBtn = e.target.closest('[data-modal-close], .modal-close, [data-dismiss="modal"]');
        if (closeBtn) {
            var modal = closeBtn.closest('.modal-backdrop, .modal');
            if (modal) {
                e.preventDefault();
                closeModal(modal);
                return;
            }
        }

        // Click on dark backdrop itself (outside dialog)
        if (e.target.classList.contains('modal-backdrop') || (e.target.classList.contains('modal') && e.target.querySelector('.modal-dialog, .modal-content'))) {
            if (e.target === e.currentTarget || !e.target.closest('.modal-dialog, .modal-content')) {
                closeModal(e.target);
            }
        }
    });

    // Close on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
            var activeModals = document.querySelectorAll('.modal-backdrop.active, .modal-backdrop.show, .modal.active, .modal.show');
            activeModals.forEach(function (m) {
                if (m.style.display !== 'none') {
                    closeModal(m);
                }
            });
        }
    });

    // 2. Prevent Double Submit on POST forms
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.method || form.method.toUpperCase() !== 'POST') {
            return;
        }

        if (form.getAttribute('data-submitted') === 'true') {
            e.preventDefault();
            return false;
        }

        // Disable submit button after small delay so button values are sent
        var submitButtons = form.querySelectorAll('button[type=submit], input[type=submit]');
        submitButtons.forEach(function (btn) {
            if (!btn.disabled) {
                btn.setAttribute('data-original-text', btn.innerHTML || btn.value);
                setTimeout(function () {
                    btn.disabled = true;
                    if (btn.tagName === 'BUTTON') {
                        btn.innerHTML = '⏳ Memproses...';
                    }
                }, 20);
            }
        });
        form.setAttribute('data-submitted', 'true');
    });

    // 3. Confirm Delete / Cancel / Reversal Dialog Helper
    window.confirmAction = function (message, formOrUrl) {
        if (confirm(message)) {
            if (typeof formOrUrl === 'string') {
                window.location.href = formOrUrl;
            } else if (formOrUrl && formOrUrl.submit) {
                formOrUrl.submit();
            }
            return true;
        }
        return false;
    };

    // 4. Unsaved form changes warning (Only for critical POST data-entry forms)
    var isFormDirty = false;
    document.addEventListener('input', function (e) {
        var form = e.target.closest('form');
        if (form && form.method && form.method.toUpperCase() === 'POST') {
            if (!form.classList.contains('filter-bar') && !form.classList.contains('form-filter') && !form.hasAttribute('data-no-dirty')) {
                isFormDirty = true;
            }
        }
    });
    document.addEventListener('submit', function () {
        isFormDirty = false;
    });
    // Allow cancel buttons / links to bypass dirty check
    document.addEventListener('click', function (e) {
        if (e.target.closest('a, button[type="button"], .btn-secondary, [data-modal-close], .btn-cancel')) {
            isFormDirty = false;
        }
    });
    window.addEventListener('beforeunload', function (e) {
        if (isFormDirty) {
            var msg = 'Perubahan data yang Anda masukkan belum disimpan. Yakin ingin meninggalkan halaman?';
            e.returnValue = msg;
            return msg;
        }
    });

})();
