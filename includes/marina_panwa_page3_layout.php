<?php

declare(strict_types=1);

/**
 * ข้อมูลมิติจากแผน PONTOON LAYOUT PLAN — PANWA MARINA (หน้า page3 / scale 1:1000)
 * แยกตาม Legend สีในแผน + จัดกลุ่มตามโซน MARINA A1–A4 ที่มีป้ายในแผน
 * หมายเหตุ: ผลรวมความยาวของแต่ละป้าย ≠ พื้นที่ และอาจมีขอบที่ใช้ร่วมกัน — ใช้เป็นรายการอ้างอิงจากแบบเท่านั้น
 */
function marina_panwa_page3_layout(): array
{
    return [
        'document_title' => 'PONTOON LAYOUT PLAN — PANWA MARINA',
        'drawing_ref' => 'page3.pdf',
        'scale' => '1:1000',
        'length_unit' => 'm',
        'zones' => [
            'orange_sf' => [
                'label_th' => 'โซนสีส้ม — SF Pontoons (โครงหลัก)',
                'color_hint' => '#fd7e14',
                'groups' => [
                    [
                        'id' => 'sf_bottom_outer',
                        'label_th' => 'แนวด้านล่างนอก (โซนหลักต่อเนื่อง)',
                        'segments_m' => [48.720, 122.500, 121.882, 122.500],
                    ],
                    [
                        'id' => 'sf_internal',
                        'label_th' => 'แนวภายใน / เชื่อมหลัก (มีป้ายในแผน)',
                        'segments_m' => [104.030, 141.008, 56.075, 59.268, 60.234, 38.935, 15.172, 121.500, 67.994, 72.000, 46.000],
                    ],
                    [
                        'id' => 'sf_small_module_widths',
                        'label_th' => 'ความกว้างชิ้นเล็ก (ป้ายบนแผน — มักเป็นขนาดโมดูล)',
                        'segments_m' => [5.000, 4.000, 3.000, 2.000],
                    ],
                ],
            ],
            'yellow_msi' => [
                'label_th' => 'โซนสีเหลือง — MSI Pontoons (finger / slip)',
                'color_hint' => '#ffc107',
                'groups' => [
                    ['id' => 'msi_a1', 'label_th' => 'MARINA A1', 'segments_m' => [15.000, 20.000, 15.000]],
                    ['id' => 'msi_a2', 'label_th' => 'MARINA A2', 'segments_m' => [15.000, 12.000]],
                    ['id' => 'msi_center', 'label_th' => 'โซนกลางแผน', 'segments_m' => [25.000, 26.000]],
                    ['id' => 'msi_top', 'label_th' => 'แนวด้านบน', 'segments_m' => [18.000, 26.000, 30.000, 25.000, 35.000]],
                    ['id' => 'msi_a4', 'label_th' => 'MARINA A4', 'segments_m' => [35.000, 35.000, 35.000]],
                ],
            ],
            'grey_wave' => [
                'label_th' => 'โซนสีเทา — Wave screen (แนวกันคลื่น)',
                'color_hint' => '#6c757d',
                'groups' => [
                    [
                        'id' => 'wave_outer',
                        'label_th' => 'แนวรอบนอกตามที่มีป้ายในแผน',
                        'segments_m' => [34.235, 24.625, 48.720, 122.500, 121.882, 122.500, 81.828],
                    ],
                ],
            ],
        ],
        'annotations' => [
            [
                'label_th' => 'Fuel dock (โซนบริการ)',
                'note_th' => 'มิติประมาณ 60.000 × 45.000 m (จากแผน)',
            ],
        ],
    ];
}

/** @param list<float> $segments */
function marina_sum_segments(array $segments): float
{
    $s = 0.0;
    foreach ($segments as $v) {
        $s += (float) $v;
    }

    return round($s, 3);
}
