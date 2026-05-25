/**
 * แปลงลิงก์ลบแบบ GET → POST พร้อมใส่รหัสผ่านยืนยัน (ต้องมี SweetAlert2)
 * window.tncSwalAttachPasswordReveal — ใส่ใน didOpen ของ Swal ที่ input เป็น password (เช่น index deleteItem)
 */
(function () {
    var tncPassEyeSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="1.15em" height="1.15em" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/><path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/></svg>';
    var tncPassEyeSlashSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="1.15em" height="1.15em" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="m10.79 12.912-1.614-1.615a3.5 3.5 0 0 1-4.474-4.474l-2.06-2.06C.938 6.278 0 8 0 8s3 5.5 8 5.5a7.029 7.029 0 0 0 2.79-.588zM5.21 3.088A7.028 7.028 0 0 1 8 2.5c5 0 8 5.5 8 5.5s-.939 1.721-2.641 3.238l-2.062-2.062a3.5 3.5 0 0 0-4.474-4.474L5.21 3.089z"/><path d="M5.525 7.646a2.5 2.5 0 0 0 2.829 2.829l-2.83-2.829zm4.95.708-2.829-2.83a2.5 2.5 0 0 1 2.829 2.829zm3.171-6.122-1.414 1.414A8.5 8.5 0 0 1 16 8s-3 5.5-8 5.5a8.5 8.5 0 0 1-4.063-.98l-1.414 1.414A10.5 10.5 0 0 0 8 14c5 0 8-5.5 8-5.5a10.5 10.5 0 0 0-2.324-4.474zM2.98 4.98l1.414-1.414A10.5 10.5 0 0 0 0 8c0 0 3 5.5 8 5.5a8.5 8.5 0 0 0 4.063-.98l1.414 1.414A10.5 10.5 0 0 1 8 14c-5 0-8-5.5-8-5.5 0-1.61.656-3.22 1.98-4.52z"/></svg>';

    /**
     * ปุ่มลูกตาแสดง/ซ่อนรหัสผ่านใน Swal (เรียกจาก didOpen)
     * ห่อเฉพาะช่อง input ใน wrapper — parent เดิมมักเป็น .swal2-popup ทำให้ top:50% เพี้ยน
     */
    window.tncSwalAttachPasswordReveal = function () {
        if (typeof Swal === 'undefined') {
            return;
        }
        var run = function () {
            var input = Swal.getInput();
            if (!input || (input.type !== 'password' && input.type !== 'text')) {
                return;
            }
            if (input.getAttribute('data-tnc-pass-reveal') === '1') {
                return;
            }
            var popup = input.closest('.swal2-popup');
            if (!popup) {
                return;
            }
            input.setAttribute('data-tnc-pass-reveal', '1');

            var passHost = input.closest('.tnc-delete-pass-input-wrap');
            if (!passHost) {
                passHost = document.createElement('div');
                passHost.className = 'tnc-delete-pass-input-wrap';
                var parent = input.parentNode;
                if (!parent) {
                    return;
                }
                parent.insertBefore(passHost, input);
                passHost.appendChild(input);
            }

            var toggle = passHost.querySelector('.tnc-delete-pass-toggle');
            if (!toggle) {
                toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'tnc-delete-pass-toggle';
                toggle.setAttribute('aria-label', 'แสดงรหัสผ่าน');
                toggle.setAttribute('title', 'แสดงรหัสผ่าน');
                toggle.innerHTML = tncPassEyeSvg;
                toggle.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var hidden = input.type === 'password';
                    input.type = hidden ? 'text' : 'password';
                    toggle.innerHTML = hidden ? tncPassEyeSlashSvg : tncPassEyeSvg;
                    toggle.setAttribute('aria-label', hidden ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
                    toggle.setAttribute('title', hidden ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
                });
                passHost.appendChild(toggle);
            }
            input.focus();
        };
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                run();
            });
        });
    };

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
            '.swal2-popup.tnc-delete-popup .swal2-input{min-height:44px!important;border-radius:10px!important;border:1px solid #d7dce2!important;padding-right:2.5rem!important;}' +
            '.swal2-popup.tnc-delete-popup .swal2-actions{width:100%;gap:.45rem;margin-top:.95rem!important;}' +
            '.swal2-popup.tnc-delete-popup .swal2-confirm,.swal2-popup.tnc-delete-popup .swal2-cancel{min-height:44px!important;border-radius:12px!important;font-weight:700!important;padding:.62rem 1.08rem!important;}' +
            '.swal2-popup.tnc-delete-popup .swal2-confirm{background:#dc3545!important;box-shadow:0 .45rem .95rem rgba(220,53,69,.26)!important;}' +
            '.swal2-popup.tnc-delete-popup .swal2-cancel{background:rgba(255,255,255,.72)!important;color:#475569!important;border:1px solid rgba(100,116,139,.28)!important;}' +
            '.tnc-delete-alert-icon{width:68px;height:68px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;margin:0 auto .35rem;background:rgba(220,53,69,.12);border:1px solid rgba(220,53,69,.3);color:#dc3545;font-size:1.95rem;animation:tncDeletePulse 1.2s ease-in-out infinite;}' +
            '.tnc-delete-pass-input-wrap{position:relative!important;display:block!important;width:calc(100% - 1.6em)!important;max-width:100%!important;margin:.5em auto 0!important;box-sizing:border-box!important;}' +
            '.tnc-delete-pass-input-wrap .swal2-input{display:block!important;width:100%!important;height:44px!important;min-height:44px!important;margin:0!important;padding:0 .75rem!important;padding-right:2.85rem!important;box-sizing:border-box!important;}' +
            '.tnc-delete-pass-input-wrap .tnc-delete-pass-toggle{position:absolute!important;right:4px!important;top:50%!important;left:auto!important;width:2.35rem!important;height:2.35rem!important;transform:translateY(-50%)!important;z-index:5;margin:0!important;}' +
            '.tnc-delete-pass-toggle{display:inline-flex;align-items:center;justify-content:center;width:2.1rem;height:2.1rem;border:none;background:transparent;color:#64748b;line-height:1;padding:0;border-radius:8px;cursor:pointer;}' +
            '.tnc-delete-pass-toggle:hover{background:rgba(241,245,249,.95);color:#334155;}' +
            '.tnc-delete-pass-toggle:focus-visible{outline:2px solid rgba(253,126,20,.45);outline-offset:1px;}' +
            '.tnc-delete-pass-shake{animation:tncDeleteShake .34s ease-in-out 1;}' +
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
                + '<div>ข้อมูลจะถูกลบถาวร กรุณาใส่<strong>รหัสผ่านเข้าระบบของคุณ</strong>เพื่อยืนยัน</div>',
            input: 'password',
            inputLabel: 'รหัสผ่าน',
            inputPlaceholder: 'รหัสผ่าน',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-trash3 me-1"></i>ยืนยัน ลบข้อมูล',
            cancelButtonText: 'ยกเลิก',
            reverseButtons: true,
            focusCancel: true,
            showClass: { popup: 'swal2-show' },
            hideClass: { popup: 'swal2-hide' },
            customClass: { container: 'tnc-delete-overlay', popup: 'tnc-delete-popup' },
            inputAttributes: {
                autocapitalize: 'off',
                autocorrect: 'off',
                autocomplete: 'current-password'
            },
            didOpen: function () {
                if (typeof window.tncSwalAttachPasswordReveal === 'function') {
                    window.tncSwalAttachPasswordReveal();
                }
            },
            preConfirm: function (pw) {
                if (!pw || !String(pw).trim()) {
                    var input = Swal.getInput();
                    if (input) {
                        input.classList.remove('tnc-delete-pass-shake');
                        void input.offsetWidth;
                        input.classList.add('tnc-delete-pass-shake');
                    }
                    Swal.showValidationMessage('กรุณากรอกรหัสผ่าน');
                    return false;
                }
                return pw;
            }
        }).then(function (res) {
            if (!res.isConfirmed || !res.value) {
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
            var cp = document.createElement('input');
            cp.type = 'hidden';
            cp.name = 'confirm_password';
            cp.value = res.value;
            form.appendChild(cp);
            document.body.appendChild(form);
            if (window.TncLoadingOverlay && typeof window.TncLoadingOverlay.show === 'function') {
                window.TncLoadingOverlay.show();
            }
            form.submit();
        });
    });
})();
