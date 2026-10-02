<?php
declare(strict_types=1);

session_start();
$config = require __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo;
    global $config;
    if (!$pdo) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_port'], $config['db_name']);
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function user(): ?array
{
    static $loaded = false;
    static $current = null;
    if ($loaded) {
        return $current;
    }
    $loaded = true;

    $sessionUser = $_SESSION['user'] ?? null;
    $id = is_array($sessionUser) ? filter_var($sessionUser['id'] ?? null, FILTER_VALIDATE_INT) : false;
    if (!$id) {
        unset($_SESSION['user']);
        return null;
    }

    $statement = db()->prepare('SELECT id,name,email,role FROM users WHERE id=? AND is_active=1');
    $statement->execute([$id]);
    $current = $statement->fetch() ?: null;
    if ($current === null) {
        unset($_SESSION['user']);
        return null;
    }

    $_SESSION['user'] = $current;
    return $current;
}

function require_login(): array
{
    $current = user();
    if ($current === null) {
        flash('Oturum açarak devam edin.', 'error');
        redirect('?page=login');
    }
    return $current;
}

function require_admin(): array
{
    $current = require_login();
    if ($current['role'] !== 'admin') {
        http_response_code(403);
        exit('Bu alanı görüntüleme yetkiniz yok.');
    }
    return $current;
}

function teacher_class_ids(int $teacherId): array
{
    return array_map('intval', array_column(rows("SELECT class_id FROM class_members WHERE user_id=? AND member_role='teacher'", [$teacherId]), 'class_id'));
}

function managed_course(int $courseId, ?int $teacherId = null): ?array
{
    $current = user();
    if (!$current || !$courseId) {
        return null;
    }
    $course = rows('SELECT id,class_id,teacher_id FROM courses WHERE id=?', [$courseId])[0] ?? null;
    if (!$course) {
        return null;
    }
    if ($current['role'] === 'admin') {
        return $course;
    }
    if ($current['role'] !== 'teacher' || ($teacherId !== null && (int)$current['id'] !== $teacherId)) {
        return null;
    }
    if ((int)($course['teacher_id'] ?? 0) === (int)$current['id']) {
        return $course;
    }
    if ($course['class_id'] === null) {
        return null;
    }
    return scalar("SELECT COUNT(*) FROM class_members WHERE user_id=? AND class_id=? AND member_role='teacher'", [$current['id'], $course['class_id']])
        ? $course
        : null;
}

function require_content_manager(): array
{
    $current = require_login();
    if (!in_array($current['role'], ['admin', 'teacher'], true)) {
        http_response_code(403);
        exit('Bu alana erişim yetkiniz yok.');
    }
    return $current;
}

function can_access_course(int $courseId, array $current): bool
{
    if ($current['role'] === 'admin') {
        return true;
    }
    if ($current['role'] === 'teacher') {
        return managed_course($courseId, (int)$current['id']) !== null;
    }
    return (bool)scalar('SELECT COUNT(*) FROM course_members WHERE course_id=? AND user_id=?', [$courseId, $current['id']]);
}

function csrf_field(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

function verify_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.');
    }
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function post_string(string $key, int $max = 255): string
{
    $value = trim((string)($_POST[$key] ?? ''));
    if (mb_strlen($value) > $max) {
        throw new RuntimeException("“{$key}” alanı çok uzun.");
    }
    return $value;
}

