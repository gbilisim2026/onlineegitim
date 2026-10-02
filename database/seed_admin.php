<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Bu betik yalnızca terminalden çalıştırılabilir.\n");
}

$config = require dirname(__DIR__) . '/config.php';
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_port'], $config['db_name']);
$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$email = $argv[1] ?? '';
$password = $argv[2] ?? '';
$name = $argv[3] ?? 'Platform Yöneticisi';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
    fwrite(STDERR, "Kullanim: php database/seed_admin.php yonetici@ornek.com 'EnAz12Karakter!'\n");
    exit(1);
}
$stmt = $pdo->prepare("INSERT INTO users (name,email,password,role) VALUES (?,?,?,'admin') ON DUPLICATE KEY UPDATE name=VALUES(name),password=VALUES(password),role='admin',is_active=1");
$stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
echo "Yonetici hesabi hazir: {$email}\n";
