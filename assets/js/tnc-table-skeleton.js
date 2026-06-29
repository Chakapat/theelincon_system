(function (window) {
    'use strict';

    var PR_GRID = '2rem 1.2fr 1fr 0.7fr 0.8fr 5rem';
    var PO_GRID = '2rem 1.2fr 1fr 1.2fr 0.9fr 5rem';

    function skeletonCells(variant) {
        if (variant === 'po') {
            return '<span class="tnc-table-skeleton-line sm"></span>'
                + '<span class="tnc-table-skeleton-line md"></span>'
                + '<span class="tnc-table-skeleton-line lg"></span>'
                + '<span class="tnc-table-skeleton-line md"></span>'
                + '<span class="tnc-table-skeleton-line md"></span>'
                + '<span class="tnc-table-skeleton-actions">'
                + '<span class="tnc-table-skeleton-dot"></span>'
                + '<span class="tnc-table-skeleton-dot"></span>'
                + '</span>';
        }

        return '<span class="tnc-table-skeleton-line sm"></span>'
            + '<span class="tnc-table-skeleton-line md"></span>'
            + '<span class="tnc-table-skeleton-line lg"></span>'
            + '<span class="tnc-table-skeleton-line sm"></span>'
            + '<span class="tnc-table-skeleton-line md"></span>'
            + '<span class="tnc-table-skeleton-actions">'
            + '<span class="tnc-table-skeleton-dot"></span>'
            + '<span class="tnc-table-skeleton-dot"></span>'
            + '</span>';
    }

    function loadingRowHtml(colspan, variantOrGrid) {
        var grid = variantOrGrid === 'po' ? PO_GRID : (variantOrGrid === 'pr' ? PR_GRID : (variantOrGrid || PR_GRID));
        var variant = variantOrGrid === 'po' ? 'po' : 'pr';
        var rows = '';
        var i;

        for (i = 0; i < 3; i += 1) {
            rows += '<div class="tnc-table-skeleton-row" style="grid-template-columns:' + grid + '">'
                + skeletonCells(variant)
                + '</div>';
        }

        return '<tr class="tnc-table-skeleton-row"><td colspan="' + colspan + '">'
            + '<div class="tnc-table-skeleton-wrap" aria-label="Loading table rows">' + rows + '</div>'
            + '</td></tr>';
    }

    function reveal(bodyId, tableId, onReady) {
        var body = document.getElementById(bodyId);
        var table = tableId ? document.getElementById(tableId) : null;
        var skeleton;

        if (!body || !body.classList.contains('tnc-table-is-loading')) {
            if (typeof onReady === 'function') {
                onReady();
            }
            return;
        }

        skeleton = body.querySelector('tr.tnc-table-skeleton-row');
        if (skeleton) {
            skeleton.remove();
        }
        body.classList.remove('tnc-table-is-loading');
        if (table) {
            table.setAttribute('aria-busy', 'false');
        }

        if (typeof onReady === 'function') {
            onReady();
        }
    }

    function bootListPage(opts) {
        var bodyId = opts && opts.bodyId;
        var tableId = opts && opts.tableId;
        var onReady = opts && opts.onReady;
        var run;

        run = function () {
            reveal(bodyId, tableId, onReady);
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            window.requestAnimationFrame(run);
        }
    }

    window.TncTableSkeleton = {
        PR_GRID: PR_GRID,
        PO_GRID: PO_GRID,
        loadingRowHtml: loadingRowHtml,
        reveal: reveal,
        bootListPage: bootListPage
    };
}(window));
