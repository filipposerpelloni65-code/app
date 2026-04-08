/* ============================================
   HelpDesk - Main JavaScript
   ============================================ */

// Shared ticket status labels (used in multiple places)
const STATUS_LABELS = {
    'open':        'Aperto',
    'in_progress': 'In Lavorazione',
    'waiting':     'In Attesa',
    'resolved':    'Risolto',
    'closed':      'Chiuso'
};

$(document).ready(function () {

    // Sidebar toggle
    $('#sidebarToggle').on('click', function () {
        if ($(window).width() <= 768) {
            $('#sidebar').toggleClass('show');
        } else {
            $('body').toggleClass('sidebar-collapsed');
            localStorage.setItem('sidebarCollapsed', $('body').hasClass('sidebar-collapsed'));
        }
    });

    // Restore sidebar state
    if (localStorage.getItem('sidebarCollapsed') === 'true' && $(window).width() > 768) {
        $('body').addClass('sidebar-collapsed');
    }

    // Auto-dismiss alerts after 5 seconds
    setTimeout(function () {
        $('.alert-dismissible.auto-dismiss').fadeOut('slow', function () {
            $(this).remove();
        });
    }, 5000);

    // ============================================================
    // GLOBAL CONFIRMATION MODAL
    // Replaces all native confirm() dialogs
    // ============================================================

    /**
     * Show the global confirmation modal.
     * @param {string}   message   - Body text
     * @param {function} onConfirm - Callback executed when user confirms (null for alert-only)
     * @param {object}   options   - { title, btnClass, btnText, hideOk }
     */
    window.showConfirmModal = function (message, onConfirm, options) {
        options = options || {};
        var title    = options.title    || 'Conferma';
        var btnClass = options.btnClass || 'btn-danger';
        var btnText  = options.btnText  || 'Conferma';
        var hideOk   = options.hideOk  || false;

        $('#confirmModalTitle').text(title);
        $('#confirmModalBody').text(message);

        var $okBtn = $('#confirmModalOk');
        if (hideOk) {
            $okBtn.addClass('d-none');
        } else {
            $okBtn.removeClass('d-none');
            $okBtn
                .attr('class', 'btn btn-sm ' + btnClass)
                .off('click.modalConfirm')
                .on('click.modalConfirm', function () {
                    var modalEl = document.getElementById('confirmModal');
                    var bsModal = bootstrap.Modal.getInstance(modalEl);
                    if (bsModal) bsModal.hide();
                    if (typeof onConfirm === 'function') onConfirm();
                });
        }
        $('#confirmModalOkText').text(btnText);

        var modalEl = document.getElementById('confirmModal');
        if (modalEl) {
            (bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl)).show();
        }
    };

    // Intercept clicks on any element with [data-confirm]
    $(document).on('click', '[data-confirm]', function (e) {
        // If already confirmed by our callback, let it through
        if ($(this).data('modal-confirmed')) {
            $(this).removeData('modal-confirmed');
            return;
        }
        e.preventDefault();
        e.stopPropagation();

        var $el     = $(this);
        var msg      = $el.data('confirm')       || 'Sei sicuro di voler procedere?';
        var btnClass = $el.data('confirm-class') || 'btn-danger';
        var btnText  = $el.data('confirm-text')  || 'Conferma';
        var title    = $el.data('confirm-title') || 'Conferma';

        showConfirmModal(msg, function () {
            if ($el.is('a')) {
                window.location.href = $el.attr('href');
            } else {
                // Submit button inside a form
                $el.data('modal-confirmed', true);
                $el[0].click();
            }
        }, { title: title, btnClass: btnClass, btnText: btnText });
    });

    // Status change form — show confirmation modal
    $(document).on('submit', '#statusUpdateForm', function (e) {
        e.preventDefault();
        var $form     = $(this);
        var newStatus = $form.find('[name="new_status"]').val();
        var label     = STATUS_LABELS[newStatus] || newStatus;

        showConfirmModal(
            'Confermi il cambio di stato a: ' + label + '?',
            function () { $form.off('submit').submit(); },
            { btnClass: 'btn-primary', btnText: 'Conferma', title: 'Cambia Stato' }
        );
    });

    // ============================================================
    // TICKET QUICK STATUS CHANGE MODAL (tickets/index.php)
    // ============================================================
    $(document).on('click', '.ticket-quick-status', function () {
        var ticketId      = $(this).data('ticket-id');
        var currentStatus = $(this).data('current-status');
        var prefix        = window.ticketPrefix || 'TKT';
        var pad           = String(ticketId);
        while (pad.length < 4) pad = '0' + pad;

        $('#statusModalTicketCode').text(prefix + '-' + pad);
        $('#statusModalSelect').val(currentStatus);

        var form = document.getElementById('quickStatusForm');
        if (form) {
            form.action = window.appUrl + '/modules/tickets/view.php?id=' + ticketId;
        }

        var modalEl = document.getElementById('ticketStatusModal');
        if (modalEl) {
            (bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl)).show();
        }
    });

    // Confirm quick status change via modal
    $(document).on('submit', '#quickStatusForm', function (e) {
        e.preventDefault();
        var $form     = $(this);
        var newStatus = $form.find('#statusModalSelect').val();
        var label     = STATUS_LABELS[newStatus] || newStatus;

        // Close the status modal first, then show confirm
        var statusModalEl = document.getElementById('ticketStatusModal');
        var statusModal   = statusModalEl ? bootstrap.Modal.getInstance(statusModalEl) : null;
        if (statusModal) statusModal.hide();

        showConfirmModal(
            'Confermi il cambio di stato a: ' + label + '?',
            function () { $form.off('submit').submit(); },
            { btnClass: 'btn-info', btnText: 'Conferma', title: 'Cambia Stato' }
        );
    });

    // ============================================================
    // PARTS REQUEST MODAL pre-fill (spare_parts/index.php)
    // ============================================================
    $(document).on('click', '.part-request-modal-btn', function () {
        var partId = $(this).data('part-id');
        var $select = $('#modalPartSelect');
        if ($select.length) {
            $select.val(partId);
        }
        var $qty = $('#modalPartQty');
        if ($qty.length) {
            $qty.val(1);
        }
    });

    // ============================================================
    // TOOLTIP INITIALIZATION
    // ============================================================
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Table row click - navigate to href
    $(document).on('click', 'tr[data-href]', function () {
        window.location.href = $(this).data('href');
    });

    // Filter form auto-submit on select change
    $('.filter-auto-submit select, .filter-auto-submit input[type="checkbox"]').on('change', function () {
        $(this).closest('form').submit();
    });

    // Part request quantity validation
    $('input[name="quantity"]').on('input', function () {
        var val = parseInt($(this).val());
        var max = parseInt($(this).attr('max'));
        if (val < 1) $(this).val(1);
        if (max && val > max) $(this).val(max);
    });

    // Character counter for textareas
    $('textarea[maxlength]').each(function () {
        var max = $(this).attr('maxlength');
        var counter = $('<small class="text-muted char-counter"></small>');
        $(this).after(counter);
        var update = function () {
            var remaining = max - $(this).val().length;
            counter.text(remaining + ' caratteri rimanenti');
            counter.toggleClass('text-danger', remaining < 50);
        }.bind(this);
        $(this).on('input', update);
        update();
    });

    // Module toggle via AJAX in settings
    $(document).on('change', '.module-toggle', function () {
        var slug = $(this).data('slug');
        var enabled = $(this).is(':checked') ? 1 : 0;
        var csrfVal = $('input[name="csrf_token"]').first().val();
        $.post(window.appUrl + '/modules/settings/index.php', {
            action:     'toggle_module',
            slug:       slug,
            enabled:    enabled,
            csrf_token: csrfVal
        }).done(function (resp) {
            var data = typeof resp === 'string' ? JSON.parse(resp) : resp;
            if (!data.success) {
                showConfirmModal('Errore durante il salvataggio.', null, { title: 'Errore', hideOk: true });
            }
        }).fail(function () {
            showConfirmModal('Errore di comunicazione con il server.', null, { title: 'Errore', hideOk: true });
        });
    });

    // Install wizard step navigation
    var currentStep = 1;
    var totalSteps  = 5;

    window.goToStep = function (step) {
        if (step < 1 || step > totalSteps) return;
        $('.wizard-step').removeClass('active');
        $('#step-' + step).addClass('active');
        $('.step-indicator .step').each(function (i) {
            var n = i + 1;
            $(this).removeClass('active completed');
            if (n < step) $(this).addClass('completed');
            if (n === step) $(this).addClass('active');
        });
        currentStep = step;
    };

    // Smooth scroll to top on page load errors
    if ($('.alert-danger').length) {
        $('html, body').animate({ scrollTop: 0 }, 400);
    }

    // Global search
    var searchTimer;
    $('#globalSearchInput').on('input', function () {
        clearTimeout(searchTimer);
        var q = $(this).val().trim();
        var $results = $('#searchResults');
        if (q.length < 2) {
            $results.hide();
            return;
        }
        searchTimer = setTimeout(function () {
            $.getJSON(window.appUrl + '/api/search.php', { q: q }, function (data) {
                $results.empty();
                if (!data.success || !data.results || !data.results.length) {
                    $results.append('<div class="p-3 text-muted small">Nessun risultato trovato.</div>');
                } else {
                    $.each(data.results, function (i, r) {
                        var item = $('<a class="dropdown-item py-2 px-3 d-flex align-items-start gap-2" href="' + r.url + '"></a>');
                        item.append('<i class="bi ' + r.icon + ' mt-1 text-primary flex-shrink-0"></i>');
                        var info = $('<div class="flex-grow-1 overflow-hidden"></div>');
                        info.append('<div class="small fw-semibold text-truncate">' + (r.code ? '<span class="font-monospace text-secondary">' + r.code + '</span> — ' : '') + r.label + '</div>');
                        info.append('<div class="x-small text-muted">' + r.type + (r.meta ? ' &middot; ' + r.meta : '') + '</div>');
                        item.append(info);
                        $results.append(item);
                    });
                }
                $results.show();
            });
        }, 300);
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('#globalSearchForm').length) {
            $('#searchResults').hide();
        }
    });
});

