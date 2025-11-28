<?php
// ต้องมีไฟล์ db.php สำหรับเชื่อมต่อฐานข้อมูล
require_once __DIR__ . '/db.php';

// Helper to sanitize output
function e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// Fetch rows and group by status
// ในหน้านี้เราไม่ต้องการ POST handler เพราะไม่มีการเพิ่ม/แก้ไข/ลบ
$allRows = $pdo->query("
    SELECT date_in, name, surname, ward, hospital, o2_ett_icd, partner, note,
           time_contact AS contact_time, status
    FROM `{$tableName}`
    ORDER BY status ASC, date_in DESC, id DESC
")->fetchAll();

// Group rows by status
$groupedRows = [
    1 => [], // รอรถเข้ารับ
    2 => [], // รถกำลังมารับ
    3 => []  // บุรีรัมย์ไปส่ง
];

foreach ($allRows as $row) {
    $status = (int)$row['status'];
    if (isset($groupedRows[$status])) {
        $groupedRows[$status][] = $row;
    }
}

$statusLabels = [
    1 => 'รอรถเข้ารับ',
    2 => 'รถกำลังมารับ',
    3 => 'บุรีรัมย์ไปส่ง'
];

// Fetch hospital zipcodes for search helper
$zipcodeRows = $pdo->query("
    SELECT hospital_name, zipcode
    FROM hospital_zipcodes
    WHERE zipcode IS NOT NULL AND zipcode <> ''
")->fetchAll();

$zipcodeMap = [];
foreach ($zipcodeRows as $zipRow) {
    $zip = trim($zipRow['zipcode']);
    $hospitalName = trim($zipRow['hospital_name']);
    if ($zip === '' || $hospitalName === '') {
        continue;
    }
    if (!isset($zipcodeMap[$zip])) {
        $zipcodeMap[$zip] = [];
    }
    $zipcodeMap[$zip][] = $hospitalName;
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        /* สไตล์หลัก */
        * {
            box-sizing: border-box;
        }

        :root{
            --page-bg: #d8eefe; /* requested background color */
            --panel-bg: #ffffff;
            --accent: #2d9bf7; /* bright blue */
            --accent-dark: #0b6ecf;
            --muted: #6b7280;
        }

        body {
            font-family: "Prompt", Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: var(--page-bg);
            min-height: 100vh;
            color: #0f1720;
            font-size: 14px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: var(--panel-bg);
            border-radius: 12px;
            padding: 28px;
            box-shadow: 0 8px 30px rgba(2,6,23,0.06);
        }

        h2 {
            margin-bottom: 20px;
            color: var(--accent-dark);
            font-weight: 700;
            font-size: 28px;
            text-align: center;
            margin-top: 0;
        }

    
        /* Header layout: images on both sides, title centered */
        .page-header { display: flex; align-items: center; justify-content: center; gap: 30px; margin-bottom: 18px; }
        .page-header h2 { text-align: center; margin: 0; flex: 0 1 auto; }
        .doctor-img, .ambulance-img { max-width: 140px; height: auto; display: block; flex: 0 0 auto; }

        @media (max-width: 768px) {
            .page-header { flex-direction: column; align-items: center; gap: 15px; }
            .page-header h2 { text-align: center; }
            .doctor-img, .ambulance-img { margin: 0; }
        }
        /* Toolbar */
        .toolbar {
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        /* ช่องค้นหาและ Dropdown */
        #hospitalSearch, #statusFilter {
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            background: white;
            font-family: "Prompt", Arial, sans-serif;
        }

        /* suggestion box removed - plain search input now */

        #hospitalSearch:focus, #statusFilter:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 6px 18px rgba(45,155,247,0.12);
            transform: translateY(-1px);
        }

        #statusFilter {
            min-width: 200px;
            cursor: pointer;
        }

        /* Table */
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            animation: slideUp 0.5s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        th {
           background: linear-gradient(135deg,  #55423d 0%, #6d554eff 100%);
            color: #fff;
            padding: 15px;
            font-weight: 600;
            text-align: left;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        th:first-child {
            border-top-left-radius: 12px;
        }

        th:last-child {
            border-top-right-radius: 12px;
        }

        /* Group Header Styles */
        tr.group-header {
            background: transparent;
        }

        tr.group-header td.group-header-cell {
            background: linear-gradient(135deg, #44ecdc 0%, #44a08d 100%);
            color: #fff;
            font-size: 16px;
            font-weight: 700;
            padding: 15px 20px;
            text-align: left;
            border-bottom: 3px solid rgba(255, 255, 255, 0.3);
            vertical-align: middle;
        }
        tr.group-header.status-group-1 td.group-header-cell {
            background: linear-gradient(135deg, #3da9fc 0%, #3e8deeff 100%);
        }
ถ้า
        tr.group-header.status-group-2 td.group-header-cell {
            background: linear-gradient(135deg, #fda81fff 0%, #fda81fff 100%);
        }

        tr.group-header.status-group-3 td.group-header-cell {
            background: linear-gradient(135deg, #06ba5dff 0%, #06ba5dff 100%);
        }
        .group-count {
            font-size: 14px;
            font-weight: 400;
            opacity: 0.9;
            margin-left: 10px;
        }

        td {
            padding: 15px;
            font-size: 14px;
            border-bottom: 1px solid #f0f0f0;
            background: #fff;
            vertical-align: top;
            transition: all 0.2s ease;
        }

        tr:last-child td {
            border-bottom: none;
        }
        
        tr:hover td {
            background: linear-gradient(90deg, #e8faf9 0%, #fff 100%);
            transform: scale(1.01);
            box-shadow: 0 2px 8px rgba(78, 205, 196, 0.1);
        }

        /* Status Colors (ใช้สีเดิม) */
        .status-1 {
            color: #1f7aed;
            font-weight: 600;
            padding: 4px 12px;
            background: rgba(31, 122, 237, 0.1);
            border-radius: 20px;
            display: inline-block;
        }
        .status-2 {
            color: #f39c12;
            font-weight: 600;
            padding: 4px 12px;
            background: rgba(243, 156, 18, 0.1);
            border-radius: 20px;
            display: inline-block;
        }
        .status-3 {
            color: #00a651;
            font-weight: 600;
            padding: 4px 12px;
            background: rgba(0, 166, 81, 0.1);
            border-radius: 20px;
            display: inline-block;
        }
        
        /* Responsive Design - Card Layout for Mobile */
        @media (max-width: 768px) {
            body {
                padding: 10px;
                background: #f5f5f5;
            }

            .container {
                padding: 15px;
                border-radius: 12px;
                background: #f5f5f5;
            }

            h2 {
                font-size: 22px;
                margin-bottom: 15px;
            }
            
            /* Toolbar Stacked on mobile */
            .toolbar {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                margin-bottom: 20px;
            }

            #hospitalSearch, #statusFilter {
                width: 100%;
                box-sizing: border-box;
                padding: 12px 14px;
                font-size: 15px;
            }

            /* Table Responsive setup - Hide table structure */
            table, thead, tbody, th, td, tr { 
                display: block;
                width: 100%;
            }

            table {
                background: transparent;
                box-shadow: none;
                border-radius: 0;
            }

            thead tr {
                position: absolute;
                top: -9999px;
                left: -9999px;
                height: 0;
                width: 0;
            }

            /* Group header styling for mobile */
            tr.group-header {
                display: block;
                background: transparent;
                border: none;
                margin: 20px 0 12px 0;
                padding: 0;
                box-shadow: none;
            }

            tr.group-header td.group-header-cell {
                padding: 12px 15px;
                border-radius: 8px;
                font-size: 15px;
                text-align: left;
                font-weight: 700;
                margin: 0;
            }

            /* Card styling for data rows on mobile */
            tbody tr {
                display: block;
                border: none;
                margin-bottom: 15px;
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 2px 12px rgba(0,0,0,0.1);
                background: white;
                padding: 0;
            }
            
            tbody tr:last-of-type {
                margin-bottom: 0;
            }

            /* Cell (td) layout - show as blocks */
            tbody tr td {
                display: block;
                border: none;
                border-bottom: 1px solid #f0f0f0;
                position: relative;
                padding: 14px 15px;
                text-align: left;
                min-height: auto;
                background: white;
                word-wrap: break-word;
                word-break: break-word;
            }

            tbody tr td:last-child {
                border-bottom: none;
            }

            /* Label (::before) styling */
            tbody tr td::before {
                content: attr(data-label);
                font-weight: 700;
                color: #0b6ecf;
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                display: block;
                margin-bottom: 6px;
                opacity: 0.9;
            }

            /* Status badge styling */
            tbody tr td.status-1,
            tbody tr td.status-2,
            tbody tr td.status-3 {
                text-align: left;
            }

            /* Empty state */
            tbody tr[style*="display: none"] {
                display: none !important;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 8px;
            }

            .container {
                padding: 12px;
            }

            .page-header {
                gap: 8px;
            }

            .doctor-img, .ambulance-img {
                max-width: 100px;
            }

            h2 {
                font-size: 18px;
                margin-bottom: 12px;
            }

            tbody tr {
                margin-bottom: 12px;
                border-radius: 10px;
            }

            tbody tr td {
                padding: 12px 13px;
                font-size: 13px;
            }

            tbody tr td::before {
                font-size: 11px;
                margin-bottom: 5px;
            }

            #hospitalSearch, #statusFilter {
                font-size: 14px;
                padding: 10px 12px;
            }

            tr.group-header td.group-header-cell {
                padding: 10px 13px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>

<div class="container">
<div class="page-header">
    <img src="img/doctor.png" alt="doctor" class="doctor-img">
    <h2>ผู้ป่วยรอส่งกลับไปรักษาต่อ</h2>
    <img src="img/ambulance.png" alt="doctor" class="ambulance-img">
</div>
<div class="toolbar">
    <select id="statusFilter">
        <option value="">-- กรองตามสถานะทั้งหมด --</option>
        <option value="1">รอรถเข้ารับ</option>
        <option value="2">รถกำลังมารับ</option>
        <option value="3">บุรีรัมย์ไปส่ง</option>
    </select>
    <input type="text" id="hospitalSearch" placeholder="🔍 ค้นหาชื่อโรงพยาบาลหรือรหัส">
</div>

<table>
    <thead>
        <tr>
            <th>วันที่</th>
            <th>ชื่อ</th>
            <th>นามสกุล</th>
            <th>ตึก</th>
            <th>โรงพยาบาล</th>
            <th>อุปกรณ์ที่ใช้</th>
            <th>พันธมิตร</th>
            <th>หมายเหตุ</th>
            <th>เวลาประสาน</th>
            <th>สถานะ</th>
            </tr>
    </thead>
    <tbody id="dataTableBody">
    <?php 
    $hasData = false;
    foreach ($groupedRows as $status => $rows) {
        if (!empty($rows)) {
            $hasData = true;
            // Group header
            ?>
            <tr class="group-header status-group-<?= $status ?>" data-group-status="<?= $status ?>">
                <td colspan="10" class="group-header-cell">
                    <strong>กลุ่มที่ <?= $status ?>: <?= $statusLabels[$status] ?></strong>
                    <span class="group-count">(<?= count($rows) ?> รายการ)</span>
                </td>
            </tr>
            <?php
            // Group rows
            foreach ($rows as $r): ?>
                <tr data-status="<?= e($r['status']) ?>">
                    <td data-label="DATE"><?= e($r['date_in']) ?></td>
                    <td data-label="NAME"><?= e($r['name']) ?></td>
                    <td data-label="SURNAME"><?= e($r['surname']) ?></td>
                    <td data-label="WARD"><?= e($r['ward']) ?></td>
                    <td data-label="HOSPITAL"><?= e($r['hospital']) ?></td> 
                    <td data-label="O2/ETT/ICD"><?= e($r['o2_ett_icd']) ?></td>
                    <td data-label="พันธมิตร"><?= e($r['partner']) ?></td>
                    <td data-label="หมายเหตุ"><?= e($r['note']) ?></td>
                    <td data-label="เวลาประสาน"><?= e($r['contact_time']) ?></td>
                    <td data-label="สถานะ" class="status-<?= e($r['status']) ?>">
                        <?= $statusLabels[$r['status']] ?? '-' ?>
                    </td>
                </tr>
            <?php endforeach;
        }
    }
    if (!$hasData): ?>
        <tr><td colspan="10" style="text-align:center;">ไม่มีข้อมูล</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<script>
const zipcodeMap = <?= json_encode($zipcodeMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> || {};

function getHospitalsFromZipInput(inputValue) {
    const filter = inputValue.trim().toLowerCase();
    if (!filter) return null;
    const matches = [];
    Object.entries(zipcodeMap).forEach(([zip, hospitals]) => {
        if (!zip) return;
        if (zip.toLowerCase().startsWith(filter)) {
            (hospitals || []).forEach(name => {
                const lower = (name || '').toLowerCase();
                if (lower && !matches.includes(lower)) {
                    matches.push(lower);
                }
            });
        }
    });
    return matches.length ? matches : null;
}

// คืนค่าชื่อโรงพยาบาล (รูปแบบต้นฉบับ) ตาม prefix ของ zipcode ที่พิมพ์
// (Removed helper that returned hospital name suggestions — dropdown disabled)

document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('hospitalSearch');
    const statusFilter = document.getElementById('statusFilter');
    const tableBody = document.getElementById('dataTableBody');
    const hospitalColumnIndex = 4; // คอลัมน์ HOSPITAL อยู่ลำดับที่ 4 (นับจาก 0)

    // ฟังก์ชันหลักในการกรอง/ค้นหาข้อมูล
    function applyFilters() {
        const hospitalFilter = searchInput.value.trim().toLowerCase();
        const selectedStatus = statusFilter.value; // ค่า status (1, 2, 3 หรือ "")
        const rows = tableBody.getElementsByTagName('tr');

        let hasData = false;
        let groupHasVisibleRows = {}; // เก็บสถานะว่ากลุ่มไหนมีแถวที่แสดง
        const hospitalsFromZip = getHospitalsFromZipInput(hospitalFilter);

        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            
            // ตรวจสอบว่าเป็น group header หรือไม่
            if (row.classList.contains('group-header')) {
                const groupStatus = row.getAttribute('data-group-status');
                // แสดง group header ถ้ามีแถวที่แสดงในกลุ่มนั้น หรือถ้าไม่มีการกรองสถานะ
                if (selectedStatus === "" || selectedStatus === groupStatus) {
                    // ตรวจสอบว่ามีแถวในกลุ่มนี้ที่แสดงหรือไม่ (จะตรวจสอบหลังจากวนลูป)
                    continue;
                } else {
                    row.style.display = "none";
                    continue;
                }
            }
            
            // ข้ามแถวที่ไม่มีข้อมูลจริง (เช่น แถว "ไม่มีข้อมูล")
            if (row.children.length < 10) {
                 row.style.display = "";
                 continue;
            }
            
            const hospitalCell = row.getElementsByTagName('td')[hospitalColumnIndex];
            const rowStatus = row.getAttribute('data-status');
            
            if (!hospitalCell) continue;

            const hospitalText = hospitalCell.textContent || hospitalCell.innerText;

            // ตรวจสอบเงื่อนไขการค้นหาโรงพยาบาล
            let matchesHospital = true;
            const hospitalLower = hospitalText.toLowerCase();
            if (hospitalFilter) {
                matchesHospital = hospitalLower.indexOf(hospitalFilter) > -1;
            }

            let matchesZip = false;
            if (hospitalsFromZip && hospitalsFromZip.length) {
                matchesZip = hospitalsFromZip.some(name => hospitalLower.indexOf(name) > -1);
            }

            // ตรวจสอบเงื่อนไขการกรองสถานะ
            const matchesStatus = (selectedStatus === "" || rowStatus === selectedStatus);

            // แสดงผลลัพธ์
            if ((hospitalFilter === "" || matchesHospital || matchesZip) && matchesStatus) {
                row.style.display = "";
                hasData = true;
                // บันทึกว่ากลุ่มนี้มีแถวที่แสดง
                if (rowStatus) {
                    groupHasVisibleRows[rowStatus] = true;
                }
            } else {
                row.style.display = "none";
            }
        }
        
        // แสดง/ซ่อน group header ตามว่ามีแถวที่แสดงในกลุ่มนั้นหรือไม่
        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            if (row.classList.contains('group-header')) {
                const groupStatus = row.getAttribute('data-group-status');
                if (selectedStatus === "" || selectedStatus === groupStatus) {
                    // แสดง group header ถ้ามีแถวที่แสดงในกลุ่มนี้
                    if (groupHasVisibleRows[groupStatus]) {
                        row.style.display = "";
                    } else {
                        row.style.display = "none";
                    }
                }
            }
        }
    }

    // เพิ่ม Event Listener: ใช้เฉพาะการกรองแถวตามข้อความที่พิมพ์
    searchInput.addEventListener('keyup', applyFilters);
    statusFilter.addEventListener('change', applyFilters);

    // **********************************************
    // หมายเหตุ: ในหน้านี้เราไม่ต้องการ Modal Functions 
    // **********************************************
});
</script>

</body>
</html>