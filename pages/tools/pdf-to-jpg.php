<?php

declare(strict_types=1);


session_start();
require_once dirname(__DIR__, 2) . '/config/connect_database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . app_path('sign-in.php'));
    exit;
}

$errorMessage = '';
$successMessage = '';
$downloadUrls = [];
$processedCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify_request()) {
        $errorMessage = 'โทเค็นไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองใหม่อีกครั้ง';
    } elseif (!class_exists(Imagick::class)) {
        $errorMessage = 'เซิร์ฟเวอร์ยังไม่รองรับ Imagick จึงยังแปลงไฟล์ PDF ไม่ได้';
    } elseif (!isset($_FILES['pdf_file']) || (int) ($_FILES['pdf_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errorMessage = 'กรุณาเลือกไฟล์ PDF ที่ต้องการแปลง';
    } else {
        $tmpPath = (string) ($_FILES['pdf_file']['tmp_name'] ?? '');
        $originalName = trim((string) ($_FILES['pdf_file']['name'] ?? 'document.pdf'));
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            $errorMessage = 'รองรับเฉพาะไฟล์ .pdf เท่านั้น';
        } else {
            $jobId = 'pdfjpg_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
            $jobDirRel = 'uploads/pdf-to-jpg/' . $jobId;
            $jobDirAbs = ROOT_PATH . '/' . $jobDirRel;
            if (!is_dir($jobDirAbs) && !@mkdir($jobDirAbs, 0775, true) && !is_dir($jobDirAbs)) {
                $errorMessage = 'ไม่สามารถสร้างโฟลเดอร์ปลายทางได้';
            } else {
                $pdfAbs = $jobDirAbs . '/source.pdf';
                if (!@move_uploaded_file($tmpPath, $pdfAbs)) {
                    $errorMessage = 'อัปโหลดไฟล์ไม่สำเร็จ';
                } else {
                    $dpi = 180;
                    $jpegQuality = 85;

                    try {
                        $imagick = new Imagick();
                        $imagick->setResolution($dpi, $dpi);
                        $imagick->readImage($pdfAbs);
                        $index = 0;
                        foreach ($imagick as $page) {
                            $index++;
                            $page->setImageBackgroundColor('white');
                            $flattened = $page->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                            $flattened->setImageFormat('jpeg');
                            $flattened->setImageCompressionQuality($jpegQuality);
                            $jpgName = 'page-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT) . '.jpg';
                            $flattened->writeImage($jobDirAbs . '/' . $jpgName);
                            $processedCount++;
                            $flattened->clear();
                            $flattened->destroy();
                        }
                        $imagick->clear();
                        $imagick->destroy();
                    } catch (Throwable $e) {
                        $errorMessage = 'แปลงไฟล์ไม่สำเร็จ กรุณาลองไฟล์อื่นอีกครั้ง';
                    }

                    if ($errorMessage === '' && $processedCount > 0) {
                        foreach (glob($jobDirAbs . '/*.jpg') ?: [] as $jpgFile) {
                            $downloadUrls[] = [
                                'name' => basename($jpgFile),
                                'url' => app_path($jobDirRel . '/' . basename($jpgFile)),
                            ];
                        }
                        $successMessage = 'แปลงสำเร็จ ' . number_format($processedCount) . ' หน้า';
                    } elseif ($errorMessage === '') {
                        $errorMessage = 'ไม่พบหน้าที่สามารถแปลงได้ในไฟล์ PDF';
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF to JPG | THEELIN CON</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        body { background: #f6f8fb; font-family: 'Sarabun', sans-serif; }
        .tool-card { border: 0; border-radius: 16px; box-shadow: 0 10px 26px rgba(15, 23, 42, 0.08); }
        .tool-head { border-radius: 16px 16px 0 0; background: linear-gradient(135deg, #0d6efd 0%, #3b82f6 100%); color: #fff; }
    </style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/components/navbar.php'; ?>

<div class="container py-4 py-lg-5">
    <div class="card tool-card">
        <div class="tool-head px-4 py-3">
            <h4 class="mb-1 fw-bold"><i class="bi bi-filetype-pdf me-2"></i>PDF to JPG</h4>
            <div class="small text-white-50">แปลงทุกหน้าในไฟล์ PDF เป็นภาพ JPG และดาวน์โหลดไฟล์ JPG ได้ทันที</div>
        </div>
        <div class="card-body p-4">
            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success">
                    <span><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if (count($downloadUrls) > 0): ?>
                        <div class="mt-3 d-flex flex-wrap gap-2">
                            <?php foreach ($downloadUrls as $file): ?>
                                <a class="btn btn-success btn-sm rounded-pill px-3" href="<?= htmlspecialchars((string) $file['url'], ENT_QUOTES, 'UTF-8') ?>" download>
                                    <i class="bi bi-download me-1"></i><?= htmlspecialchars((string) $file['name'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="row g-3">
                <?php csrf_field(); ?>
                <div class="col-12">
                    <label class="form-label fw-bold">เลือกไฟล์ PDF</label>
                    <input type="file" class="form-control" name="pdf_file" accept=".pdf,application/pdf" required>
                </div>
                <div class="col-12">
                    <div class="alert alert-info mb-0">
                        ระบบนี้รองรับการแปลงเฉพาะ <strong>PDF to JPG</strong> เท่านั้น
                    </div>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary rounded-pill px-4"><i class="bi bi-arrow-repeat me-1"></i>แปลงไฟล์</button>
                    <a href="<?= htmlspecialchars(app_path('index.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary rounded-pill px-4">กลับหน้าแรก</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
