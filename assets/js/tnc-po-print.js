/**
 * PO print: รอโหลดรูปสลิป / แนบใบเสนอราคา แล้วค่อย window.print (ลดกรณีหน้าสลิปว่าง)
 * เปิด URL พร้อม autoprint=1 จะเรียกพิมพ์อัตโนมัติหลังโหลดหน้า
 * beforeprint: บังคับ decode + เลื่อนรูปเข้า viewport ช่วย Chromium วาดรูปใน print preview
 */
(function (w) {
    'use strict';

    function tncPreparePoPrintImages() {
        var imgs = document.querySelectorAll('img.tnc-po-deferred-print-img');
        imgs.forEach(function (img) {
            try {
                if (typeof img.decode === 'function') {
                    img.decode().catch(function () {});
                }
            } catch (e1) {
                /* ignore */
            }
            try {
                img.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            } catch (e2) {
                /* ignore */
            }
        });
    }

    var savedPrintTitle = '';

    function tncPoPrintHideBrowserHeaderTitle() {
        try {
            savedPrintTitle = document.title || '';
            document.title = '\u00a0';
        } catch (e0) {
            /* ignore */
        }
    }

    function tncPoPrintRestoreTitle() {
        try {
            if (savedPrintTitle !== '') {
                document.title = savedPrintTitle;
            }
        } catch (e1) {
            /* ignore */
        }
    }

    function tncPoBeforePrint() {
        tncPreparePoPrintImages();
        tncPoPrintHideBrowserHeaderTitle();
    }

    if (typeof w.addEventListener === 'function') {
        w.addEventListener('beforeprint', tncPoBeforePrint);
        w.addEventListener('afterprint', tncPoPrintRestoreTitle);
    }

    if (w.matchMedia) {
        var mq = w.matchMedia('print');
        var onMq = function (ev) {
            if (ev.matches) {
                tncPreparePoPrintImages();
            }
        };
        if (typeof mq.addEventListener === 'function') {
            mq.addEventListener('change', onMq);
        } else if (typeof mq.addListener === 'function') {
            mq.addListener(onMq);
        }
    }

    function tncPrintPoWhenReady() {
        tncPreparePoPrintImages();
        var fired = false;
        function firePrint() {
            if (fired) {
                return;
            }
            fired = true;
            var ifrs = document.querySelectorAll('iframe.tnc-po-deferred-print-iframe');
            var delayMs = ifrs.length > 0 ? 1400 : 0;
            setTimeout(function () {
                w.print();
            }, delayMs);
        }

        var imgs = Array.prototype.slice.call(document.querySelectorAll('img.tnc-po-deferred-print-img'));
        var pending = imgs.filter(function (img) {
            return !img.complete || img.naturalHeight === 0;
        });

        if (pending.length === 0) {
            firePrint();
            return;
        }

        var left = pending.length;
        function tick() {
            left -= 1;
            if (left <= 0) {
                firePrint();
            }
        }

        pending.forEach(function (img) {
            img.addEventListener('load', tick, { once: true });
            img.addEventListener('error', tick, { once: true });
        });

        setTimeout(firePrint, 8000);
    }

    w.tncPrintPoWhenReady = tncPrintPoWhenReady;

    function tryAutoprint() {
        try {
            var p = new URLSearchParams(w.location.search);
            if (p.get('autoprint') !== '1') {
                return;
            }
            function schedule() {
                setTimeout(tncPrintPoWhenReady, 250);
            }
            if (document.readyState === 'complete') {
                schedule();
            } else {
                w.addEventListener('load', schedule);
            }
        } catch (e) {
            /* ignore */
        }
    }

    tryAutoprint();
})(window);
