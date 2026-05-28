/**
 * แปลงลิงก์ลบแบบ GET → POST พร้อมยืนยันการลบ (ไม่ใช้รหัสผ่าน)
 */
(function () {
    if (typeof Swal === 'undefined') {
        return;
    }

    if (!document.getElementById('tnc-delete-swal-style')) {
        var style = document.createElement('style');
        style.id = 'tnc-delete-swal-style';
        style.textContent = '' +
            '.swal2-container.tnc-delete-overlay{background:rgba(15,23,42,.38)!important;backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px);}' +
            '.swal2-popup.tnc-delete-popup{width:min(92vw,430px)!important;border-radius:12px!important;border:1px solid rgba(255,255,255,.42)!important;background:rgba(255,255,255,.9)!important;box-shadow:0 1rem 2.2rem rgba(0,0,0,.24)!important;padding:1.25rem 1.2rem 1.05rem!important;}' +
            '.swal2-popup.tnc-delete-popup .swal2-title{font-size:1.08rem!important;font-weight:800!important;letter-spacing:.01em;color:#991b1b!important;}' +
            '.swal2-popup.tnc-delete-popup .swal2-html-container{line-height:1.65!important;font-size:.94rem!important;color:#475569!important;margin-top:.22rem!important;}' +
            '.swal2-popup.tnc-delete-popup .swal2-actions{width:100%;gap:.45rem;margin-top:.95rem!important;}' +
            '.swal2-popup.tnc-delete-popup .swal2-confirm,.swal2-popup.tnc-delete-popup .swal2-cancel{min-height:44px!important;border-radius:12px!important;font-weight:700!important;padding:.62rem 1.08rem!important;}' +
            '.swal2-popup.tnc-delete-popup .swal2-confirm{background:#dc3545!important;box-shadow:0 .45rem .95rem rgba(220,53,69,.26)!important;}' +
            '.swal2-popup.tnc-delete-popup .swal2-cancel{background:rgba(255,255,255,.72)!important;color:#475569!important;border:1px solid rgba(100,116,139,.28)!important;}' +
            '.tnc-delete-alert-icon{width:68px;height:68px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;margin:0 auto .35rem;background:rgba(220,53,69,.12);border:1px solid rgba(220,53,69,.3);color:#dc3545;font-size:1.95rem;animation:tncDeletePulse 1.2s ease-in-out infinite;}' +
            '@keyframes tncDeletePulse{0%,100%{transform:scale(1);opacity:1;}50%{transform:scale(1.08);opacity:.9;}}' +
            '@keyframes tncDeleteShake{0%,100%{transform:translateX(0);}20%{transform:translateX(-6px);}40%{transform:translateX(5px);}60%{transform:translateX(-4px);}80%{transform:translateX(3px);}}';
        document.head.appendChild(style);
    }

    document.addEventListener('click', function (ev) {
        var a = ev.target.closest('a.tnc-delete-post');
        if (!a) {
            return;
        }
        var href = a.getAttribute('href');
        if (!href || href === '#' || href.trim().indexOf('javascript:') === 0) {
            return;
        }
        ev.preventDefault();

        Swal.fire({
            title: 'ยืนยันการลบ',
            html: '<div class="tnc-delete-alert-icon" aria-hidden="true"><i class="bi bi-exclamation-lg"></i></div>'
                + '<div>คุณต้องการลบข้อมูลนี้จริงหรือไม่</div>',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-trash3 me-1"></i>ยืนยัน',
            cancelButtonText: 'ยกเลิก',
            reverseButtons: true,
            focusCancel: true,
            showClass: { popup: 'swal2-show' },
            hideClass: { popup: 'swal2-hide' },
            customClass: { container: 'tnc-delete-overlay', popup: 'tnc-delete-popup' }
        }).then(function (res) {
            if (!res.isConfirmed) {
                return;
            }
            var u;
            try {
                u = new URL(href, window.location.href);
            } catch (e) {
                return;
            }
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = u.pathname;
            form.style.display = 'none';
            u.searchParams.forEach(function (val, key) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = key;
                inp.value = val;
                form.appendChild(inp);
            });
            var cf = document.createElement('input');
            cf.type = 'hidden';
            cf.name = 'confirm_delete';
            cf.value = '1';
            form.appendChild(cf);
            document.body.appendChild(form);
            if (window.TncLoadingOverlay && typeof window.TncLoadingOverlay.show === 'function') {
                window.TncLoadingOverlay.show();
            }
            form.submit();
        });
    });
})();