function upload_content(): ?array
{
    global $config;
    if (empty($_FILES['file']['name'])) {
        return null;
    }
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Dosya yüklenemedi. Lütfen tekrar deneyin.');
    }
    if ($file['size'] > $config['max_upload_bytes']) {
        throw new RuntimeException('Dosya boyutu 25 MB sınırını aşıyor.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => ['image', 'jpg'], 'image/png' => ['image', 'png'], 'image/webp' => ['image', 'webp'],
        'video/mp4' => ['video', 'mp4'], 'video/webm' => ['video', 'webm'],
        'application/pdf' => ['document', 'pdf'],
        'application/msword' => ['document', 'doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['document', 'docx'],
        'application/vnd.ms-powerpoint' => ['document', 'ppt'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['document', 'pptx'],
        'application/vnd.ms-excel' => ['document', 'xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['document', 'xlsx'],
        'text/plain' => ['document', 'txt'],
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Bu dosya türüne izin verilmiyor. PDF, Office, JPG, PNG, WebP, MP4 veya WebM yükleyin.');
    }
    if (!is_dir($config['upload_dir']) && !mkdir($config['upload_dir'], 0755, true) && !is_dir($config['upload_dir'])) {
        throw new RuntimeException('Yükleme klasörü oluşturulamadı.');
    }
    [$contentType, $extension] = $allowed[$mime];
    $stored = bin2hex(random_bytes(20)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], $config['upload_dir'] . DIRECTORY_SEPARATOR . $stored)) {
        throw new RuntimeException('Yüklenen dosya kaydedilemedi.');
    }
    return ['path' => 'uploads/' . $stored, 'type' => $contentType, 'name' => basename($file['name'])];
}

function layout_start(string $title, string $active = ''): void
{
    $current = user();
    $notice = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    $links = match ($current['role'] ?? '') {
        'admin' => [['dashboard', 'Genel bakış', '◫'], ['classes', 'Sınıflar', '▦'], ['courses', 'Dersler', '▤'], ['content', 'İçerikler', '▶'], ['users', 'Kullanıcılar', '♙'], ['quizzes', 'Sınavlar', '✎']],
        'teacher' => [['dashboard', 'Öğretmen paneli', '◫'], ['classes', 'Sınıflarım', '▦'], ['courses', 'Derslerim', '▤'], ['content', 'İçerikler', '▶'], ['quizzes', 'Sınavlar', '✎']],
        'student' => [['dashboard', 'Öğrenim alanım', '◫'], ['classes', 'Sınıflarım', '▦'], ['courses', 'Derslerim', '▤'], ['quizzes', 'Sınavlar', '✎']],
        default => [],
    };
    ?><!doctype html>
    <html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#101a35"><title><?= e($title) ?> · Pusula Akademi</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css"></head><body>
    <?php if ($current): ?>
      <aside class="sidebar"><a class="brand" href="?page=dashboard"><span class="brand-mark">p.</span><span>Pusula<span class="brand-light">akademi</span></span></a>
      <div class="side-caption"><?= $current['role'] === 'admin' ? 'YÖNETİM' : 'ÖĞRENME ALANI' ?></div>
      <nav><?php foreach ($links as [$page, $label, $icon]): ?><a class="nav-link <?= $active === $page ? 'active' : '' ?>" href="?page=<?= e($page) ?>"><span class="nav-icon"><?= $icon ?></span><?= e($label) ?><?php if ($active === $page): ?><span class="nav-dot"></span><?php endif; ?></a><?php endforeach; ?></nav>
      <div class="sidebar-bottom"><div class="help-card"><span class="help-icon">✦</span><strong>Öğrenmeye devam et</strong><p>Yeni içeriklerle hedeflerine bir adım daha yaklaş.</p><a href="?page=courses">Dersleri keşfet <span>↗</span></a></div>
      <div class="user-chip"><div class="avatar"><?= e(mb_strtoupper(mb_substr($current['name'], 0, 1))) ?></div><div class="user-copy"><strong><?= e($current['name']) ?></strong><small><?= $current['role'] === 'admin' ? 'Yönetici' : ($current['role'] === 'teacher' ? 'Öğretmen' : 'Öğrenci') ?></small></div><a class="logout" href="?page=logout" title="Çıkış yap">↗</a></div></div>
      </aside>
      <div class="app-main"><header class="topbar"><div class="crumb">Pusula Akademi <span>/</span> <?= e($title) ?></div><div class="topbar-right"><span class="online-dot"></span><span class="topbar-label"><?= e(date('d.m.Y')) ?></span><div class="top-avatar"><?= e(mb_strtoupper(mb_substr($current['name'], 0, 1))) ?></div></div></header>
    <?php else: ?>
      <header class="public-nav"><a class="brand dark-brand" href="?"><span class="brand-mark">p.</span><span>Pusula<span class="brand-light">akademi</span></span></a><div class="public-nav-links"><a href="?page=login">Giriş yap</a><a class="button button-primary button-small" href="?page=register">Ücretsiz kayıt ol <span>↗</span></a></div></header>
    <?php endif; ?>
    <main class="<?= $current ? 'content-area' : 'public-content' ?>">
    <?php if ($notice): ?><div class="flash <?= e($notice['type']) ?>"><?= e($notice['message']) ?><button type="button" class="flash-close" onclick="this.parentElement.remove()">×</button></div><?php endif; ?>
    <?php
}

function layout_end(): void
{
    if (user()) echo '</main></div>';
    else echo '</main>';
    ?><script src="assets/app.js"></script></body></html><?php
}

function scalar(string $sql, array $params = []): mixed
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}

function rows(string $sql, array $params = []): array
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function turkish_date(): string
{
    $months = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $weekdays = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
    $now = time();
    return $weekdays[(int)date('w', $now)] . ', ' . date('d', $now) . ' ' . $months[(int)date('n', $now)] . ' ' . date('Y', $now);
}
