<?php
// กำหนดระดับการรายงานข้อผิดพลาด
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ----------------------------------------------------
// 1. ดึงค่าตัวแปรการเชื่อมต่อ (ให้ความสำคัญกับ Environment Variables ของ Railway)
// ----------------------------------------------------

// Null Coalescing Operator (??) ใช้เพื่อให้แน่ใจว่าดึงค่าจาก $_ENV / $_SERVER ได้ถ้ามี
// และใช้ getenv() เป็นตัวเลือกสำรองสุดท้าย

$host = $_ENV['MYSQLHOST'] ?? $_SERVER['MYSQLHOST'] ?? getenv('MYSQLHOST') ?: 
        ($_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? getenv('DB_HOST')) ?: 
        'localhost';

$user = $_ENV['MYSQLUSER'] ?? $_SERVER['MYSQLUSER'] ?? getenv('MYSQLUSER') ?:
        ($_ENV['DB_USER'] ?? $_SERVER['DB_USER'] ?? getenv('DB_USER')) ?:
        'root';

$pass = $_ENV['MYSQLPASSWORD'] ?? $_SERVER['MYSQLPASSWORD'] ?? getenv('MYSQLPASSWORD') ?:
        ($_ENV['DB_PASS'] ?? $_SERVER['DB_PASS'] ?? getenv('DB_PASS')) ?:
        ''; // ใช้ DB_PASS ตามที่โค้ดของคุณใช้ (แก้ไขปัญหา [1045] Access denied)

$dbname = $_ENV['MYSQLDATABASE'] ?? $_SERVER['MYSQLDATABASE'] ?? getenv('MYSQLDATABASE') ?:
          ($_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'] ?? getenv('DB_NAME')) ?:
          'referback';

$port = $_ENV['MYSQLPORT'] ?? $_SERVER['MYSQLPORT'] ?? getenv('MYSQLPORT') ?:
        ($_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'] ?? getenv('DB_PORT')) ?:
        '3306';

$tableName = "patient_records"; // ชื่อตารางหลัก

// ----------------------------------------------------
// 2. การจัดการ URL และ Host (ปรับปรุงจากโค้ดเดิมของคุณ)
// ----------------------------------------------------

// หาก Railway ให้ URL มา ให้ Parse และ Override ค่า
$mysqlUrl = getenv('MYSQL_URL') ?: getenv('MYSQLPUBLICURL') ?: getenv('MYSQL_PUBLIC_URL');
if ($mysqlUrl) {
    $parts = parse_url($mysqlUrl);
    if ($parts !== false) {
        if (!empty($parts['host'])) $host = $parts['host'];
        if (!empty($parts['port'])) $port = $parts['port'];
        if (!empty($parts['user'])) $user = $parts['user'];
        // ใช้รหัสผ่านจาก URL ก่อน หากมี
        if (isset($parts['pass'])) $pass = $parts['pass']; 
        if (!empty($parts['path'])) $dbname = ltrim($parts['path'], '/');
    }
}

// หาก Host เป็น 'localhost' ให้บังคับใช้ '127.0.0.1' เพื่อใช้ TCP (ป้องกัน Socket error [2002])
if ($host === 'localhost') {
    $host = '127.0.0.1';
}

// ----------------------------------------------------
// 3. การลองเชื่อมต่อหลาย Hosts และสร้างฐานข้อมูลอัตโนมัติ
// ----------------------------------------------------
try {
    // กำหนด hosts ที่จะลองเชื่อมต่อ: [Host ที่ถูกกำหนด], [Host ภายใน Railway]
    $attemptHosts = [$host]; 
    $attemptHosts[] = 'mysql.railway.internal';
    $attemptHosts[] = 'mysql';

    $attemptHosts = array_values(array_filter(array_unique($attemptHosts)));

    $pdo = null;
    $lastException = null;
    
    foreach ($attemptHosts as $h) {
        $dsn = "mysql:host={$h};port={$port};dbname={$dbname};charset=utf8mb4";
        try {
            // ลองเชื่อมต่อกับฐานข้อมูล
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $host = $h; // ถ้าเชื่อมสำเร็จ ให้อัพเดต host และหยุด loop
            break;
            
        } catch (PDOException $e) {
            $lastException = $e;
            $sqlstate = $e->getCode();
            
            // ถ้า error คือ Unknown database (1049) ให้ลองสร้างฐานข้อมูล
            if (intval($sqlstate) === 1049 || (is_array($e->errorInfo) && isset($e->errorInfo[1]) && $e->errorInfo[1] == 1049)) {
                try {
                    // เชื่อมต่อโดยไม่ระบุ dbname
                    $dsnNoDb = "mysql:host={$h};port={$port};charset=utf8mb4";
                    $adminPdo = new PDO($dsnNoDb, $user, $pass, [
                         PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                         PDO::ATTR_EMULATE_PREPARES => false,
                    ]);
                    
                    // สร้างฐานข้อมูลถ้ายังไม่มี
                    $createSql = "CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '``', $dbname) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
                    $adminPdo->exec($createSql);
                    
                    // ลองเชื่อมต่ออีกครั้งกับฐานข้อมูลที่สร้างใหม่
                    $pdo = new PDO($dsn, $user, $pass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]);
                    $host = $h;
                    break;
                } catch (PDOException $inner) {
                    $lastException = $inner; // เก็บ exception ล่าสุด
                }
            }
        }
    }

    if (!$pdo) {
        // ถ้ายังไม่สำเร็จ ให้โยน exception ล่าสุดออกมา
        throw $lastException ?: new PDOException('Unable to connect to database using any host.');
    }

} catch (PDOException $e) {
    echo "Database connection failed: " . $e->getMessage();
    exit;
}
// โค้ดของคุณจะดำเนินการต่อจากตรงนี้เมื่อเชื่อมต่อสำเร็จแล้ว
?>