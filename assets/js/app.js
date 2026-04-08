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

    // Confirm dialogs
    $(document).on('click', '[data-confirm]', function (e) {
        var message = $(this).data('confirm') || 'Sei sicuro di voler procedere?';
        if (!confirm(message)) {
            e.preventDefault();
            return false;
        }
    });

    // CSRF token in AJAX headers
    var csrfToken = $('meta[name="csrf-token"]').attr('content');
    if (csrfToken) {
        $.ajaxSetup({
            headers: { 'X-CSRF-Token': csrfToken }
        });
    }

    // Tooltip initialization
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

    // Status update form confirmation
    $('#statusUpdateForm').on('submit', function (e) {
        var newStatus = $(this).find('[name="status"]').val();
        var statusLabels = {
            'open': 'Aperto',
            'in_progress': 'In Lavorazione',
            'waiting': 'In Attesa',
            'resolved': 'Risolto',
            'closed': 'Chiuso'
        };
        var label = statusLabels[newStatus] || newStatus;
        if (!confirm('Confermi il cambio di stato a: ' + label + '?')) {
            e.preventDefault();
        }
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
            action: 'toggle_module',
            slug: slug,
            enabled: enabled,
            csrf_token: csrfVal
        }).done(function (resp) {
            var data = typeof resp === 'string' ? JSON.parse(resp) : resp;
            if (!data.success) {
                alert('Errore durante il salvataggio.');
            }
        }).fail(function () {
            alert('Errore di comunicazione con il server.');
        });
    });

    // Install wizard step navigation
    var currentStep = 1;
    var totalSteps = 5;

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

// Global search
$(document).ready(function () {
    var searchInput = $('#globalSearchInput');
    var searchResults = $('#globalSearchResults');
    var searchTimer = null;

    if (!searchInput.length) return;

    searchInput.on('input', function () {
        clearTimeout(searchTimer);
        var q = $(this).val().trim();
        if (q.length < 2) {
            searchResults.hide().empty();
            return;
        }
        searchTimer = setTimeout(function () {
            $.get(window.appUrl + '/api/search.php', { q: q }, function (data) {
                searchResults.empty();
                if (!data.results || !data.results.length) {
                    searchResults.append('<div class="p-2 text-muted small">Nessun risultato per "' + $('<div>').text(q).html() + '"</div>');
                } else {
                    data.results.forEach(function (r) {
                        var item = $('<a>')
                            .attr('href', r.url)
                            .addClass('d-flex align-items-center gap-2 px-3 py-2 text-decoration-none text-dark border-bottom search-result-item')
                            .html('<i class="bi ' + r.icon + ' text-primary flex-shrink-0"></i>' +
                                  '<span class="small text-truncate flex-grow-1">' + $('<div>').text(r.label).html() + '</span>' +
                                  (r.badge ? '<span class="badge bg-secondary ms-1 flex-shrink-0" style="font-size:0.7rem">' + $('<div>').text(r.badge).html() + '</span>' : ''));
                        searchResults.append(item);
                    });
                }
                searchResults.show();
            }, 'json').fail(function () {
                searchResults.hide();
            });
        }, 300);
    });

    // Hide results when clicking outside
    $(document).on('click', function (e) {
        if (!$(e.target).closest('#globalSearchInput, #globalSearchResults').length) {
            searchResults.hide();
        }
    });

    // Close on ESC
    searchInput.on('keydown', function (e) {
        if (e.key === 'Escape') {
            searchResults.hide();
            $(this).val('');
        }
    });
});
