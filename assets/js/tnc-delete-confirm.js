/**
 * แปลงลิงก์ลบแบบ GET → POST พร้อมใส่รหัสผ่านยืนยัน (ต้องมี SweetAlert2)
 */
(function () {
    if (typeof Swal === 'undefined') {
        return;
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
            html: 'ข้อมูลจะถูกลบถาวร กรุณาใส่<strong>รหัสผ่านเข้าระบบของคุณ</strong>เพื่อยืนยัน',
            icon: 'warning',
            input: 'password',
            inputLabel: 'รหัสผ่าน',
            inputPlaceholder: 'รหัสผ่าน',
            showCancelButton: true,
            confirmButtonText: 'ลบข้อมูล',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc3545',
            reverseButtons: true,
            focusCancel: true,
            inputAttributes: {
                autocapitalize: 'off',
                autocorrect: 'off',
                autocomplete: 'current-password'
            },
            preConfirm: function (pw) {
                if (!pw || !String(pw).trim()) {
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
            form.submit();
        });
    });
})();
