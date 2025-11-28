<?php
// ข้อมูลการเชื่อมต่อ
// ใช้ตัวแปร Railway MySQL หรือ default สำหรับ local
$host = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: 'localhost';
$user = getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root';
$pass = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS') ?: '';
$dbname = getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'referback';
$port = getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: '3306';

// If Railway provides a URL like mysql://user:pass@host:port/dbname, parse it and override
$mysqlUrl = getenv('MYSQL_URL') ?: getenv('MYSQLPUBLICURL') ?: getenv('MYSQL_PUBLIC_URL');
if ($mysqlUrl) {
    $parts = parse_url($mysqlUrl);
    if ($parts !== false) {
        if (!empty($parts['host'])) $host = $parts['host'];
        if (!empty($parts['port'])) $port = $parts['port'];
        if (!empty($parts['user'])) $user = $parts['user'];
        if (isset($parts['pass'])) $pass = $parts['pass'];
        if (!empty($parts['path'])) $dbname = ltrim($parts['path'], '/');
    }
}

// If host is 'localhost', force 127.0.0.1 to make PDO use TCP (prevents socket lookup)
if ($host === 'localhost') {
    $host = '127.0.0.1';
}

$tableName = "patient_records"; // ชื่อตารางหลัก (ใช้ร่วมกับโค้ดของคุณ)

try {
    // พยายามเชื่อมต่อกับ host ที่กำหนด หากล้มเหลว ให้ลอง hosts สำรอง (เช่น mysql.railway.internal)
    $attemptHosts = [$host];
    // เพิ่มค่าจาก env ชื่ออื่นๆ เผื่อถูกตั้งชื่อแตกต่าง
    $attemptHosts[] = getenv('MYSQL_HOST') ?: null;
    $attemptHosts[] = getenv('DB_HOST') ?: null;
    // Railway internal host
    $attemptHosts[] = 'mysql.railway.internal';
    $attemptHosts[] = 'mysql';

    $attemptHosts = array_values(array_filter(array_unique($attemptHosts)));

    $pdo = null;
    $lastException = null;
    foreach ($attemptHosts as $h) {
        $dsn = "mysql:host={$h};port={$port};dbname={$dbname};charset=utf8mb4";
        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // ถ้าเชื่อมสำเร็จ ให้อัพเดต host และ break
            $host = $h;
            break;
        } catch (PDOException $e) {
            $lastException = $e;
            // try next host
        }
    }

    if (!$pdo) {
        // ถ้ายังไม่สำเร็จ ให้โยน exception ล่าสุดออกมา
        throw $lastException ?: new PDOException('Unable to connect to database');
    }

} catch (PDOException $e) {
    echo "Database connection failed: " . $e->getMessage();
    exit;
}
