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
    /* Inline Thai strings — avoid extra CDN round-trip that blocked table init */
    language: {
        decimal: '',
        emptyTable: 'ไม่มีข้อมูลในตาราง',
        info: 'แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ',
        infoEmpty: 'แสดง 0 ถึง 0 จาก 0 รายการ',
        infoFiltered: '(กรองจากทั้งหมด _MAX_ รายการ)',
        infoPostFix: '',
        thousands: ',',
        lengthMenu: 'แสดง _MENU_ รายการ',
        loadingRecords: 'กำลังโหลด…',
        processing: 'กำลังดำเนินการ…',
        search: 'ค้นหา:',
        zeroRecords: 'ไม่พบข้อมูล',
        paginate: {
            first: 'หน้าแรก',
            last: 'หน้าสุดท้าย',
            next: 'ถัดไป',
            previous: 'ก่อนหน้า'
        },
        aria: {
            sortAscending: ': เรียงจากน้อยไปมาก',
            sortDescending: ': เรียงจากมากไปน้อย'
        }
    }
};
</script>
