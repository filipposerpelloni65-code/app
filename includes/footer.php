<?php $currentUser = currentUser() ?? null; ?>
        </div><!-- end container-fluid p-4 -->
    <?php if ($currentUser): ?>
    </div><!-- end page-content-wrapper -->
</div><!-- end wrapper -->
<?php else: ?>
</div>
<?php endif; ?>

<!-- ============================================================
     GLOBAL MODALS (available on every page)
     ============================================================ -->

<!-- Global Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0 pb-0 pt-3 px-4">
                <h6 class="modal-title fw-semibold" id="confirmModalLabel">
                    <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i><span id="confirmModalTitle">Conferma</span>
                </h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body px-4 py-3" id="confirmModalBody">Sei sicuro di voler procedere?</div>
            <div class="modal-footer border-0 pt-0 pb-3 px-4 gap-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg me-1"></i>Annulla
                </button>
                <button type="button" class="btn btn-sm btn-danger" id="confirmModalOk">
                    <i class="bi bi-check-lg me-1"></i><span id="confirmModalOkText">Conferma</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Ticket Quick View Modal -->
<div class="modal fade" id="ticketQuickViewModal" tabindex="-1" aria-labelledby="ticketQuickViewLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-3 flex-grow-1 min-w-0">
                    <div>
                        <div class="fw-bold text-white fs-6 text-truncate" id="qvTitle" style="max-width:380px">Caricamento...</div>
                        <code class="text-white opacity-75 small" id="qvCode"></code>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 ms-3" id="qvHeaderActions"></div>
                <button type="button" class="btn-close ms-2" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body" id="qvBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <div class="mt-2 text-muted small">Caricamento...</div>
                </div>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg me-1"></i>Chiudi
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<?php if (isset($extraJs)) echo $extraJs; ?>
</body>
</html>