// App URL global (set in PHP pages)
window.appUrl = window.appUrl || '';

/* ============================================================
   Notification Centre — real-time polling + toast + dropdown
   ============================================================ */
(function () {
    'use strict';

    var appUrl       = document.querySelector('meta[name="app-url"]') ? document.querySelector('meta[name="app-url"]').content : window.appUrl;
    var csrfMeta     = document.querySelector('meta[name="csrf-token"]');
    var csrfToken    = csrfMeta ? csrfMeta.content : '';
    var apiUrl       = appUrl + '/api/notifications.php';
    var pollInterval = 30000;   // 30 s
    var lastMaxId    = 0;
    var isLoggedIn   = !!document.getElementById('notif-dropdown');

    if (!isLoggedIn) return;

    /* ── Type meta ─────────────────────────────────────────── */
    var typeMeta = {
        info:    { color: 'info',      icon: 'bi-info-circle-fill' },
        success: { color: 'success',   icon: 'bi-check-circle-fill' },
        warning: { color: 'warning',   icon: 'bi-exclamation-triangle-fill' },
        danger:  { color: 'danger',    icon: 'bi-x-circle-fill' },
        ticket:  { color: 'primary',   icon: 'bi-ticket-detailed-fill' },
        comment: { color: 'secondary', icon: 'bi-chat-fill' },
        status:  { color: 'warning',   icon: 'bi-arrow-repeat' },
        assign:  { color: 'info',      icon: 'bi-person-fill-check' },
        part:    { color: 'secondary', icon: 'bi-tools' },
    };

    function getMeta(type) {
        return typeMeta[type] || { color: 'secondary', icon: 'bi-bell-fill' };
    }

    /* ── Badge helpers ─────────────────────────────────────── */
    function updateBadge(count) {
        var badge = document.getElementById('notif-bell-badge');
        if (!badge) return;
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = '';
        } else {
            badge.style.display = 'none';
        }
    }

    /* ── Toast factory ─────────────────────────────────────── */
    function showToast(notif) {
        var meta    = getMeta(notif.type);
        var toastId = 'toast-n-' + notif.id;
        if (document.getElementById(toastId)) return; // already shown

        var linkHtml = notif.url
            ? '<a href="' + escHtml(notif.url) + '" class="btn btn-sm btn-outline-primary mt-2 notif-toast-link" data-id="' + notif.id + '">Visualizza</a>'
            : '';

        var bodyHtml = '';
        if (notif.message) {
            bodyHtml = '<div class="toast-body small text-muted">' + escHtml(notif.message) + (linkHtml ? '<br>' + linkHtml : '') + '</div>';
        } else if (linkHtml) {
            bodyHtml = '<div class="toast-body">' + linkHtml + '</div>';
        }

        var html = '<div id="' + toastId + '" class="toast notif-toast toast-' + escHtml(notif.type) + ' align-items-start border-0 shadow" role="alert" aria-live="assertive" data-bs-autohide="true" data-bs-delay="7000">' +
            '<div class="toast-header">' +
            '<i class="bi ' + meta.icon + ' text-' + meta.color + ' me-2"></i>' +
            '<strong class="me-auto">' + escHtml(notif.title) + '</strong>' +
            '<small class="text-muted ms-2">ora</small>' +
            '<button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>' +
            '</div>' + bodyHtml + '</div>';

        var container = document.getElementById('toast-container');
        if (!container) return;
        container.insertAdjacentHTML('beforeend', html);
        var el = document.getElementById(toastId);
        if (el) {
            el.addEventListener('hidden.bs.toast', function () { el.remove(); });
            var bsToast = new bootstrap.Toast(el);
            bsToast.show();

            // Mark read when clicking "Visualizza" inside toast
            el.addEventListener('click', function (e) {
                var link = e.target.closest('.notif-toast-link');
                if (link) {
                    e.preventDefault();
                    markRead(link.dataset.id, function () {
                        window.location.href = link.href;
                    });
                }
            });
        }
    }

    /* ── HTML escaping ─────────────────────────────────────── */
    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    /* ── Mark read ─────────────────────────────────────────── */
    function markRead(id, cb) {
        $.post(apiUrl, { action: 'mark_read', id: id, csrf_token: csrfToken }, function (r) {
            if (typeof r === 'string') r = JSON.parse(r);
            if (r.success) { updateBadge(r.unread || 0); }
            if (cb) cb();
        });
    }

    /* ── Dropdown list renderer ────────────────────────────── */
    function renderDropdown(notifications) {
        var list  = document.getElementById('notif-dropdown-list');
        if (!list) return;

        if (!notifications || !notifications.length) {
            list.innerHTML = '<div class="text-center text-muted py-4 small"><i class="bi bi-bell-slash fs-4 d-block mb-2"></i>Nessuna notifica</div>';
            return;
        }

        var html = '';
        notifications.forEach(function (n) {
            var meta    = getMeta(n.type);
            var urlAttr = n.url ? 'href="' + escHtml(n.url) + '"' : 'href="#"';
            html += '<a ' + urlAttr + ' class="notif-dropdown-item' + (n.is_read ? '' : ' unread') + '" data-id="' + n.id + '">' +
                '<div class="d-flex align-items-start gap-2">' +
                '<div class="notif-type-dot notif-dot-' + escHtml(n.type) + '"></div>' +
                '<div class="flex-grow-1 min-w-0">' +
                '<div class="d-flex justify-content-between">' +
                '<span class="small fw-semibold text-truncate">' + escHtml(n.title) + '</span>' +
                '<span class="text-muted small ms-2 text-nowrap">' + formatRelativeTime(n.created_at) + '</span>' +
                '</div>' +
                (n.message ? '<div class="text-muted small text-truncate">' + escHtml(n.message) + '</div>' : '') +
                '</div></div></a>';
        });
        list.innerHTML = html;
    }

    /* ── Relative time helper ──────────────────────────────── */
    function formatRelativeTime(dateStr) {
        var d = new Date(dateStr.replace(' ', 'T'));
        var diff = Math.floor((Date.now() - d.getTime()) / 1000);
        if (diff < 60)   return 'ora';
        if (diff < 3600) return Math.floor(diff / 60) + ' min';
        if (diff < 86400) return Math.floor(diff / 3600) + ' h';
        return Math.floor(diff / 86400) + ' g';
    }

    /* ── Initial load of dropdown ──────────────────────────── */
    function loadDropdown() {
        $.get(apiUrl, { action: 'fetch' }, function (r) {
            if (typeof r === 'string') r = JSON.parse(r);
            if (!r.success) return;
            updateBadge(r.unread || 0);
            renderDropdown(r.notifications || []);

            // Track the max ID we have seen
            if (r.notifications && r.notifications.length) {
                var ids = r.notifications.map(function (n) { return parseInt(n.id, 10); });
                lastMaxId = Math.max.apply(null, ids);
            }
        });
    }

    /* ── Polling ───────────────────────────────────────────── */
    function poll() {
        $.get(apiUrl, { action: 'poll', since: lastMaxId }, function (r) {
            if (typeof r === 'string') r = JSON.parse(r);
            if (!r.success) return;
            updateBadge(r.unread || 0);

            var newNotifs = r.notifications || [];
            if (newNotifs.length) {
                // Show toasts for each new notification
                newNotifs.forEach(function (n) {
                    if (!n.is_read) showToast(n);
                });

                // Update lastMaxId
                var ids = newNotifs.map(function (n) { return parseInt(n.id, 10); });
                lastMaxId = Math.max(lastMaxId, Math.max.apply(null, ids));

                // Reload dropdown list if it's open
                if ($('#notif-dropdown').hasClass('show')) {
                    loadDropdown();
                }
            }
        });
    }

    /* ── Dropdown open event ───────────────────────────────── */
    document.addEventListener('show.bs.dropdown', function (e) {
        if (e.target && e.target.closest('#notif-dropdown')) {
            loadDropdown();
        }
    });

    /* ── Dropdown item click → mark read ──────────────────── */
    $(document).on('click', '.notif-dropdown-item', function (e) {
        var id  = $(this).data('id');
        var url = $(this).attr('href');
        if (!id) return;
        e.preventDefault();
        markRead(id, function () {
            if (url && url !== '#') window.location.href = url;
            else loadDropdown();
        });
    });

    /* ── Mark all read button in dropdown ──────────────────── */
    $(document).on('click', '#notif-mark-all', function (e) {
        e.preventDefault();
        $.post(apiUrl, { action: 'mark_all_read', csrf_token: csrfToken }, function (r) {
            if (typeof r === 'string') r = JSON.parse(r);
            if (r.success) {
                updateBadge(0);
                loadDropdown();
            }
        });
    });

    /* ── Bootstrap prevents link clicks in dropdowns — stop propagation ── */
    $(document).on('click', '.notif-dropdown-menu', function (e) {
        e.stopPropagation();
    });

    /* ── Start ─────────────────────────────────────────────── */
    loadDropdown();
    setInterval(poll, pollInterval);

}());
