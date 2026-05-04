/**
 * DataTables + optional polling refresh (no full page reload).
 */
(function (window, $) {
    'use strict';

    if (!$ || !$.fn.DataTable) {
        return;
    }

    window.TncLiveDT = {
        /**
         * @param {string|JQuery} tableSelector
         * @param {object} dtOpts jQuery DataTables options
         * @param {object} [liveOpts] { url: string, intervalMs: number, mapRows?: function(resp)->array for rows.add }
         */
        init: function (tableSelector, dtOpts, liveOpts) {
            var $t = $(tableSelector);
            if (!$t.length) {
                return null;
            }
            var base = (typeof window.TncDataTablesDefaults === 'object' && window.TncDataTablesDefaults !== null)
                ? window.TncDataTablesDefaults
                : {};
            var merged = $.extend(true, {}, base, dtOpts || {});
            var dt = $t.DataTable(merged);
            if (liveOpts && liveOpts.url) {
                this.attachPoll(dt, liveOpts);
            }
            return dt;
        },

        attachPoll: function (dt, liveOpts) {
            var url = liveOpts.url;
            var intervalMs = liveOpts.intervalMs || 5000;
            var lastChecksum = '';
            var mapRows = liveOpts.mapRows;

            function apply(resp) {
                if (!resp || !resp.ok) {
                    return;
                }
                if (resp.checksum && resp.checksum === lastChecksum) {
                    return;
                }
                lastChecksum = resp.checksum || '';
                if (typeof mapRows === 'function') {
                    var rows = mapRows(resp) || [];
                    dt.clear();
                    if (rows.length) {
                        dt.rows.add(rows);
                    }
                    dt.draw(false);
                }
            }

            function tick() {
                if (document.hidden) {
                    return;
                }
                fetch(url, { credentials: 'same-origin' })
                    .then(function (r) {
                        return r.json();
                    })
                    .then(apply)
                    .catch(function () {});
            }

            setInterval(tick, intervalMs);
            setTimeout(tick, 800);
        }
    };
})(window, window.jQuery);
