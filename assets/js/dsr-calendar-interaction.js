/**
 * ปฏิทิน DSR — คลิกวันเพื่อเปิดฟอร์มบันทึก (ต้องมี window.DSR_CALENDAR_CONFIG)
 */
(function () {
    const cfg = window.DSR_CALENDAR_CONFIG || {};
    const reports = Array.isArray(cfg.reports) ? cfg.reports : [];
    const weekdays = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
    const monthNames = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
    const today = new Date();
    const userId = Number(cfg.userId || 0);
    const isAdmin = !!cfg.isAdmin;
    const maxPhotos = Number(cfg.maxPhotos || 2);

    let currentYear = today.getFullYear();
    let currentMonth = today.getMonth();
    let pendingDateKey = '';

    const elWeekdayRow = document.getElementById('weekdayRow');
    const elCalendarGrid = document.getElementById('calendarGrid');
    const elCalendarTitle = document.getElementById('calendarTitle');
    const elCalendarCountLabel = document.getElementById('calendarCountLabel');
    const btnPrevMonth = document.getElementById('btnPrevMonth');
    const btnNextMonth = document.getElementById('btnNextMonth');
    const btnToday = document.getElementById('btnToday');

    const dayPickModalEl = document.getElementById('dsrDayPickModal');
    const formModalEl = document.getElementById('dsrFormModal');
    let dayPickModal = null;
    let formModal = null;

    function initModals() {
        if (typeof bootstrap === 'undefined') {
            return;
        }
        try {
            if (dayPickModalEl && !dayPickModal) {
                dayPickModal = new bootstrap.Modal(dayPickModalEl);
            }
            if (formModalEl && !formModal) {
                formModal = new bootstrap.Modal(formModalEl);
            }
        } catch (err) {
            console.warn('DSR calendar: modal init failed', err);
        }
    }

    const dsrForm = document.getElementById('dsrCalendarForm');
    const dsrFormAction = document.getElementById('dsrFormAction');
    const dsrFormId = document.getElementById('dsrFormId');
    const dsrFormDeleteBtn = document.getElementById('dsrFormDeleteBtn');

    function pad2(v) {
        return String(v).padStart(2, '0');
    }

    function formatDateThai(dateText) {
        if (!dateText) return '—';
        const dt = new Date(dateText + 'T00:00:00');
        if (Number.isNaN(dt.getTime())) return dateText;
        return pad2(dt.getDate()) + '/' + pad2(dt.getMonth() + 1) + '/' + (dt.getFullYear() + 543);
    }

    function toDateKey(year, month, day) {
        return year + '-' + pad2(month + 1) + '-' + pad2(day);
    }

    function esc(s) {
        return String(s || '').replace(/[&<>"']/g, function (m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }

    function reportsInCurrentMonth() {
        return reports.filter(function (r) {
            const key = String(r.report_date || '').trim();
            if (key === '') return false;
            const dt = new Date(key + 'T00:00:00');
            if (Number.isNaN(dt.getTime())) return false;
            return dt.getFullYear() === currentYear && dt.getMonth() === currentMonth;
        });
    }

    function shiftMonth(delta) {
        let y = currentYear;
        let m = currentMonth + delta;
        if (m < 0) {
            m = 11;
            y -= 1;
        } else if (m > 11) {
            m = 0;
            y += 1;
        }
        currentYear = y;
        currentMonth = m;
        renderCalendar();
    }

    function groupByDate(items) {
        const map = {};
        items.forEach(function (r) {
            const key = String(r.report_date || '').trim();
            if (key === '') return;
            if (!map[key]) map[key] = [];
            map[key].push(r);
        });
        Object.keys(map).forEach(function (k) {
            map[k].sort(function (a, b) {
                return Number(b.id || 0) - Number(a.id || 0);
            });
        });
        return map;
    }

    function canEditItem(item) {
        if (!item) return true;
        const creator = Number(item.created_by || 0);
        return creator === userId || isAdmin;
    }

    /** form.action ถูกบังโดย input[name=action] — ต้องใช้ getAttribute */
    function getFormPostAction() {
        if (!dsrForm) return '';
        return dsrForm.getAttribute('action') || '';
    }

    function submitReportDelete(reportId) {
        const id = Number(reportId || 0);
        if (id <= 0) {
            alert('ไม่พบรายงานที่จะลบ');
            return;
        }
        const postUrl = getFormPostAction();
        if (!postUrl) {
            alert('ไม่พบ URL สำหรับลบรายงาน');
            return;
        }
        const f = document.createElement('form');
        f.method = 'post';
        f.action = postUrl;
        const fields = { action: 'delete', id: String(id), return_to: 'calendar', _csrf: cfg.csrf || '' };
        Object.keys(fields).forEach(function (k) {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = k;
            inp.value = fields[k];
            f.appendChild(inp);
        });
        document.body.appendChild(f);
        f.submit();
    }

    function renderWeekdayRow() {
        if (!elWeekdayRow) return;
        elWeekdayRow.innerHTML = weekdays.map(function (d, idx) {
            const weekend = idx === 0 || idx === 6;
            return '<div class="calendar-weekday' + (weekend ? ' weekend' : '') + '">' + d + '</div>';
        }).join('');
    }

    function openDay(dateKey, dayItems) {
        initModals();
        pendingDateKey = dateKey;
        if (dayItems.length === 0) {
            openFormModal(dateKey, null);
            return;
        }
        if (dayItems.length === 1) {
            openFormModal(dateKey, dayItems[0]);
            return;
        }
        const listEl = document.getElementById('dsrDayPickList');
        const labelEl = document.getElementById('dsrDayPickDateLabel');
        if (labelEl) labelEl.textContent = 'วันที่ ' + formatDateThai(dateKey);
        if (listEl) {
            listEl.innerHTML = dayItems.map(function (item) {
                const title = [item.report_no, item.project_name, item.site_name].filter(Boolean).join(' · ');
                return '<button type="button" class="day-pick-item text-start" data-report-id="' + Number(item.id || 0) + '">' +
                    '<div class="fw-semibold">' + esc(title || 'DSR') + '</div>' +
                    '<div class="small text-muted">' + esc(item.company_name || '') + '</div>' +
                    '</button>';
            }).join('');
            listEl.querySelectorAll('.day-pick-item').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const id = Number(btn.getAttribute('data-report-id') || '0');
                    const found = reports.find(function (r) { return Number(r.id || 0) === id; });
                    if (dayPickModal) dayPickModal.hide();
                    openFormModal(dateKey, found || null);
                });
            });
        }
        const createBtn = document.getElementById('dsrDayPickCreateBtn');
        if (createBtn) {
            createBtn.onclick = function () {
                if (dayPickModal) dayPickModal.hide();
                openFormModal(dateKey, null);
            };
        }
        if (dayPickModal) dayPickModal.show();
    }

    function resetForm() {
        if (!dsrForm) return;
        dsrForm.reset();
        if (dsrFormAction) dsrFormAction.value = 'create';
        if (dsrFormId) dsrFormId.value = '';
        const wrap = document.getElementById('dsrFormReportNoWrap');
        if (wrap) wrap.classList.add('d-none');
        if (dsrFormDeleteBtn) dsrFormDeleteBtn.classList.add('d-none');
        const photoBox = document.getElementById('dsrFormExistingPhotos');
        if (photoBox) photoBox.innerHTML = '';
        const capBox = document.getElementById('dsrFormNewCaptions');
        if (capBox) capBox.innerHTML = '';
        syncIssuesState();
    }

    function syncIssuesState() {
        const hasIssues = document.getElementById('dsrFormHasIssues');
        const issues = document.getElementById('dsrFormIssues');
        if (!hasIssues || !issues) return;
        issues.disabled = !hasIssues.checked;
        if (!hasIssues.checked) issues.value = '';
    }

    function fillForm(item, dateKey) {
        const reportDateEl = document.getElementById('dsrFormReportDate');
        const modalDateLabel = document.getElementById('dsrFormModalDateLabel');
        const modalLabel = document.getElementById('dsrFormModalLabel');
        if (reportDateEl) reportDateEl.value = dateKey;
        if (modalDateLabel) modalDateLabel.textContent = 'วันที่ ' + formatDateThai(dateKey);
        if (!item) {
            if (modalLabel) modalLabel.textContent = 'สร้างรายงานหน้างาน';
            return;
        }
        if (modalLabel) modalLabel.textContent = 'แก้ไขรายงานหน้างาน';
        if (dsrFormAction) dsrFormAction.value = 'update';
        if (dsrFormId) dsrFormId.value = String(item.id || '');
        const wrap = document.getElementById('dsrFormReportNoWrap');
        const noEl = document.getElementById('dsrFormReportNo');
        if (wrap && noEl) {
            noEl.textContent = String(item.report_no || '');
            wrap.classList.remove('d-none');
        }
        document.getElementById('dsrFormWeather').value = String(item.weather || '');
        document.getElementById('dsrFormCompany').value = String(item.company_id || '');
        document.getElementById('dsrFormSite').value = String(item.site_name || '');
        document.getElementById('dsrFormProject').value = String(item.project_name || '');
        document.getElementById('dsrFormWorkers').value = String(item.worker_count || '');
        document.getElementById('dsrFormWorkProgress').value = String(item.work_progress || '');
        document.getElementById('dsrFormMaterials').value = String(item.materials_equipment || '');
        const issuesVal = String(item.issues_remarks || '').trim();
        const hasIssues = document.getElementById('dsrFormHasIssues');
        const issues = document.getElementById('dsrFormIssues');
        if (hasIssues) hasIssues.checked = issuesVal !== '';
        if (issues) issues.value = issuesVal;
        syncIssuesState();

        const photoBox = document.getElementById('dsrFormExistingPhotos');
        const photos = Array.isArray(item.photos) ? item.photos : [];
        if (photoBox) {
            if (photos.length === 0) {
                photoBox.innerHTML = '';
            } else {
                photoBox.innerHTML = photos.map(function (p) {
                    const pid = Number(p.id || 0);
                    return '<div class="col-6 col-md-4"><div class="border rounded p-2 bg-light">' +
                        '<img src="' + esc(p.url) + '" alt="" class="img-fluid rounded mb-1" style="max-height:100px;object-fit:contain;width:100%;">' +
                        '<input type="text" class="form-control form-control-sm mb-1" name="photo_caption[' + pid + ']" value="' + esc(p.caption || '') + '" placeholder="คำอธิบาย">' +
                        '<div class="form-check small"><input class="form-check-input" type="checkbox" name="delete_photo[]" value="' + pid + '" id="delPh' + pid + '">' +
                        '<label class="form-check-label text-danger" for="delPh' + pid + '">ลบรูป</label></div></div></div>';
                }).join('');
            }
        }

        if (dsrFormDeleteBtn && canEditItem(item)) {
            dsrFormDeleteBtn.classList.remove('d-none');
            dsrFormDeleteBtn.onclick = function (e) {
                if (e) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                if (!confirm('ลบรายงานนี้ถาวร?')) return;
                submitReportDelete(item.id);
            };
        }
    }

    function openFormModal(dateKey, item) {
        initModals();
        if (item && !canEditItem(item)) {
            alert('ไม่มีสิทธิ์แก้ไขรายงานนี้');
            return;
        }
        resetForm();
        fillForm(item, dateKey);
        const fileInput = document.getElementById('dsrFormPhotosNew');
        if (fileInput) {
            fileInput.dataset.maxPhotos = String(maxPhotos);
            fileInput.dataset.existingPhotos = String(item && item.photos ? item.photos.length : 0);
        }
        if (formModal) formModal.show();
    }

    function renderCalendar() {
        if (!elCalendarGrid) return;
        const items = reports;
        const mapByDate = groupByDate(items);
        const firstDay = new Date(currentYear, currentMonth, 1);
        const startWeekDay = firstDay.getDay();
        const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
        const prevMonthDays = new Date(currentYear, currentMonth, 0).getDate();
        const html = [];

        for (let i = 0; i < 42; i++) {
            let dayNum = 0;
            let cellMonth = currentMonth;
            let cellYear = currentYear;
            let otherMonth = false;

            if (i < startWeekDay) {
                dayNum = prevMonthDays - startWeekDay + i + 1;
                cellMonth = currentMonth - 1;
                if (cellMonth < 0) { cellMonth = 11; cellYear -= 1; }
                otherMonth = true;
            } else if (i >= startWeekDay + daysInMonth) {
                dayNum = i - (startWeekDay + daysInMonth) + 1;
                cellMonth = currentMonth + 1;
                if (cellMonth > 11) { cellMonth = 0; cellYear += 1; }
                otherMonth = true;
            } else {
                dayNum = i - startWeekDay + 1;
            }

            const dateKey = toDateKey(cellYear, cellMonth, dayNum);
            const dayItems = mapByDate[dateKey] || [];
            const isToday = cellYear === today.getFullYear() && cellMonth === today.getMonth() && dayNum === today.getDate();
            const weekCol = i % 7;
            const isWeekend = weekCol === 0 || weekCol === 6;
            const hasIssues = dayItems.some(function (it) { return String(it.issues_remarks || '').trim() !== ''; });

            let cls = 'calendar-day';
            if (otherMonth) cls += ' other-month';
            if (isToday) cls += ' today';
            if (isWeekend && !otherMonth) cls += ' weekend';
            if (dayItems.length > 0) cls += ' has-report';
            if (hasIssues) cls += ' has-issues';

            html.push('<div class="' + cls + '" data-date="' + esc(dateKey) + '" role="button" tabindex="0">');
            html.push('<div class="calendar-day-head"><span class="calendar-day-num">' + dayNum + '</span>');
            if (dayItems.length > 0) {
                html.push('<span class="badge bg-warning text-dark rounded-pill" style="font-size:.65rem;">' + dayItems.length + '</span>');
            } else {
                html.push('<span class="calendar-day-add">+</span>');
            }
            html.push('</div><div class="event-list">');
            const showMax = 2;
            dayItems.slice(0, showMax).forEach(function (item) {
                const label = String(item.project_name || item.report_no || 'DSR').trim();
                const chipCls = String(item.issues_remarks || '').trim() !== '' ? ' event-chip-danger' : '';
                html.push('<span class="event-chip' + chipCls + '" title="' + esc(label) + '">' + esc(label) + '</span>');
            });
            if (dayItems.length > showMax) {
                html.push('<span class="event-more">+' + (dayItems.length - showMax) + ' รายการ</span>');
            }
            html.push('</div></div>');
        }

        elCalendarGrid.innerHTML = html.join('');
        if (elCalendarTitle) {
            elCalendarTitle.textContent = monthNames[currentMonth] + ' ' + (currentYear + 543);
        }
        if (elCalendarCountLabel) {
            const monthCount = reportsInCurrentMonth().length;
            elCalendarCountLabel.textContent = monthCount + ' รายงานในเดือนนี้';
        }

        elCalendarGrid.querySelectorAll('.calendar-day[data-date]').forEach(function (cell) {
            const dateKey = cell.getAttribute('data-date') || '';
            const dayItems = mapByDate[dateKey] || [];
            function activate() { openDay(dateKey, dayItems); }
            cell.addEventListener('click', activate);
            cell.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activate(); }
            });
        });
    }

    const hasIssuesEl = document.getElementById('dsrFormHasIssues');
    if (hasIssuesEl) hasIssuesEl.addEventListener('change', syncIssuesState);

    const fileInput = document.getElementById('dsrFormPhotosNew');
    const capContainer = document.getElementById('dsrFormNewCaptions');
    if (fileInput && capContainer) {
        fileInput.addEventListener('change', function () {
            capContainer.innerHTML = '';
            const n = this.files ? this.files.length : 0;
            const maxP = Number(this.dataset.maxPhotos || '2');
            const existing = Number(this.dataset.existingPhotos || '0');
            const slots = Math.max(0, maxP - existing);
            if (n > slots) {
                alert('อัปโหลดได้ไม่เกิน ' + maxP + ' รูป (เหลือเพิ่มได้ ' + slots + ' รูป)');
                this.value = '';
                return;
            }
            if (n <= 1) return;
            const lab = document.createElement('div');
            lab.className = 'small fw-semibold text-muted mb-2 mt-2';
            lab.textContent = 'คำอธิบายแต่ละรูป';
            capContainer.appendChild(lab);
            for (let i = 0; i < n; i++) {
                const wrap = document.createElement('div');
                wrap.className = 'input-group input-group-sm mb-1';
                wrap.innerHTML = '<span class="input-group-text">' + (i + 1) + '</span><input type="text" class="form-control" name="photo_caption_new[]" placeholder="คำอธิบาย">';
                capContainer.appendChild(wrap);
            }
        });
    }

    function goToToday() {
        currentYear = today.getFullYear();
        currentMonth = today.getMonth();
        renderCalendar();
    }

    window.dsrCalendarRender = renderCalendar;
    window.dsrCalendarGetMonth = function () { return { year: currentYear, month: currentMonth }; };
    window.dsrCalendarSetMonth = function (y, m) { currentYear = y; currentMonth = m; renderCalendar(); };
    window.dsrCalendarShiftMonth = shiftMonth;
    window.dsrCalendarGoToday = goToToday;

    function bindMonthNav(btn, handler) {
        if (!btn) return;
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            handler();
        });
    }

    bindMonthNav(btnPrevMonth, function () { shiftMonth(-1); });
    bindMonthNav(btnNextMonth, function () { shiftMonth(1); });
    bindMonthNav(btnToday, goToToday);

    document.addEventListener('click', function (e) {
        const navBtn = e.target.closest('[data-dsr-month]');
        if (!navBtn) return;
        const action = String(navBtn.getAttribute('data-dsr-month') || '');
        if (action === 'prev') shiftMonth(-1);
        else if (action === 'next') shiftMonth(1);
        else if (action === 'today') goToToday();
    });

    renderWeekdayRow();
    renderCalendar();

    const openParams = new URLSearchParams(window.location.search);
    const openId = Number(openParams.get('open_id') || '0');
    if (openId > 0) {
        const foundOpen = reports.find(function (r) { return Number(r.id || 0) === openId; });
        if (foundOpen) {
            const dateKey = String(foundOpen.report_date || '').trim();
            if (dateKey) {
                const dtOpen = new Date(dateKey + 'T00:00:00');
                if (!Number.isNaN(dtOpen.getTime())) {
                    currentYear = dtOpen.getFullYear();
                    currentMonth = dtOpen.getMonth();
                    renderCalendar();
                }
                setTimeout(function () { openFormModal(dateKey, foundOpen); }, 200);
            }
        }
    }
})();
