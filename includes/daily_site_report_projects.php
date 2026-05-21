<?php

declare(strict_types=1);

/** URL หน้าหลักสมุดรายวันหน้างาน (ปฏิทิน) */
function daily_site_report_hub_url(): string
{
    return app_path('pages/daily-site-reports/daily-site-report-calendar.php');
}

/** ชื่อโครงการที่เลือกได้ในรายงานหน้างาน */
function daily_site_report_project_options(): array
{
    return [
        'Gardens of eden',
        'Kmit - Pool',
        'Kmit - The modeva',
        'Kmit - SOLEMIO',
        'Heli pad',
    ];
}
