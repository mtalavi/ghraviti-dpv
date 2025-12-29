/**
 * ============================================================
 *  DPV Hub - Unified JavaScript UI Components
 *  Version: 2.0.0
 * ============================================================
 * 
 *  Provides modular, reusable JavaScript functionality for:
 *  - Mobile card expansion
 *  - Modal dialogs
 *  - Permission chip toggles
 *  - Copy to clipboard
 *  - Alert auto-dismiss
 *  - Form validation helpers
 *  - Loading states
 */

(function () {
    'use strict';

    // Wait for DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDPVUI);
    } else {
        initDPVUI();
    }

    function initDPVUI() {
        // Initialize all components
        initCardExpand();
        initModals();
        initPermissionChips();
        initCopyButtons();
        initAlertAutoDismiss();
        initFormLoadingStates();
        initSearchEnhancements();
        initConfirmDialogs();
    }

    /**
     * Expandable Data Cards (Mobile)
     */
    function initCardExpand() {
        // Toggle card expansion on header click
        document.querySelectorAll('.dpv-data-card__header').forEach(function (header) {
            header.addEventListener('click', function () {
                var card = this.closest('.dpv-data-card');
                if (card) {
                    card.classList.toggle('expanded');
                }
            });
        });

        // Legacy support for old class names
        document.querySelectorAll('[data-card-toggle]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                var targetId = this.getAttribute('data-card-toggle');
                var card = document.getElementById(targetId) || this.closest('.admin-card, .dpv-data-card');
                if (card) {
                    card.classList.toggle('expanded');
                }
            });
        });
    }

    /**
     * Modal Dialogs
     */
    function initModals() {
        // Open modal buttons
        document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var modalId = this.getAttribute('data-modal-open');
                openModal(modalId);
            });
        });

        // Close modal buttons
        document.querySelectorAll('.dpv-modal__close, [data-modal-close]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var modal = this.closest('.dpv-modal, .admin-modal');
                if (modal) {
                    closeModal(modal.id);
                }
            });
        });

        // Close modal on backdrop click
        document.querySelectorAll('.dpv-modal, .admin-modal').forEach(function (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === this) {
                    closeModal(this.id);
                }
            });
        });

        // Close modal on Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.dpv-modal.active, .admin-modal.active').forEach(function (modal) {
                    closeModal(modal.id);
                });
            }
        });
    }

    /**
     * Open a modal by ID
     */
    window.openModal = function (id) {
        var modal = document.getElementById(id);
        if (modal) {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            // Focus first focusable element
            var focusable = modal.querySelector('input, button, select, textarea, a[href]');
            if (focusable) {
                setTimeout(function () { focusable.focus(); }, 100);
            }
        }
    };

    /**
     * Close a modal by ID
     */
    window.closeModal = function (id) {
        var modal = document.getElementById(id);
        if (modal) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    };

    /**
     * Permission Chip Toggles
     */
    function initPermissionChips() {
        document.querySelectorAll('.dpv-perm-chip, .perm-chip').forEach(function (chip) {
            var checkbox = chip.querySelector('input[type="checkbox"]');
            if (!checkbox) return;

            // Listen for checkbox change (handles both direct clicks and label clicks)
            checkbox.addEventListener('change', function () {
                chip.classList.toggle('active', checkbox.checked);
                chip.classList.toggle('perm-active', checkbox.checked);
            });

            // Initialize active state
            chip.classList.toggle('active', checkbox.checked);
            chip.classList.toggle('perm-active', checkbox.checked);
        });
    }

    /**
     * Copy to Clipboard Buttons
     */
    function initCopyButtons() {
        document.querySelectorAll('[data-copy]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var text = this.getAttribute('data-copy');
                if (!text) return;

                var originalText = this.textContent;
                var button = this;

                navigator.clipboard.writeText(text).then(function () {
                    button.textContent = 'Copied ✓';
                    button.classList.add('dpv-btn--success');
                    setTimeout(function () {
                        button.textContent = originalText;
                        button.classList.remove('dpv-btn--success');
                    }, 1500);
                }).catch(function (err) {
                    console.error('Copy failed:', err);
                    // Fallback for older browsers
                    var textarea = document.createElement('textarea');
                    textarea.value = text;
                    document.body.appendChild(textarea);
                    textarea.select();
                    try {
                        document.execCommand('copy');
                        button.textContent = 'Copied ✓';
                        setTimeout(function () {
                            button.textContent = originalText;
                        }, 1500);
                    } catch (e) {
                        button.textContent = 'Copy failed';
                        setTimeout(function () {
                            button.textContent = originalText;
                        }, 1500);
                    }
                    document.body.removeChild(textarea);
                });
            });
        });
    }

    /**
     * Auto-dismiss alerts after timeout
     */
    function initAlertAutoDismiss() {
        document.querySelectorAll('.dpv-alert[data-auto-dismiss]').forEach(function (alert) {
            var delay = parseInt(alert.getAttribute('data-auto-dismiss'), 10) || 5000;
            setTimeout(function () {
                alert.style.transition = 'opacity 0.3s, transform 0.3s';
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(function () {
                    alert.remove();
                }, 300);
            }, delay);
        });
    }

    /**
     * Form Loading States
     */
    function initFormLoadingStates() {
        document.querySelectorAll('form[data-loading]').forEach(function (form) {
            form.addEventListener('submit', function () {
                var btn = this.querySelector('button[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                    btn.classList.add('dpv-btn--loading');
                    btn.dataset.originalText = btn.textContent;
                    btn.innerHTML = '<span class="dpv-spinner"></span> Processing...';
                }
                showLoadingOverlay();
            });
        });

        // Auto-attach to forms with submit buttons
        document.querySelectorAll('form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                // Only add loading if not prevented
                if (!e.defaultPrevented) {
                    var btn = this.querySelector('button[type="submit"]:not(.no-loading)');
                    if (btn && !btn.disabled) {
                        btn.classList.add('btn-loading');
                    }
                }
            });
        });
    }

    /**
     * Show loading overlay
     */
    window.showLoadingOverlay = function (message) {
        message = message || 'Processing...';
        if (document.getElementById('dpvLoadingOverlay')) return;

        var overlay = document.createElement('div');
        overlay.id = 'dpvLoadingOverlay';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(255,255,255,0.8);backdrop-filter:blur(4px);display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:99999;';
        overlay.innerHTML = '<div class="spinner-lite"><div></div><div></div><div></div><div></div></div><div style="margin-top:16px;font-weight:700;color:#0f172a;">' + message + '</div>';
        document.body.appendChild(overlay);
    };

    /**
     * Hide loading overlay
     */
    window.hideLoadingOverlay = function () {
        var overlay = document.getElementById('dpvLoadingOverlay');
        if (overlay) {
            overlay.remove();
        }
    };

    /**
     * Search enhancements
     */
    function initSearchEnhancements() {
        // Auto-submit on Enter in search inputs
        document.querySelectorAll('.dpv-search__input').forEach(function (input) {
            input.addEventListener('keypress', function (e) {
                if (e.key === 'Enter') {
                    var form = this.closest('form');
                    if (form) {
                        form.submit();
                    }
                }
            });
        });
    }

    /**
     * Confirm dialog for dangerous actions
     */
    function initConfirmDialogs() {
        document.querySelectorAll('[data-confirm]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                var message = this.getAttribute('data-confirm');
                if (!confirm(message)) {
                    e.preventDefault();
                    return false;
                }
            });
        });
    }

    /**
     * Toggle card expansion (global helper for onclick attributes)
     */
    window.toggleCard = function (card) {
        if (typeof card === 'string') {
            card = document.getElementById(card);
        }
        if (card) {
            card.classList.toggle('expanded');
        }
    };

    /**
     * Toast notification
     */
    window.dpvToast = function (message, type, duration) {
        type = type || 'info';
        duration = duration || 3000;

        var toast = document.createElement('div');
        toast.className = 'dpv-toast dpv-toast--' + type;
        toast.textContent = message;
        toast.style.cssText = 'position:fixed;bottom:20px;right:20px;padding:14px 24px;border-radius:12px;font-weight:600;z-index:99999;animation:dpvSlideUp 0.3s ease;';

        var colors = {
            success: 'background:#10b981;color:white;',
            error: 'background:#ef4444;color:white;',
            warning: 'background:#f59e0b;color:white;',
            info: 'background:#3b82f6;color:white;'
        };
        toast.style.cssText += colors[type] || colors.info;

        document.body.appendChild(toast);

        setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(function () {
                toast.remove();
            }, 300);
        }, duration);
    };

    /**
     * Choice button groups (gender, emirate selection)
     */
    window.wireChoiceGroup = function (groupName, hiddenId) {
        var buttons = document.querySelectorAll('[data-choice="' + groupName + '"]');
        var hidden = document.getElementById(hiddenId);

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                buttons.forEach(function (b) { b.classList.remove('is-active'); });
                btn.classList.add('is-active');
                if (hidden) {
                    hidden.value = btn.getAttribute('data-value') || '';
                }
            });
        });
    };

    /**
     * Auto-advance for Emirates ID inputs
     */
    window.initEidAutoAdvance = function () {
        var eidGroup = document.querySelector('[data-eid-group]');
        if (!eidGroup) return;

        var fields = Array.from(eidGroup.querySelectorAll('input'));
        fields.forEach(function (field, idx) {
            field.addEventListener('input', function () {
                // Force numeric only
                this.value = this.value.replace(/\D/g, '');

                var max = parseInt(field.getAttribute('maxlength') || '0', 10);
                if (max && field.value.length >= max) {
                    var next = fields[idx + 1];
                    if (next) next.focus();
                }
            });
        });
    };

    /**
     * V-number auto-format (6 digits)
     */
    window.initVNumberInput = function () {
        var input = document.querySelector('input[name="v_number"]');
        if (!input) return;

        input.addEventListener('input', function () {
            var digits = (this.value || '').replace(/\D+/g, '').slice(0, 6);
            this.value = digits;

            if (digits.length >= 6) {
                // Auto-advance to next field
                var focusables = Array.from(document.querySelectorAll('input,select,textarea,button'))
                    .filter(function (el) { return !el.disabled && el.type !== 'hidden'; });
                var idx = focusables.indexOf(input);
                if (idx >= 0 && focusables[idx + 1]) {
                    focusables[idx + 1].focus();
                }
            }
        });
    };

    // Auto-initialize common form enhancements
    document.addEventListener('DOMContentLoaded', function () {
        initEidAutoAdvance();
        initVNumberInput();

        // Wire up common choice groups if they exist
        if (document.getElementById('genderChoice')) {
            wireChoiceGroup('gender', 'genderChoice');
        }
        if (document.getElementById('emirateChoice')) {
            wireChoiceGroup('emirate', 'emirateChoice');
        }
    });

})();
