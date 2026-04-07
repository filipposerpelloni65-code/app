<?php $currentUser = currentUser() ?? null; ?>
        </div><!-- end container-fluid p-4 -->
    <?php if ($currentUser): ?>
    </div><!-- end page-content-wrapper -->
</div><!-- end wrapper -->
<?php else: ?>
</div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.4/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<?php if (isset($extraJs)) echo $extraJs; ?>
</body>
</html>
