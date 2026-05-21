<?php
/** Modal เลือกรายการ / ฟอร์มบันทึก DSR จากปฏิทิน (include จากหน้าปฏิทิน) */
?>
<div class="modal fade" id="dsrDayPickModal" tabindex="-1" aria-labelledby="dsrDayPickModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="dsrDayPickModalLabel">เลือกรายการ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body pt-2">
                <p class="text-muted small mb-3" id="dsrDayPickDateLabel"></p>
                <div id="dsrDayPickList" class="d-grid gap-2"></div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-warning text-dark fw-bold rounded-pill w-100" id="dsrDayPickCreateBtn">
                    <i class="bi bi-plus-lg me-1"></i>สร้างรายงานใหม่ในวันนี้
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="dsrFormModal" tabindex="-1" aria-labelledby="dsrFormModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header bg-warning-subtle">
                <div>
                    <h5 class="modal-title fw-bold mb-0" id="dsrFormModalLabel">บันทึกรายงานหน้างาน</h5>
                    <div class="small text-muted" id="dsrFormModalDateLabel"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <form method="post" action="<?= htmlspecialchars($dsrFormSaveUrl, ENT_QUOTES, 'UTF-8') ?>" enctype="multipart/form-data" id="dsrCalendarForm">
                <?php csrf_field(); ?>
                <input type="hidden" name="return_to" value="calendar">
                <input type="hidden" name="action" id="dsrFormAction" value="create">
                <input type="hidden" name="id" id="dsrFormId" value="">
                <div class="modal-body">
                    <div id="dsrFormReportNoWrap" class="alert alert-light border small py-2 d-none mb-3">
                        เลขที่เอกสาร: <strong id="dsrFormReportNo"></strong>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">วันที่รายงาน <span class="text-danger">*</span></label>
                            <input type="date" name="report_date" id="dsrFormReportDate" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">สภาพอากาศ</label>
                            <input type="text" name="weather" id="dsrFormWeather" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">บริษัท <span class="text-danger">*</span></label>
                            <select name="company_id" id="dsrFormCompany" class="form-select form-select-sm" required>
                                <option value="" disabled selected>— เลือกบริษัท —</option>
                                <?php foreach ($dsrFormCompanies as $co): ?>
                                    <option value="<?= (int) ($co['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($co['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">ไซต์งาน</label>
                            <select name="site_name" id="dsrFormSite" class="form-select form-select-sm">
                                <option value="">— เลือกไซต์งาน —</option>
                                <?php foreach ($dsrFormSiteOptions as $site): ?>
                                    <option value="<?= htmlspecialchars($site, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($site, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">โครงการ <span class="text-danger">*</span></label>
                            <select name="project_name" id="dsrFormProject" class="form-select form-select-sm" required>
                                <option value="" disabled selected>— เลือกโครงการ —</option>
                                <?php foreach ($dsrFormProjectOptions as $proj): ?>
                                    <option value="<?= htmlspecialchars($proj, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($proj, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">คนงาน</label>
                            <input type="text" name="worker_count" id="dsrFormWorkers" class="form-control form-control-sm" inputmode="numeric" placeholder="เช่น 12">
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <label class="form-label fw-semibold small text-warning-emphasis">รายละเอียดงานที่ทำ</label>
                            <textarea name="work_progress" id="dsrFormWorkProgress" class="form-control form-control-sm" rows="5"></textarea>
                        </div>
                        <div class="col-lg-6">
                            <label class="form-label fw-semibold small text-warning-emphasis">วัสดุและเครื่องจักร</label>
                            <textarea name="materials_equipment" id="dsrFormMaterials" class="form-control form-control-sm" rows="5"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small text-warning-emphasis">ปัญหาและอุปสรรค</label>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" value="1" id="dsrFormHasIssues" name="has_issues">
                                <label class="form-check-label small" for="dsrFormHasIssues">พบปัญหา/อุปสรรคในวันนี้</label>
                            </div>
                            <textarea name="issues_remarks" id="dsrFormIssues" class="form-control form-control-sm" rows="3" disabled></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small text-warning-emphasis">รูปภาพประกอบ</label>
                            <div id="dsrFormExistingPhotos" class="row g-2 mb-2"></div>
                            <input type="file" name="photos_new[]" id="dsrFormPhotosNew" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
                            <p class="text-muted mt-1 mb-0" style="font-size:.78rem;">อัปโหลดได้สูงสุด <?= (int) $dsrFormMaxPhotos ?> รูป</p>
                            <div id="dsrFormNewCaptions"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="button" class="btn btn-outline-danger rounded-pill d-none" id="dsrFormDeleteBtn">ลบรายงาน</button>
                    <button type="submit" class="btn btn-warning text-dark fw-bold rounded-pill px-4 ms-auto">
                        <i class="bi bi-check2-circle me-1"></i>บันทึก
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
