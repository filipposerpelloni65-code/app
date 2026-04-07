/* ============================================
   HelpDesk - Main JavaScript
   ============================================ */

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
     * @param {function} onConfirm - Callback executed when user confirms
     * @param {object}   options   - { title, btnClass, btnText }
     */
    window.showConfirmModal = function (message, onConfirm, options) {
        options = options || {};
        var title   = options.title    || 'Conferma';
        var btnClass = options.btnClass || 'btn-danger';
        var btnText  = options.btnText  || 'Conferma';

        $('#confirmModalTitle').text(title);
        $('#confirmModalBody').text(message);
        $('#confirmModalOk')
            .attr('class', 'btn btn-sm ' + btnClass)
            .off('click.modalConfirm')
            .on('click.modalConfirm', function () {
                var modalEl = document.getElementById('confirmModal');
                var bsModal = bootstrap.Modal.getInstance(modalEl);
                if (bsModal) bsModal.hide();
                if (typeof onConfirm === 'function') onConfirm();
            });
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
        var $form    = $(this);
        var newStatus = $form.find('[name="new_status"]').val();
        var statusLabels = {
            'open':        'Aperto',
            'in_progress': 'In Lavorazione',
            'waiting':     'In Attesa',
            'resolved':    'Risolto',
            'closed':      'Chiuso'
        };
        var label = statusLabels[newStatus] || newStatus;

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
        var statusLabels = {
            'open':        'Aperto',
            'in_progress': 'In Lavorazione',
            'waiting':     'In Attesa',
            'resolved':    'Risolto',
            'closed':      'Chiuso'
        };
        var label = statusLabels[newStatus] || newStatus;

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
        var max     = $(this).attr('maxlength');
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
        var slug    = $(this).data('slug');
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
                showConfirmModal('Errore durante il salvataggio.', null, { title: 'Errore', btnClass: 'd-none' });
            }
        }).fail(function () {
            showConfirmModal('Errore di comunicazione con il server.', null, { title: 'Errore', btnClass: 'd-none' });
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
});

// App URL global (set in PHP pages)
window.appUrl = window.appUrl || '';
