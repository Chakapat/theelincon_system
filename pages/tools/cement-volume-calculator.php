<?php

session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>คำนวณปริมาตรปูน / เทพื้น / เทเสา (คิว) | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #fffaf5; }
        .calc-card {
            border: none;
            border-radius: 1rem;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.06);
        }
        .result-panel {
            background: linear-gradient(135deg, rgba(253, 126, 20, 0.08) 0%, rgba(255, 146, 43, 0.12) 100%);
            border: 1px solid rgba(253, 126, 20, 0.2);
            border-radius: 0.85rem;
        }
        .formula-box {
            font-size: 0.9rem;
            border-left: 4px solid #fd7e14;
            padding-left: 1rem;
        }
    </style>
</head>
<body>

<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4 pb-5">
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="card calc-card mb-4">
                <div class="card-body p-4">
                    <h4 class="fw-bold mb-1"><i class="bi bi-columns-gap text-warning me-2"></i>คำนวณปริมาตรคอนกรีต (คิว)</h4>
                    <ul class="nav nav-pills gap-2 mb-4 flex-wrap" id="calcTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active rounded-pill px-4" id="pillar-tab" data-bs-toggle="pill" data-bs-target="#pillar-pane" type="button" role="tab" aria-controls="pillar-pane" aria-selected="true">ปูนเทเสา</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link rounded-pill px-4" id="floor-tab" data-bs-toggle="pill" data-bs-target="#floor-pane" type="button" role="tab" aria-controls="floor-pane" aria-selected="false">ปูนเทพื้น</button>
                        </li>
                    </ul>

                    <div class="tab-content" id="calcTabContent">
                        <div class="tab-pane fade show active" id="pillar-pane" role="tabpanel" aria-labelledby="pillar-tab" tabindex="0">
                            <div class="alert alert-light border small mb-4">
                                <strong>สูตร (ปูนเทเสา)</strong>
                                <div class="formula-box mt-2 mb-0 text-secondary">
                                    พื้นที่หน้าตัดเสา <em>A</em> (ตร.ม.) = กว้าง<sub>ม.</sub> × ยาว<sub>ม.</sub><br>
                                    ปริมาตรที่ใช้ <em>V</em> (ลบ.ม./คิว) = <em>A</em> × ความสูง<sub>ม.</sub> × จำนวนเสา<br>
                                    ปริมาตรสั่งซื้อ (ก่อนปัดขึ้น) = <em>V</em> × (1 + เปอร์เซ็นต์สำรอง ÷ 100)
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-4 col-6">
                                    <label for="p_w_m" class="form-label fw-semibold">ด้านกว้างหน้าตัด (ม.)</label>
                                    <input type="number" class="form-control form-control-lg pillar-inp" id="p_w_m" min="0" step="0.001" placeholder="0" inputmode="decimal">
                                </div>
                                <div class="col-md-4 col-6">
                                    <label for="p_l_m" class="form-label fw-semibold">ด้านยาวหน้าตัด (ม.)</label>
                                    <input type="number" class="form-control form-control-lg pillar-inp" id="p_l_m" min="0" step="0.001" placeholder="0" inputmode="decimal">
                                </div>
                                <div class="col-md-4">
                                    <label for="p_h_m" class="form-label fw-semibold">ความสูงเสา (ม.)</label>
                                    <input type="number" class="form-control form-control-lg pillar-inp" id="p_h_m" min="0" step="0.001" placeholder="0" inputmode="decimal">
                                </div>
                                <div class="col-md-4 col-6">
                                    <label for="p_n" class="form-label fw-semibold">จำนวนเสา (ต้น)</label>
                                    <input type="number" class="form-control form-control-lg pillar-inp" id="p_n" min="0" step="1" placeholder="0" inputmode="numeric">
                                </div>
                                <div class="col-md-4 col-6">
                                    <label for="p_spare" class="form-label fw-semibold">คอนกรีตสำรอง (%)</label>
                                    <input type="number" class="form-control form-control-lg pillar-inp" id="p_spare" min="0" step="1" value="10" placeholder="10" title="แนะนำเผื่อ 5–10%">
                                </div>
                            </div>

                            <div class="result-panel p-4 mb-2">
                                <div class="text-muted small mb-2">พื้นที่หน้าตัดเสา <em>A</em></div>
                                <div class="fs-5 fw-semibold mb-3"><span id="p_area_m2">0.0000</span> ตร.ม.</div>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="text-muted small mb-1">ปริมาตรคอนกรีตที่ต้องใช้</div>
                                        <div class="fs-3 fw-bold text-dark"><span id="p_vol_need">0.000</span> <span class="fs-6 fw-semibold text-secondary">คิว (ลบ.ม.)</span></div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-muted small mb-1">ปริมาตรหลังเผื่อสำรอง (ยังไม่ปัดขึ้น)</div>
                                        <div class="fs-4 fw-bold" style="color: #e8590c;"><span id="p_vol_spare_raw">0.000</span> <span class="fs-6">คิว</span></div>
                                    </div>
                                </div>
                                <hr class="my-3 opacity-50">
                                <div class="text-muted small mb-1">จำนวนคอนกรีตที่แนะนำให้สั่งซื้อ (หลักปัดเศษ &gt; 0.5 ปัดขึ้น)</div>
                                <div class="display-6 fw-bold text-success"><span id="p_vol_order_ceil">0</span> <span class="fs-4">คิว</span></div>
                                <p class="small text-secondary mb-0 mt-3"><i class="bi bi-info-circle me-1"></i>ปัดเศษเฉพาะเมื่อส่วนทศนิยมมากกว่า 0.5 (เช่น 3.51 ปัดเป็น 4, 3.50 คงเป็น 3)</p>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="floor-pane" role="tabpanel" aria-labelledby="floor-tab" tabindex="0">
                            <div class="alert alert-light border small mb-4">
                                <strong>สูตร (ปูนเทพื้น)</strong> — หน่วยตาม <a href="https://aycontento.com/blog/floor-pouring-cement-calculation-program/" target="_blank" rel="noopener noreferrer">บทความอ้างอิง</a>
                                <div class="formula-box mt-2 mb-0 text-secondary">
                                    ปริมาตรคอนกรีต (ลบ.ม./คิว) = ความกว้าง<sub>ม.</sub> × ความยาว<sub>ม.</sub> × ความหนา<sub>ม.</sub><br>
                                    ปริมาตรที่ควรสั่ง (ก่อนปัดขึ้น) = ปริมาตร × (1 + เปอร์เซ็นต์สำรอง ÷ 100)
                                </div>
                                <p class="mb-0 mt-2 small text-secondary">โหมดนี้คิดหน่วยเมตรทั้งหมด (m × m × m)</p>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label for="f_w_m" class="form-label fw-semibold">ความกว้างพื้น (ม.)</label>
                                    <input type="number" class="form-control form-control-lg floor-inp" id="f_w_m" min="0" step="0.001" placeholder="0" inputmode="decimal">
                                </div>
                                <div class="col-md-4">
                                    <label for="f_l_m" class="form-label fw-semibold">ความยาวพื้น (ม.)</label>
                                    <input type="number" class="form-control form-control-lg floor-inp" id="f_l_m" min="0" step="0.001" placeholder="0" inputmode="decimal">
                                </div>
                                <div class="col-md-4">
                                    <label for="f_t_m" class="form-label fw-semibold">ความหนาพื้น (ม.)</label>
                                    <input type="number" class="form-control form-control-lg floor-inp" id="f_t_m" min="0" step="0.001" placeholder="0" inputmode="decimal">
                                </div>
                                <div class="col-md-4 col-6">
                                    <label for="f_spare" class="form-label fw-semibold">คอนกรีตสำรอง (%)</label>
                                    <input type="number" class="form-control form-control-lg floor-inp" id="f_spare" min="0" step="1" value="10" placeholder="10" title="แนะนำเผื่อ 5–10%">
                                </div>
                            </div>

                            <div class="result-panel p-4 mb-2">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="text-muted small mb-1">ปริมาตรคอนกรีตที่ต้องใช้</div>
                                        <div class="fs-3 fw-bold text-dark"><span id="f_vol_need">0.000</span> <span class="fs-6 fw-semibold text-secondary">คิว (ลบ.ม.)</span></div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="text-muted small mb-1">ปริมาตรหลังเผื่อสำรอง (ยังไม่ปัดขึ้น)</div>
                                        <div class="fs-4 fw-bold" style="color: #e8590c;"><span id="f_vol_spare_raw">0.000</span> <span class="fs-6">คิว</span></div>
                                    </div>
                                </div>
                                <hr class="my-3 opacity-50">
                                <div class="text-muted small mb-1">ปริมาตรที่ควรสั่งซื้อ (หลักปัดเศษ &gt; 0.5 ปัดขึ้น)</div>
                                <div class="display-6 fw-bold text-success"><span id="f_vol_order_ceil">0</span> <span class="fs-4">คิว</span></div>
                                <p class="small text-secondary mb-0 mt-3"><i class="bi bi-info-circle me-1"></i>โปรแกรมช่วยประมาณการเบื้องต้น — ปริมาณจริงอาจเปลี่ยนตามหน้างานและวิธีเท (ตามคำเตือนในบทความต้นทาง)</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const fmt3 = function (n) {
        return Number(n).toLocaleString('th-TH', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
    };
    const fmt4 = function (n) {
        return Number(n).toLocaleString('th-TH', { minimumFractionDigits: 4, maximumFractionDigits: 4 });
    };

    function parseNonNeg(el, fallback) {
        const v = parseFloat(String(el.value).replace(',', '.'));
        if (!Number.isFinite(v) || v < 0) return fallback;
        return v;
    }

    function parseNonNegInt(el, fallback) {
        const v = parseFloat(String(el.value).replace(',', '.'));
        if (!Number.isFinite(v) || v < 0) return fallback;
        return Math.floor(v);
    }

    function roundUpOnlyWhenGtPointFive(v) {
        if (!Number.isFinite(v) || v <= 0) return 0;
        const base = Math.floor(v);
        const frac = v - base;
        return frac > 0.5 ? base + 1 : base;
    }

    /* --- Pillar (สูตร aycontento) --- */
    const pW = document.getElementById('p_w_m');
    const pL = document.getElementById('p_l_m');
    const pH = document.getElementById('p_h_m');
    const pN = document.getElementById('p_n');
    const pSpare = document.getElementById('p_spare');
    const outArea = document.getElementById('p_area_m2');
    const outNeed = document.getElementById('p_vol_need');
    const outSpareRaw = document.getElementById('p_vol_spare_raw');
    const outOrderCeil = document.getElementById('p_vol_order_ceil');

    function recalcPillar() {
        const Wm = parseNonNeg(pW, 0);
        const Lm = parseNonNeg(pL, 0);
        const Hm = parseNonNeg(pH, 0);
        const N = parseNonNegInt(pN, 0);
        const sparePct = parseNonNeg(pSpare, 0);

        const A = Wm * Lm;
        const V = A * Hm * N;
        const Vspare = V * (1 + sparePct / 100);
        const Vceil = roundUpOnlyWhenGtPointFive(Vspare);

        outArea.textContent = fmt4(A);
        outNeed.textContent = fmt3(V);
        outSpareRaw.textContent = fmt3(Vspare);
        outOrderCeil.textContent = String(Vceil);
    }

    document.querySelectorAll('.pillar-inp').forEach(function (el) {
        el.addEventListener('input', recalcPillar);
        el.addEventListener('change', recalcPillar);
    });

    /* --- Floor เทพื้น: V = W×L×Tm --- */
    const fW = document.getElementById('f_w_m');
    const fL = document.getElementById('f_l_m');
    const fT = document.getElementById('f_t_m');
    const fSpare = document.getElementById('f_spare');
    const fOutNeed = document.getElementById('f_vol_need');
    const fOutSpareRaw = document.getElementById('f_vol_spare_raw');
    const fOutCeil = document.getElementById('f_vol_order_ceil');

    function recalcFloor() {
        const W = parseNonNeg(fW, 0);
        const L = parseNonNeg(fL, 0);
        const Tm = parseNonNeg(fT, 0);
        const sparePct = parseNonNeg(fSpare, 0);

        const V = W * L * Tm;
        const Vspare = V * (1 + sparePct / 100);
        const Vceil = roundUpOnlyWhenGtPointFive(Vspare);

        fOutNeed.textContent = fmt3(V);
        fOutSpareRaw.textContent = fmt3(Vspare);
        fOutCeil.textContent = String(Vceil);
    }

    document.querySelectorAll('.floor-inp').forEach(function (el) {
        el.addEventListener('input', recalcFloor);
        el.addEventListener('change', recalcFloor);
    });

    recalcPillar();
    recalcFloor();

    document.getElementById('pillar-tab').addEventListener('shown.bs.tab', recalcPillar);
    document.getElementById('floor-tab').addEventListener('shown.bs.tab', recalcFloor);
})();
</script>
</body>
</html>
