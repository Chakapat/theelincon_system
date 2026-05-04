<?php

declare(strict_types=1);

if (!function_exists('app_path')) {
    require_once dirname(__DIR__) . '/config/foundation.php';
}

?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="<?= htmlspecialchars(app_path('assets/js/tnc-live-datatable.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
window.TncDataTablesDefaults = {
    pageLength: 25,
    lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'ทั้งหมด']],
    order: [],
    autoWidth: false,
    language: { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' }
};
</script>
