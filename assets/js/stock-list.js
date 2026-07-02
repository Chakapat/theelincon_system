(function ($) {
    'use strict';

    if (!$) {
        return;
    }

    var boot = window.__tncStockListBoot || {};
    var dtDefaults = window.TncDataTablesDefaults || {};
    var thLang = dtDefaults.language || { url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json' };

    var $mov = $('#stockMovementsTable');
    if ($mov.length && $mov.find('tbody tr.stock-row').length) {
        $mov.DataTable($.extend(true, {}, dtDefaults, {
            order: [[0, 'desc']],
            pageLength: 25,
            language: thLang,
            columnDefs: boot.movementColumnDefs || []
        }));
    }

    var $bal = $('#stockBalanceTable');
    if ($bal.length) {
        $bal.DataTable($.extend(true, {}, dtDefaults, {
            paging: false,
            searching: true,
            info: false,
            order: [[0, 'asc']],
            language: thLang
        }));
    }

    var lastCs = boot.checksum || '';
    var checksumUrl = boot.checksumUrl || '';

    if (checksumUrl) {
        setInterval(function () {
            if (document.hidden || document.querySelector('.modal.show')) {
                return;
            }
            fetch(checksumUrl, { credentials: 'same-origin' })
                .then(function (r) {
                    return r.json();
                })
                .then(function (d) {
                    if (!d || !d.ok || !d.checksum) {
                        return;
                    }
                    if (lastCs === '') {
                        lastCs = d.checksum;
                        return;
                    }
                    if (d.checksum !== lastCs) {
                        window.location.reload();
                    }
                })
                .catch(function () {});
        }, 5000);
    }

    $('.stock-edit-btn').each(function () {
        $(this).on('click', function () {
            var btn = $(this);
            $('#editMovementId').val(btn.data('id'));
            $('#editTxnDate').val(btn.data('date'));
            $('#editPersonName').val(btn.data('person'));
            $('#editProductId').val(btn.attr('data-product-id'));
            $('#editMovementType').val(btn.data('type'));
            $('#editQty').val(btn.data('qty'));
            $('#editNote').val(btn.data('note'));
        });
    });
})(window.jQuery);
