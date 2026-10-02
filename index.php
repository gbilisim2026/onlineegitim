<?php
declare(strict_types=1);
require __DIR__ . '/app.php';

$page = $_GET['page'] ?? (user() ? 'dashboard' : 'home');
$allowedPages = ['home', 'login', 'register', 'dashboard', 'classes', 'courses', 'content', 'users', 'quizzes', 'lesson', 'quiz', 'logout'];
if (!in_array($page, $allowedPages, true)) {
    http_response_code(404);
    $page = 'home';
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        handle_post();
    }
    render_page($page);
} catch (Throwable $error) {
    error_log((string)$error);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $message = $error instanceof RuntimeException ? $error->getMessage() : 'İşlem tamamlanamadı. Bilgileri kontrol edip tekrar deneyin.';
        flash($message, 'error');
        redirect('?page=' . urlencode((string)($_GET['page'] ?? 'dashboard')));
    }
    http_response_code(500);
    layout_start('Bir sorun oluştu');
    echo '<section class="empty-state"><span class="empty-icon">!</span><h1>Sayfa yüklenemedi</h1><p>' . e($error instanceof PDOException ? 'Veritabanına bağlanılamadı. Kurulum adımlarını kontrol edin.' : 'Beklenmeyen bir hata oluştu. Lütfen tekrar deneyin.') . '</p><a class="button button-primary" href="?">Ana sayfaya dön</a></section>';
    layout_end();
}

function handle_post(): void
{
    $action = $_POST['action'] ?? '';
    if ($action === 'register') {
        $name = post_string('name', 120);
        $email = filter_var(post_string('email', 190), FILTER_VALIDATE_EMAIL);
        $password = (string)($_POST['password'] ?? '');
        if (!$name || !$email || strlen($password) < 8) {
            throw new RuntimeException('Ad ve geçerli e-posta girin; şifre en az 8 karakter olmalıdır.');
        }
        $statement = db()->prepare("INSERT INTO users (name,email,password,role) VALUES (?,?,?,'student')");
        $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $_SESSION['user'] = ['id' => (int)db()->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => 'student'];
        flash('Hoş geldiniz! Öğrenme yolculuğunuz başladı.');
        redirect('?page=dashboard');
    }
    if ($action === 'login') {
        $email = filter_var(post_string('email', 190), FILTER_VALIDATE_EMAIL);
        $statement = db()->prepare('SELECT id,name,email,password,role FROM users WHERE email=? AND is_active=1');
        $statement->execute([$email]);
        $record = $statement->fetch();
        if (!$record || !password_verify((string)($_POST['password'] ?? ''), $record['password'])) {
            throw new RuntimeException('E-posta veya şifre hatalı.');
        }
        session_regenerate_id(true);
        unset($record['password']);
        $_SESSION['user'] = $record;
        redirect('?page=dashboard');
    }
    $current = require_login();
    if (str_starts_with($action, 'admin_')) {
        require_admin();
        if (in_array($action, ['admin_save_user', 'admin_delete_user'], true)) {
            user_management_action($action);
            return;
        }
        admin_action($action);
        return;
    }
    if (str_starts_with($action, 'manage_')) {
        require_content_manager();
        content_manager_action($action);
        return;
    }
    if ($action === 'finish_lesson') {
        $lessonId = filter_input(INPUT_POST, 'lesson_id', FILTER_VALIDATE_INT);
        if (!$lessonId || !scalar('SELECT COUNT(*) FROM lessons WHERE id=?', [$lessonId])) {
            throw new RuntimeException('Ders bulunamadı.');
        }
        db()->prepare('INSERT INTO progress (user_id,lesson_id,is_completed,completed_at) VALUES (?,?,1,NOW()) ON DUPLICATE KEY UPDATE is_completed=1,completed_at=NOW()')->execute([$current['id'], $lessonId]);
        flash('Ders tamamlandı. Harika iş!');
        redirect('?page=lesson&id=' . $lessonId);
    }
    if ($action === 'submit_quiz') {
        $quizId = filter_input(INPUT_POST, 'quiz_id', FILTER_VALIDATE_INT);
        $quiz = rows('SELECT id FROM quizzes WHERE id=? AND is_published=1', [$quizId])[0] ?? null;
        if (!$quiz || !scalar('SELECT COUNT(*) FROM quizzes q JOIN courses c ON c.id=q.course_id WHERE q.id=? AND c.id IN (SELECT course_id FROM course_members WHERE user_id=?)',[$quizId,$current['id']])) throw new RuntimeException('Sınav bulunamadı veya bu sınava atanmadınız.');
        $questions = rows('SELECT id,correct_option FROM questions WHERE quiz_id=? ORDER BY id', [$quizId]);
        if (!$questions) throw new RuntimeException('Bu sınavda henüz soru bulunmuyor.');
        $answers = $_POST['answer'] ?? [];
        $correct = 0;
        foreach ($questions as $question) {
            if (isset($answers[$question['id']]) && (int)$answers[$question['id']] === (int)$question['correct_option']) $correct++;
        }
        $score = (int)round($correct * 100 / count($questions));
        db()->prepare('INSERT INTO quiz_attempts (user_id,quiz_id,score,correct_count,total_count) VALUES (?,?,?,?,?)')->execute([$current['id'], $quizId, $score, $correct, count($questions)]);
        flash("Sınav tamamlandı. {$correct}/" . count($questions) . " doğru · puanınız {$score}.");
        redirect('?page=quizzes');
    }
    throw new RuntimeException('İşlem tanınamadı.');
}

function admin_action(string $action): void
{
    $pdo = db();
    if ($action === 'admin_save_class') {
        $id = (int)($_POST['id'] ?? 0);
        $name = post_string('name', 120);
        $track = post_string('track', 50);
        $grade = (int)($_POST['grade'] ?? 9);
        $description = post_string('description', 1000);
        if (!$name || !in_array($track, ['Yazılım Geliştirme', 'Siber Güvenlik'], true) || $grade < 9 || $grade > 12) throw new RuntimeException('Sınıf bilgilerini kontrol edin.');
        if ($id) $pdo->prepare('UPDATE classes SET name=?,track=?,grade=?,description=? WHERE id=?')->execute([$name,$track,$grade,$description,$id]);
        else $pdo->prepare('INSERT INTO classes (name,track,grade,description) VALUES (?,?,?,?)')->execute([$name,$track,$grade,$description]);
        flash('Sınıf kaydedildi.'); redirect('?page=classes');
    }
    if ($action === 'admin_delete_class') {
        $pdo->prepare('DELETE FROM classes WHERE id=?')->execute([(int)$_POST['id']]);
        flash('Sınıf silindi.'); redirect('?page=classes');
    }
    if ($action === 'admin_save_course') {
        $id=(int)($_POST['id']??0); $title=post_string('title',180); $description=post_string('description',3000);
        $track=post_string('track',50); $classId=(int)($_POST['class_id']??0); $teacherId=(int)($_POST['teacher_id']??0);
        $level=post_string('level',30);
        if (!$title || !in_array($track,['Yazılım Geliştirme','Siber Güvenlik'],true)) throw new RuntimeException('Ders bilgilerini kontrol edin.');
        if ($teacherId && !$classId) throw new RuntimeException('Bir öğretmen atamak için dersin sınıfını da seçin.');
        if ($teacherId && !scalar("SELECT COUNT(*) FROM users WHERE id=? AND role='teacher'", [$teacherId])) throw new RuntimeException('Seçilen kullanıcı öğretmen değil.');
        if ($id) $pdo->prepare('UPDATE courses SET title=?,description=?,track=?,class_id=NULLIF(?,0),teacher_id=NULLIF(?,0),level=? WHERE id=?')->execute([$title,$description,$track,$classId,$teacherId,$level,$id]);
        else {
            $pdo->prepare('INSERT INTO courses (title,description,track,class_id,teacher_id,level) VALUES (?,?,?,NULLIF(?,0),NULLIF(?,0),?)')->execute([$title,$description,$track,$classId,$teacherId,$level]);
            $id = (int)$pdo->lastInsertId();
        }
        if ($teacherId) {
            $pdo->prepare("INSERT INTO class_members (class_id,user_id,member_role) VALUES (?,?,'teacher') ON DUPLICATE KEY UPDATE member_role='teacher'")->execute([$classId,$teacherId]);
        }
        flash('Ders kaydedildi.'); redirect('?page=courses');
    }
    if ($action === 'admin_delete_course') {
        $id=(int)$_POST['id'];
        $files=rows('SELECT file_path FROM lessons WHERE course_id=? AND file_path IS NOT NULL',[$id]);
        $pdo->prepare('DELETE FROM courses WHERE id=?')->execute([$id]);
        foreach($files as $file) {
            $path=__DIR__.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$file['file_path']);
            if(is_file($path)) unlink($path);
        }
        flash('Ders silindi.'); redirect('?page=courses');
    }
    if ($action === 'admin_delete_quiz') {
        $pdo->prepare('DELETE FROM quizzes WHERE id=?')->execute([(int)$_POST['id']]);
        flash('Sınav silindi.'); redirect('?page=quizzes');
    }
    throw new RuntimeException('Yönetim işlemi tanınamadı.');
}

function content_manager_action(string $action): void
{
    $pdo = db();
    if ($action === 'manage_save_content') {
        $id=(int)($_POST['id']??0); $title=post_string('title',180); $description=post_string('description',2000); $courseId=(int)($_POST['course_id']??0);
        if (!$title || !$courseId) throw new RuntimeException('İçerik adı ve dersi zorunludur.');
        if (!managed_course($courseId)) throw new RuntimeException('Bu derse içerik ekleme yetkiniz yok.');
        if ($id && !scalar('SELECT COUNT(*) FROM lessons WHERE id=?',[$id])) throw new RuntimeException('Düzenlenecek içerik bulunamadı.');
        if ($id && !managed_course((int)scalar('SELECT course_id FROM lessons WHERE id=?',[$id]))) throw new RuntimeException('Bu içeriği düzenleme yetkiniz yok.');
        if ($url=trim(post_string('video_url',500))) {
            $parts=parse_url($url);
            if (!filter_var($url,FILTER_VALIDATE_URL) || !in_array(strtolower($parts['scheme']??''),['http','https'],true)) throw new RuntimeException('Video bağlantısı geçerli bir HTTP veya HTTPS adresi olmalıdır.');
        }
        $file=upload_content();
        $url=trim(post_string('video_url',500));
        $oldFile=$id?(rows('SELECT file_path FROM lessons WHERE id=?',[$id])[0]['file_path']??null):null;
        if ($file) {
            $type=$file['type'];
            $sql='INSERT INTO lessons (course_id,title,description,content_type,file_path,original_name,video_url,sort_order) VALUES (?,?,?,?,?,?,?,?)';
            $values=[$courseId,$title,$description,$type,$file['path'],$file['name'],$url,(int)($_POST['sort_order']??0)];
            if ($id) { $sql='UPDATE lessons SET course_id=?,title=?,description=?,content_type=?,file_path=?,original_name=?,video_url=?,sort_order=? WHERE id=?'; $values=[$courseId,$title,$description,$type,$file['path'],$file['name'],$url,(int)($_POST['sort_order']??0),$id]; }
            $pdo->prepare($sql)->execute($values);
        } elseif ($id) {
            $pdo->prepare('UPDATE lessons SET course_id=?,title=?,description=?,video_url=?,sort_order=? WHERE id=?')->execute([$courseId,$title,$description,$url,(int)($_POST['sort_order']??0),$id]);
        } else {
            $pdo->prepare('INSERT INTO lessons (course_id,title,description,content_type,video_url,sort_order) VALUES (?,?,?,\'video\',?,?)')->execute([$courseId,$title,$description,$url,(int)($_POST['sort_order']??0)]);
        }
        if ($file && $oldFile && $oldFile!==$file['path']) {
            $oldPath=__DIR__.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$oldFile);
            if (is_file($oldPath)) unlink($oldPath);
        }
        flash('İçerik kaydedildi.'); redirect('?page=content');
    }
    if ($action === 'manage_delete_content') {
        $lessonId=(int)$_POST['id']; $lesson=rows('SELECT file_path FROM lessons WHERE id=?',[$lessonId])[0]??null;
        if (!$lesson || !managed_course((int)scalar('SELECT course_id FROM lessons WHERE id=?',[$lessonId]))) throw new RuntimeException('Bu içeriği silme yetkiniz yok.');
        $pdo->prepare('DELETE FROM lessons WHERE id=?')->execute([$lessonId]);
        if ($lesson && $lesson['file_path']) { $path=__DIR__.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$lesson['file_path']); if (is_file($path)) unlink($path); }
        flash('İçerik silindi.'); redirect('?page=content');
    }
    if ($action === 'manage_save_quiz') {
        $id=(int)($_POST['id']??0); $title=post_string('title',180); $courseId=(int)($_POST['course_id']??0);
        $description=post_string('description',1500); $published=isset($_POST['is_published'])?1:0;
        if (!$title || !$courseId) throw new RuntimeException('Sınav adı ve ders seçimi zorunludur.');
        if (!managed_course($courseId)) throw new RuntimeException('Bu derse sınav ekleme yetkiniz yok.');
        $pdo->beginTransaction();
        try {
            if ($id) {
                $existing=rows('SELECT course_id FROM quizzes WHERE id=?',[$id])[0]??null;
                if (!$existing || !managed_course((int)$existing['course_id'])) throw new RuntimeException('Bu sınavı düzenleme yetkiniz yok.');
                $pdo->prepare('UPDATE quizzes SET course_id=?,title=?,description=?,is_published=? WHERE id=?')->execute([$courseId,$title,$description,$published,$id]);
                $quizId=$id;
                $pdo->prepare('DELETE FROM questions WHERE quiz_id=?')->execute([$quizId]);
            } else {
                $pdo->prepare('INSERT INTO quizzes (course_id,title,description,is_published) VALUES (?,?,?,?)')->execute([$courseId,$title,$description,$published]);
                $quizId=(int)$pdo->lastInsertId();
            }
            foreach (array_slice($_POST['question']??[],0,20) as $i=>$text) {
                $text=trim((string)$text); if (!$text) continue;
                $options=$_POST['options'][$i]??[];
                if (!is_array($options) || count($options)!==4 || count(array_filter($options,'strlen'))!==4) continue;
                $correct=max(0,min(3,(int)($_POST['correct'][$i]??0)));
                $pdo->prepare('INSERT INTO questions (quiz_id,question_text,option_a,option_b,option_c,option_d,correct_option) VALUES (?,?,?,?,?,?,?)')->execute([$quizId,$text,...array_values($options),$correct]);
            }
            if (!(int)scalar('SELECT COUNT(*) FROM questions WHERE quiz_id=?',[$quizId])) throw new RuntimeException('Sınava en az bir tam soru ekleyin.');
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        flash('Sınav ve soruları kaydedildi.'); redirect('?page=quizzes');
    }
    if ($action === 'manage_delete_quiz') {
        $quizId=(int)$_POST['id']; $courseId=(int)scalar('SELECT course_id FROM quizzes WHERE id=?',[$quizId]);
        if (!$courseId || !managed_course($courseId)) throw new RuntimeException('Bu sınavı silme yetkiniz yok.');
        $pdo->prepare('DELETE FROM quizzes WHERE id=?')->execute([$quizId]);
        flash('Sınav silindi.'); redirect('?page=quizzes');
    }
    throw new RuntimeException('Yönetim işlemi tanınamadı.');
}

function user_management_action(string $action): void
{
    $pdo=db();
    if($action==='admin_delete_user') {
        $id=(int)($_POST['id']??0);
        if($id===(int)user()['id']) throw new RuntimeException('Kendi hesabınızı bu panelden silemezsiniz.');
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
        flash('Kullanıcı silindi.'); redirect('?page=users');
    }
    if($action!=='admin_save_user') throw new RuntimeException('Kullanıcı işlemi tanınamadı.');
    $id=(int)($_POST['id']??0); $name=post_string('name',120); $email=filter_var(post_string('email',190),FILTER_VALIDATE_EMAIL);
    $role=post_string('role',20);
    $classIds=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['class_ids']??[])),static fn(int $id):bool=>$id>0)));
    $courseIds=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['course_ids']??[])),static fn(int $id):bool=>$id>0)));
    if(!$name||!$email||!in_array($role,['student','teacher','admin'],true)) throw new RuntimeException('Kullanıcı bilgilerini kontrol edin.');
    if($role==='admin'&&($classIds||$courseIds)) throw new RuntimeException('Yönetici kullanıcılar sınıf veya derse atanamaz.');
    if($role!=='student'&&$courseIds) throw new RuntimeException('Ders atamaları yalnızca öğrencilere yapılabilir.');
    if($classIds) {
        $placeholders=implode(',',array_fill(0,count($classIds),'?'));
        if((int)scalar("SELECT COUNT(*) FROM classes WHERE id IN ({$placeholders})",$classIds)!==count($classIds)) throw new RuntimeException('Seçilen sınıflardan biri bulunamadı.');
    }
    if($courseIds) {
        if(!$classIds) throw new RuntimeException('Öğrenciye ders atamadan önce en az bir sınıf seçin.');
        $placeholders=implode(',',array_fill(0,count($courseIds),'?'));
        $classFilter=implode(',',array_fill(0,count($classIds),'?'));
        if((int)scalar("SELECT COUNT(*) FROM courses WHERE id IN ({$placeholders}) AND class_id IN ({$classFilter})",array_merge($courseIds,$classIds))!==count($courseIds)) throw new RuntimeException('Öğrenciye yalnızca seçtiği sınıflara ait dersler atanabilir.');
    }
    if(!$id&&strlen((string)($_POST['password']??''))<8) throw new RuntimeException('Yeni kullanıcı şifresi en az 8 karakter olmalıdır.');
    $pdo->beginTransaction();
    try {
        if($id) {
            $password=trim((string)($_POST['password']??''));
            if($password!=='') $pdo->prepare('UPDATE users SET name=?,email=?,role=?,password=? WHERE id=?')->execute([$name,$email,$role,password_hash($password,PASSWORD_DEFAULT),$id]);
            else $pdo->prepare('UPDATE users SET name=?,email=?,role=? WHERE id=?')->execute([$name,$email,$role,$id]);
            $userId=$id;
        } else {
            $pdo->prepare('INSERT INTO users (name,email,role,password) VALUES (?,?,?,?)')->execute([$name,$email,$role,password_hash((string)$_POST['password'],PASSWORD_DEFAULT)]);
            $userId=(int)$pdo->lastInsertId();
        }
        $pdo->prepare('DELETE FROM class_members WHERE user_id=?')->execute([$userId]);
        $pdo->prepare('DELETE FROM course_members WHERE user_id=?')->execute([$userId]);
        foreach($classIds as $classId) $pdo->prepare('INSERT INTO class_members (class_id,user_id,member_role) VALUES (?,?,?)')->execute([$classId,$userId,$role]);
        foreach($courseIds as $courseId) $pdo->prepare('INSERT INTO course_members (course_id,user_id) VALUES (?,?)')->execute([$courseId,$userId]);
        $pdo->commit();
    } catch(Throwable $error) {
        if($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    flash('Kullanıcı, sınıf ve ders eşleştirmeleri kaydedildi.'); redirect('?page=users');
}

function render_page(string $page): void
{
    if ($page === 'logout') { session_destroy(); redirect('?'); }
    if (in_array($page,['login','register'],true) && user()) redirect('?page=dashboard');
    if (in_array($page,['dashboard','classes','courses','content','users','quizzes','lesson','quiz'],true)) require_login();
    if ($page==='users') require_admin();
    if ($page==='content') require_content_manager();
    if ($page==='home') { render_home(); return; }
    if ($page==='login' || $page==='register') { render_auth($page); return; }
    match ($page) {
        'dashboard' => render_dashboard(),
        'classes' => render_classes(),
        'courses' => render_courses(),
        'content' => render_content(),
        'users' => render_users(),
        'quizzes' => render_quizzes(),
        'lesson' => render_lesson(),
        'quiz' => render_quiz(),
        default => render_home(),
    };
}

function render_home(): void
{
    layout_start('Geleceğini keşfet');
    $courseCount=(int)scalar('SELECT COUNT(*) FROM courses');
    ?><section class="hero">
      <div class="hero-copy"><div class="eyebrow"><span class="eyebrow-star">✦</span> GELECEĞİNİ BUGÜNDEN İNŞA ET</div>
      <h1>Merakınla başla.<br><span>Geleceğinle</span> devam et.</h1>
      <p>Bilişim dünyasının kapılarını arala. Yazılım geliştirme ve siber güvenlik alanlarında, sana özel öğrenme yolculuğuna hemen başla.</p>
      <div class="hero-actions"><a class="button button-primary" href="?page=register">Öğrenmeye başla <span>↗</span></a><a class="text-link" href="#alanlar">Alanları keşfet <span>↓</span></a></div>
      <div class="hero-proof"><div class="proof-avatars"><span>AY</span><span>MK</span><span>ED</span><span>+</span></div><div><strong>Geleceğin teknoloji uzmanları</strong><small>ile aynı yolda buluş</small></div></div></div>
      <div class="hero-visual"><div class="visual-glow"></div><div class="visual-grid"></div><div class="visual-code"><div class="code-top"><i></i><i></i><i></i><span>gelecek.py</span></div><div class="code-body"><span class="code-muted">01</span> <span class="code-purple">class</span> <span class="code-green">Gelecek</span>:<br><span class="code-muted">02</span> &nbsp; <span class="code-purple">def</span> <span class="code-blue">ogren</span>(<span class="code-orange">ben</span>):<br><span class="code-muted">03</span> &nbsp;&nbsp;&nbsp; merak = <span class="code-green">sonsuz</span><br><span class="code-muted">04</span> &nbsp;&nbsp;&nbsp; beceri = <span class="code-green">"senin"</span><br><span class="code-muted">05</span> &nbsp;&nbsp;&nbsp; <span class="code-purple">return</span> basari <span class="code-cursor">▌</span></div></div>
      <div class="float-card float-top"><span class="float-icon purple">⌘</span><span><strong>Yazılım geliştirme</strong><small>Hayal et, kodla, üret.</small></span></div><div class="float-card float-bottom"><span class="float-icon mint">⌕</span><span><strong>Siber güvenlik</strong><small>Dijital dünyayı koru.</small></span><span class="float-check">✓</span></div><div class="visual-note"><span>✳</span> Öğren. Üret. İz bırak.</div></div>
    </section>
    <section class="stats-strip"><div><strong><?= $courseCount ?>+</strong><span>uzmanlık dersi</span></div><i></i><div><strong>2</strong><span>geleceğin alanı</span></div><i></i><div><strong>∞</strong><span>öğrenme fırsatı</span></div><i></i><div class="stats-last"><span class="online-dot"></span><span>Her seviyeye uygun içerik</span></div></section>
    <section id="alanlar" class="tracks-section"><div class="section-head"><div><div class="eyebrow">SANA UYGUN YOLU SEÇ</div><h2>Gelecek burada <span>şekilleniyor.</span></h2></div><p>İlgi alanını seç, güçlü bir temel oluştur<br>ve geleceğin mesleklerine hazırlan.</p></div>
      <div class="track-cards"><article class="track-card track-software"><div class="track-number">01 / YAZILIM</div><div class="track-symbol">⌘</div><h3>Yazılım Geliştirme</h3><p>Fikirlerini çalışan ürünlere dönüştür. Kodlamanın temellerinden gerçek dünya projelerine uzanan bir yolculuk.</p><div class="track-tags"><span>Python</span><span>Web geliştirme</span><span>Algoritma</span></div><a href="?page=register" class="track-link">Bu alanı keşfet <span>↗</span></a></article>
      <article class="track-card track-security"><div class="track-number">02 / GÜVENLİK</div><div class="track-symbol">⌕</div><h3>Siber Güvenlik</h3><p>Dijital dünyayı tehditlere karşı koru. Güvenlik bilincini geliştir, savunma ve analiz becerileri kazan.</p><div class="track-tags"><span>Ağ güvenliği</span><span>Linux</span><span>Etik hacking</span></div><a href="?page=register" class="track-link">Bu alanı keşfet <span>↗</span></a></article></div>
    </section><section class="bottom-cta"><div><span class="eyebrow">YOLCULUĞUN ŞİMDİ BAŞLASIN</span><h2>Bir sonraki adımın, <span>tek tık uzağında.</span></h2></div><a class="button button-light" href="?page=register">Hesabını oluştur <span>↗</span></a></section>
    <?php layout_end();
}

function render_auth(string $mode): void
{
    $register=$mode==='register';
    layout_start($register?'Hesap oluştur':'Giriş yap');
    ?><section class="auth-layout"><div class="auth-art"><a class="brand" href="?"><span class="brand-mark">p.</span><span>Pusula<span class="brand-light">akademi</span></span></a><div class="auth-art-copy"><div class="eyebrow"><span class="eyebrow-star">✦</span> MERAKIN YÖNÜNÜ BULSUN</div><h1>Her büyük gelecek,<br><span>küçük bir adımla</span><br>başlar.</h1><p>Bilgiye, beceriye ve geleceğine açılan kapı.</p></div><div class="auth-art-foot">Öğren. Üret. İz bırak.</div></div><div class="auth-form-wrap"><div class="auth-form">
    <a href="?" class="back-link">← Ana sayfaya dön</a><div class="auth-mark">p.</div><h2><?= $register?'Aramıza katıl':'Tekrar hoş geldin' ?></h2><p class="auth-sub"><?= $register?'Öğrenme yolculuğun için hesabını oluştur.':'Öğrenmeye kaldığın yerden devam et.' ?></p>
    <form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $register?'register':'login' ?>">
    <?php if($register): ?><label>Ad soyad<input name="name" placeholder="Örn. Deniz Yılmaz" required maxlength="120"></label><?php endif; ?>
    <label>E-posta adresi<input name="email" type="email" placeholder="ornek@eposta.com" required maxlength="190"></label>
    <label>Şifre<div class="password-wrap"><input name="password" type="password" placeholder="<?= $register?'En az 8 karakter':'Şifrenizi girin' ?>" required minlength="<?= $register?'8':'1' ?>"><button type="button" class="toggle-password" aria-label="Şifreyi göster">◉</button></div></label>
    <?php if($register): ?><label class="check-label"><input type="checkbox" required><span>Kullanım koşullarını ve gizlilik politikasını kabul ediyorum.</span></label><?php endif; ?>
    <button class="button button-primary button-wide" type="submit"><?= $register?'Ücretsiz hesap oluştur':'Giriş yap' ?> <span>↗</span></button></form>
    <div class="auth-switch"><?= $register?'Zaten hesabın var mı?':'Henüz hesabın yok mu?' ?> <a href="?page=<?= $register?'login':'register' ?>"><?= $register?'Giriş yap':'Hesap oluştur' ?></a></div>
    </div></div></section><?php layout_end();
}

function render_dashboard(): void
{
    $current=require_login(); $admin=$current['role']==='admin';
    layout_start('Genel bakış','dashboard');
    if($admin) {
        $counts=['öğrenci'=>scalar("SELECT COUNT(*) FROM users WHERE role='student'"),'öğretmen'=>scalar("SELECT COUNT(*) FROM users WHERE role='teacher'"),'sınıf'=>scalar('SELECT COUNT(*) FROM classes'),'ders'=>scalar('SELECT COUNT(*) FROM courses')];
        $recent=rows('SELECT u.name,q.title,a.score,a.created_at FROM quiz_attempts a JOIN users u ON u.id=a.user_id JOIN quizzes q ON q.id=a.quiz_id ORDER BY a.created_at DESC LIMIT 6');
        ?><div class="page-heading"><div><div class="eyebrow">YÖNETİM PANELİ</div><h1>Genel bakış<span class="heading-period">.</span></h1><p>Platformundaki öğrenme faaliyetlerini ve içerikleri yönet.</p></div><a href="?page=content" class="button button-primary">＋ İçerik ekle</a></div>
        <div class="welcome-banner admin-banner"><div><span class="banner-label">YÖNETİCİ PANELİ</span><h2>Merhaba, <?= e(explode(' ',$current['name'])[0]) ?> <span>✦</span></h2><p>İşte platformunun güncel görünümü.</p></div><div class="banner-art">✳</div><div class="banner-date"><?= e(turkish_date()) ?></div></div>
        <div class="metric-grid"><?php foreach($counts as $label=>$value): ?><article class="metric-card"><div class="metric-top"><span><?= e(ucfirst($label)) ?> sayısı</span><span class="metric-icon">↗</span></div><strong><?= (int)$value ?></strong><small>Platform genelinde</small></article><?php endforeach; ?></div>
        <div class="dashboard-grid"><section class="panel"><div class="panel-heading"><div><h3>Son sınav sonuçları</h3><p>Öğrencilerin yakın tarihli performansı</p></div><a href="?page=quizzes">Tüm sınavlar ↗</a></div>
        <?php if($recent): ?><div class="table-wrap"><table><thead><tr><th>ÖĞRENCİ</th><th>SINAV</th><th>PUAN</th><th>TARİH</th></tr></thead><tbody><?php foreach($recent as $r): ?><tr><td><strong><?= e($r['name']) ?></strong></td><td><?= e($r['title']) ?></td><td><span class="score-pill"><?= (int)$r['score'] ?>%</span></td><td><?= e(date('d.m.Y',strtotime($r['created_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="inline-empty">Henüz sınav sonucu bulunmuyor.</div><?php endif; ?></section>
        <section class="panel quick-panel"><div class="panel-heading"><div><h3>Hızlı işlemler</h3><p>Yönetim araçlarına kolay erişim</p></div></div><a class="quick-link" href="?page=users"><span>♙</span><div><strong>Kullanıcıları yönet</strong><small>Öğrenci ve öğretmenler</small></div><b>↗</b></a><a class="quick-link" href="?page=classes"><span>▦</span><div><strong>Sınıfları düzenle</strong><small>Sınıf ekle ve eşleştir</small></div><b>↗</b></a><a class="quick-link" href="?page=courses"><span>▤</span><div><strong>Ders oluştur</strong><small>Dersleri ve alanları yönet</small></div><b>↗</b></a><a class="quick-link" href="?page=quizzes"><span>✎</span><div><strong>Sınav oluştur</strong><small>Bilgiyi ölç ve değerlendir</small></div><b>↗</b></a></section></div><?php
    } else {
        $student=$current['role']==='student';
        $membershipJoin=$student?'JOIN course_members cm ON cm.course_id=c.id AND cm.user_id=?':"JOIN class_members cm ON cm.class_id=c.class_id AND cm.user_id=? AND cm.member_role='teacher'";
        $membershipParams=[$current['id']];
        $courseScope="c.id IN (SELECT cm.course_id FROM course_members cm WHERE cm.user_id=?)";
        $courseScopeParams=[$current['id']];
        if(!$student) {
            $courseScope="(c.teacher_id=? OR c.class_id IN (SELECT cm.class_id FROM class_members cm WHERE cm.user_id=? AND cm.member_role='teacher'))";
            $courseScopeParams=[$current['id'],$current['id']];
        }
        $completed=(int)scalar("SELECT COUNT(*) FROM progress p JOIN lessons l ON l.id=p.lesson_id JOIN courses c ON c.id=l.course_id WHERE p.user_id=? AND p.is_completed=1 AND {$courseScope}",array_merge([$current['id']],$courseScopeParams));
        $total=(int)scalar("SELECT COUNT(*) FROM lessons l JOIN courses c ON c.id=l.course_id WHERE {$courseScope}",$courseScopeParams);
        $quizCount=(int)scalar('SELECT COUNT(*) FROM quiz_attempts WHERE user_id=?',[$current['id']]);
        $average=(int)(scalar('SELECT COALESCE(AVG(score),0) FROM quiz_attempts WHERE user_id=?',[$current['id']])?:0);
        $courseRows=rows("SELECT c.*,COUNT(DISTINCT l.id) lesson_count,COUNT(DISTINCT CASE WHEN p.is_completed=1 THEN p.lesson_id END) completed_count FROM courses c {$membershipJoin} LEFT JOIN lessons l ON l.course_id=c.id LEFT JOIN progress p ON p.lesson_id=l.id AND p.user_id=? GROUP BY c.id ORDER BY c.created_at DESC LIMIT 6",array_merge($membershipParams,[$current['id']]));
        ?><div class="page-heading"><div><div class="eyebrow"><?= $student?'ÖĞRENME ALANIN':'ÖĞRETMEN PANELİ' ?></div><h1>Merhaba, <?= e(explode(' ',$current['name'])[0]) ?><span class="heading-period">.</span></h1><p><?= $student?'Bugün yeni bir şey öğrenmek için harika bir gün.':'Atandığın sınıfların derslerini, içeriklerini ve sınavlarını yönet.' ?></p></div><a href="<?= $student?'?page=courses':'?page=content&new=1' ?>" class="button button-primary"><?= $student?'Dersleri keşfet':'＋ İçerik ekle' ?> <span>↗</span></a></div>
        <div class="welcome-banner"><div><span class="banner-label">KÜÇÜK ADIMLAR, BÜYÜK GELECEKLER</span><h2>Öğrenmeye devam et <span>✦</span></h2><p>Her tamamladığın ders seni hedefine biraz daha yaklaştırır.</p><a href="?page=courses" class="banner-link">Derslerine göz at <span>→</span></a></div><div class="banner-art">✳</div><div class="banner-date"><?= e(date('d.m.Y')) ?></div></div>
        <div class="metric-grid"><article class="metric-card"><div class="metric-top"><span>Tamamlanan ders</span><span class="metric-icon mint-icon">✓</span></div><strong><?= $completed ?><small class="metric-total"> / <?= $total ?></small></strong><small>Öğrenme yolculuğunda</small></article><article class="metric-card"><div class="metric-top"><span>Sınav denemesi</span><span class="metric-icon purple-icon">✎</span></div><strong><?= $quizCount ?></strong><small>Bilgini test ettin</small></article><article class="metric-card"><div class="metric-top"><span>Ortalama puan</span><span class="metric-icon orange-icon">↗</span></div><strong><?= $average ?><small class="metric-total">%</small></strong><small>Son sınavlarının ortalaması</small></article><article class="metric-card"><div class="metric-top"><span>Öğrenme serisi</span><span class="metric-icon blue-icon">✦</span></div><strong>∞</strong><small>Merakınla devam et</small></article></div>
        <div class="section-head compact-head"><div><div class="eyebrow"><?= $student?'SANA ATANAN DERSLER':'SINIFLARINDAKİ DERSLER' ?></div><h2><?= $student?'Öğrenme yolculuğun':'Ders ve sınıf görünümü' ?></h2></div><a class="subtle-link" href="?page=courses">Tüm dersler <span>→</span></a></div>
        <div class="course-grid"><?php if(!$courseRows): ?><div class="empty-state small-empty"><span class="empty-icon">▤</span><h3><?= $student?'Henüz ders atanmamış':'Henüz sınıflarına ders eklenmemiş' ?></h3><p><?= $student?'Yöneticin ders ataması yaptığında derslerin burada görüntülenecek.':'Sınıflarındaki dersler eklendiğinde burada görüntülenecek.' ?></p></div><?php endif; foreach($courseRows as $i=>$course):$pct=$course['lesson_count']?(int)round($course['completed_count']*100/$course['lesson_count']):0; ?><article class="course-card"><div class="course-art art-<?= $i%4 ?>"><span><?= $course['track']==='Yazılım Geliştirme'?'⌘':'⌕' ?></span><small><?= e($course['track']) ?></small></div><div class="course-body"><div class="course-level"><?= e($course['level']) ?> <span>· <?= (int)$course['lesson_count'] ?> ders</span></div><h3><?= e($course['title']) ?></h3><p><?= e(mb_strimwidth($course['description']??'',0,98,'…')) ?></p><div class="progress-label"><span><?= $student?'İlerleme':'Tamamlanan içerik' ?></span><strong><?= $student?'%'.$pct:(int)$course['lesson_count'] ?></strong></div><div class="progress-track"><i style="width:<?= $pct ?>%"></i></div><a class="course-open" href="?page=courses&open=<?= (int)$course['id'] ?>"><?= $student?'Derse git':'İçerikleri yönet' ?> <span>→</span></a></div></article><?php endforeach; ?></div><?php
    }
    layout_end();
}

function render_classes(): void
{
    $current=user(); $admin=$current['role']==='admin';
    $track=in_array($_GET['track']??'', ['Yazılım Geliştirme','Siber Güvenlik'],true)?$_GET['track']:'';
    $classes=$admin?rows('SELECT c.*,COUNT(DISTINCT cm.user_id) member_count FROM classes c LEFT JOIN class_members cm ON cm.class_id=c.id WHERE (?=\'\' OR c.track=?) GROUP BY c.id ORDER BY c.grade,c.name',[$track,$track]):rows('SELECT c.*,COUNT(DISTINCT cm.user_id) member_count FROM classes c JOIN class_members mine ON mine.class_id=c.id AND mine.user_id=? LEFT JOIN class_members cm ON cm.class_id=c.id WHERE (?=\'\' OR c.track=?) GROUP BY c.id ORDER BY c.grade,c.name',[$current['id'],$track,$track]);
    layout_start($admin?'Sınıflar':'Sınıflarım','classes');
    ?><div class="page-heading"><div><div class="eyebrow"><?= $admin?'YÖNETİM':'ÖĞRENME ALANI' ?></div><h1><?= $admin?'Sınıf yönetimi':'Sınıflarım' ?><span class="heading-period">.</span></h1><p><?= $admin?'Sınıfları, alanları ve sınıf eşleştirmelerini yönet.':'Kayıtlı olduğun sınıfları ve uzmanlık alanlarını görüntüle.' ?></p></div><?php if($admin): ?><button class="button button-primary" data-modal="class-modal">＋ Sınıf ekle</button><?php endif; ?></div>
    <div class="track-tabs"><a class="<?= $track===''?'active':'' ?>" href="?page=classes">Tüm sınıflar <b><?= count($track===''?$classes:($admin?rows('SELECT id FROM classes'):rows('SELECT c.id FROM classes c JOIN class_members cm ON cm.class_id=c.id WHERE cm.user_id=?',[$current['id']])) ) ?></b></a><a class="<?= $track==='Yazılım Geliştirme'?'active':'' ?>" href="?page=classes&track=<?= urlencode('Yazılım Geliştirme') ?>">Yazılım geliştirme</a><a class="<?= $track==='Siber Güvenlik'?'active':'' ?>" href="?page=classes&track=<?= urlencode('Siber Güvenlik') ?>">Siber güvenlik</a></div><div class="class-grid">
    <?php if(!$classes): ?><div class="empty-state small-empty"><span class="empty-icon">▦</span><h3>Henüz sınıf bulunmuyor</h3><p><?= $admin?'İlk sınıfını ekleyerek öğrenme alanını oluştur.':'Bir sınıfa eklendiğinde burada görüntüleyebilirsin.' ?></p></div><?php endif; foreach($classes as $i=>$class): ?><article class="class-card"><div class="class-card-top"><div class="class-icon icon-<?= $i%4 ?>"><?= $class['track']==='Yazılım Geliştirme'?'⌘':'⌕' ?></div><span class="status-chip">Aktif</span></div><div class="class-track"><?= e($class['track']) ?> <span>· <?= (int)$class['grade'] ?>. sınıf</span></div><h3><?= e($class['name']) ?></h3><p><?= e($class['description']?:'Bu sınıfın ders ve öğrenme içeriklerini keşfet.') ?></p><div class="class-card-bottom"><span>♙ <?= (int)$class['member_count'] ?> üye</span><?php if($admin): ?><button class="icon-button" data-edit-class="<?= e(json_encode($class,JSON_HEX_APOS|JSON_HEX_QUOT)) ?>">Düzenle</button><?php else: ?><a href="?page=courses&class=<?= (int)$class['id'] ?>">Dersleri gör →</a><?php endif; ?></div><?php if($admin): ?><form method="post" class="inline-delete"><?= csrf_field() ?><input type="hidden" name="action" value="admin_delete_class"><input type="hidden" name="id" value="<?= (int)$class['id'] ?>"><button type="submit" data-confirm="Bu sınıf silinsin mi? Üye eşleştirmeleri de kaldırılır.">Sınıfı sil</button></form><?php endif; ?></article><?php endforeach; ?></div>
    <?php if($admin): ?><div class="modal" id="class-modal"><div class="modal-card"><button class="modal-close" data-close>×</button><div class="eyebrow">SINIF BİLGİLERİ</div><h2>Sınıf oluştur</h2><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="admin_save_class"><input type="hidden" name="id" value=""><label>Sınıf adı<input name="name" required placeholder="Örn. 10-A Yazılım"></label><div class="form-row"><label>Alan<select name="track"><option>Yazılım Geliştirme</option><option>Siber Güvenlik</option></select></label><label>Sınıf düzeyi<select name="grade"><?php for($g=9;$g<=12;$g++): ?><option><?= $g ?></option><?php endfor; ?></select></label></div><label>Açıklama<textarea name="description" rows="3"></textarea></label><button class="button button-primary button-wide">Sınıfı kaydet</button></form></div></div><?php endif; layout_end();
}

function render_courses(): void
{
    $current=user(); $admin=$current['role']==='admin';
    $teacher=$current['role']==='teacher';
    $filterClass=filter_input(INPUT_GET,'class',FILTER_VALIDATE_INT)?:0;
    $scope=$admin?'1=1':($teacher?'(c.teacher_id=? OR c.class_id IN (SELECT class_id FROM class_members WHERE user_id=? AND member_role=\'teacher\'))':'c.id IN (SELECT course_id FROM course_members WHERE user_id=?)');
    $params=$admin?[]:($teacher?[$current['id'],$current['id']]:[$current['id']]);
    $courses=rows("SELECT c.*,cl.name class_name,u.name teacher_name,COUNT(l.id) lesson_count FROM courses c LEFT JOIN classes cl ON cl.id=c.class_id LEFT JOIN users u ON u.id=c.teacher_id LEFT JOIN lessons l ON l.course_id=c.id WHERE {$scope} AND (?=0 OR c.class_id=?) GROUP BY c.id ORDER BY c.created_at DESC",array_merge($params,[$filterClass,$filterClass]));
    $editing=isset($_GET['id'])?(rows('SELECT * FROM courses WHERE id=?',[(int)$_GET['id']])[0]??null):null;
    if($editing&&!$admin) $editing=null;
    layout_start('Dersler','courses');
    ?><div class="page-heading"><div><div class="eyebrow"><?= $admin?'İÇERİK YÖNETİMİ':($teacher?'ÖĞRETMEN ALANI':'ÖĞRENME ALANI') ?></div><h1><?= $admin?'Ders yönetimi':($teacher?'Sınıflarımın dersleri':'Derslerim') ?><span class="heading-period">.</span></h1><p><?= $admin?'Dersleri, uzmanlık alanlarını ve öğretmen atamalarını düzenle.':($teacher?'Sınıflarına ait dersleri ve öğrenme içeriklerini yönet.':'Sana atanmış dersleri görüntüle.') ?></p></div><?php if($admin): ?><a class="button button-primary" href="?page=courses&new=1">＋ Ders ekle</a><?php endif; ?></div>
    <?php if($admin && (isset($_GET['new'])||$editing)): ?><section class="panel editor-panel"><div class="panel-heading"><div><h3><?= $editing?'Dersi düzenle':'Yeni ders oluştur' ?></h3><p>Ders bilgilerini ve öğretmen eşleştirmesini belirle.</p></div><a href="?page=courses">Kapat ×</a></div><form method="post" class="form-stack form-horizontal"><?= csrf_field() ?><input type="hidden" name="action" value="admin_save_course"><input type="hidden" name="id" value="<?= (int)($editing['id']??0) ?>"><div class="form-row"><label>Ders adı<input name="title" required value="<?= e($editing['title']??'') ?>" placeholder="Örn. Python ile programlama"></label><label>Uzmanlık alanı<select name="track"><?php foreach(['Yazılım Geliştirme','Siber Güvenlik'] as $track): ?><option <?= ($editing['track']??'')===$track?'selected':'' ?>><?= e($track) ?></option><?php endforeach; ?></select></label></div><label>Açıklama<textarea name="description" rows="3"><?= e($editing['description']??'') ?></textarea></label><div class="form-row"><label>Sınıf<select name="class_id"><option value="0">Tüm sınıflar</option><?php foreach(rows('SELECT id,name FROM classes ORDER BY grade,name') as $cl): ?><option value="<?= (int)$cl['id'] ?>" <?= ($editing['class_id']??0)==$cl['id']?'selected':'' ?>><?= e($cl['name']) ?></option><?php endforeach; ?></select></label><label>Öğretmen<select name="teacher_id"><option value="0">Atanmamış</option><?php foreach(rows("SELECT id,name FROM users WHERE role='teacher' ORDER BY name") as $teacher): ?><option value="<?= (int)$teacher['id'] ?>" <?= ($editing['teacher_id']??0)==$teacher['id']?'selected':'' ?>><?= e($teacher['name']) ?></option><?php endforeach; ?></select></label><label>Seviye<select name="level"><?php foreach(['Başlangıç','Orta','İleri'] as $level): ?><option <?= ($editing['level']??'Başlangıç')===$level?'selected':'' ?>><?= $level ?></option><?php endforeach; ?></select></label></div><button class="button button-primary">Dersi kaydet <span>↗</span></button></form></section><?php endif; ?>
    <div class="course-grid"><?php if(!$courses): ?><div class="empty-state small-empty"><span class="empty-icon">▤</span><h3>Henüz ders bulunmuyor</h3><p>Dersler eklendiğinde burada listelenecek.</p></div><?php endif; foreach($courses as $i=>$course): ?><article class="course-card"><div class="course-art art-<?= $i%4 ?>"><span><?= $course['track']==='Yazılım Geliştirme'?'⌘':'⌕' ?></span><small><?= e($course['track']) ?></small></div><div class="course-body"><div class="course-level"><?= e($course['level']) ?> <span>· <?= (int)$course['lesson_count'] ?> içerik</span></div><h3><?= e($course['title']) ?></h3><p><?= e(mb_strimwidth($course['description']??'',0,100,'…')) ?></p><div class="course-meta"><?= $course['class_name']?'<span>▦ '.e($course['class_name']).'</span>':'' ?><?= $course['teacher_name']?'<span>♙ '.e($course['teacher_name']).'</span>':'' ?></div><a class="course-open" href="?page=courses&open=<?= (int)$course['id'] ?>">İçerikleri görüntüle <span>→</span></a><?php if($admin): ?><div class="admin-card-actions"><a href="?page=courses&id=<?= (int)$course['id'] ?>">Düzenle</a><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="admin_delete_course"><input type="hidden" name="id" value="<?= (int)$course['id'] ?>"><button data-confirm="Bu ders ve ilişkili içerikleri silinsin mi?">Sil</button></form></div><?php endif; ?></div></article><?php endforeach; ?></div>
    <?php if(isset($_GET['open'])): $courseId=(int)$_GET['open']; $course=rows('SELECT * FROM courses WHERE id=?',[$courseId])[0]??null; if($course&&can_access_course($courseId,$current)): ?><section class="panel course-lessons"><div class="panel-heading"><div><div class="eyebrow"><?= e($course['track']) ?></div><h3><?= e($course['title']) ?></h3><p><?= e($course['description']??'') ?></p></div><a href="?page=quizzes&course=<?= $courseId ?>">Sınavlara git →</a></div><?php $lessons=rows('SELECT * FROM lessons WHERE course_id=? ORDER BY sort_order,id',[$courseId]); foreach($lessons as $index=>$lesson): ?><a class="lesson-row" href="?page=lesson&id=<?= (int)$lesson['id'] ?>"><span class="lesson-play"><?= $lesson['content_type']==='video'?'▶':($lesson['content_type']==='image'?'▧':'↓') ?></span><span><small>DERS <?= str_pad((string)($index+1),2,'0',STR_PAD_LEFT) ?> · <?= e(ucfirst($lesson['content_type'])) ?></small><strong><?= e($lesson['title']) ?></strong></span><span class="lesson-arrow">↗</span></a><?php endforeach; if(!$lessons) echo '<div class="inline-empty">Bu derse henüz içerik eklenmemiş.</div>'; ?></section><?php elseif(isset($_GET['open'])): ?><div class="inline-empty">Bu derse erişim yetkiniz yok.</div><?php endif; endif; layout_end();
}

function render_content(): void
{
    $current=require_content_manager(); $admin=$current['role']==='admin';
    $courseScope=$admin?'1=1':'(c.teacher_id=? OR c.class_id IN (SELECT class_id FROM class_members WHERE user_id=? AND member_role=\'teacher\'))';
    $scopeParams=$admin?[]:[$current['id'],$current['id']];
    $lessons=rows("SELECT l.*,c.title course_title FROM lessons l JOIN courses c ON c.id=l.course_id WHERE {$courseScope} ORDER BY l.created_at DESC",$scopeParams);
    $editing=isset($_GET['id'])?(rows('SELECT * FROM lessons WHERE id=?',[(int)$_GET['id']])[0]??null):null;
    if($editing&&!managed_course((int)$editing['course_id'])) $editing=null;
    $courses=rows("SELECT c.id,c.title FROM courses c WHERE {$courseScope} ORDER BY c.title",$scopeParams);
    layout_start('İçerik yönetimi','content');
    ?><div class="page-heading"><div><div class="eyebrow">İÇERİK KÜTÜPHANESİ</div><h1>İçerik yönetimi<span class="heading-period">.</span></h1><p>Video, görsel, dosya ve ders dokümanlarını yönet.</p></div><button class="button button-primary" data-modal="content-modal">＋ İçerik ekle</button></div>
    <?php if(isset($_GET['new'])||$editing): ?><section class="panel editor-panel"><div class="panel-heading"><div><h3><?= $editing?'İçeriği düzenle':'Yeni içerik ekle' ?></h3><p>İçeriğini yetkili olduğun bir derse bağla.</p></div><a href="?page=content">Kapat ×</a></div><form method="post" enctype="multipart/form-data" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="manage_save_content"><input type="hidden" name="id" value="<?= (int)($editing['id']??0) ?>"><div class="form-row"><label>İçerik başlığı<input name="title" required value="<?= e($editing['title']??'') ?>"></label><label>Bağlı ders<select name="course_id" required><?php foreach($courses as $course): ?><option value="<?= (int)$course['id'] ?>" <?= ($editing['course_id']??0)==$course['id']?'selected':'' ?>><?= e($course['title']) ?></option><?php endforeach; ?></select></label></div><label>Açıklama<textarea name="description" rows="3"><?= e($editing['description']??'') ?></textarea></label><div class="form-row"><label>Video bağlantısı <small>(isteğe bağlı)</small><input name="video_url" type="url" value="<?= e($editing['video_url']??'') ?>" placeholder="https://..."></label><label>Dosya <?= $editing&&$editing['file_path']?'<small>Mevcut: '.e($editing['original_name']).'</small>':'' ?><input type="file" name="file" accept=".jpg,.jpeg,.png,.webp,.mp4,.webm,.pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt"></label><label>Sıralama<input name="sort_order" type="number" value="<?= (int)($editing['sort_order']??0) ?>"></label></div><button class="button button-primary">İçeriği kaydet <span>↗</span></button></form></section><?php endif; ?>
    <div class="panel"><div class="panel-heading"><div><h3>Yüklenen içerikler</h3><p><?= count($lessons) ?> içerik · Video, resim ve belgeler</p></div></div><div class="table-wrap"><table><thead><tr><th>İÇERİK</th><th>DERS</th><th>TÜR</th><th>EKLENME</th><th>İŞLEM</th></tr></thead><tbody><?php foreach($lessons as $lesson): ?><tr><td><strong><?= e($lesson['title']) ?></strong><small class="table-sub"><?= e(mb_strimwidth($lesson['description']??'',0,60,'…')) ?></small></td><td><?= e($lesson['course_title']) ?></td><td><span class="type-pill"><?= e(ucfirst($lesson['content_type'])) ?></span></td><td><?= e(date('d.m.Y',strtotime($lesson['created_at']))) ?></td><td><div class="row-actions"><a href="?page=content&id=<?= (int)$lesson['id'] ?>">Düzenle</a><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="manage_delete_content"><input type="hidden" name="id" value="<?= (int)$lesson['id'] ?>"><button data-confirm="Bu içerik silinsin mi?">Sil</button></form></div></td></tr><?php endforeach; if(!$lessons): ?><tr><td colspan="5" class="table-empty">Henüz içerik eklenmemiş.</td></tr><?php endif; ?></tbody></table></div></div>
    <div class="modal" id="content-modal"><div class="modal-card"><button class="modal-close" data-close>×</button><div class="eyebrow">İÇERİK KÜTÜPHANESİ</div><h2>Yeni içerik</h2><p>Video, resim, PDF veya doküman ekleyebilirsin.</p><a class="button button-primary button-wide" href="?page=content&new=1">İçerik formunu aç →</a></div></div><?php layout_end();
}

function render_users(): void
{
    $members=rows('SELECT u.id,u.name,u.email,u.role,u.created_at,GROUP_CONCAT(DISTINCT c.name SEPARATOR \', \') class_names,GROUP_CONCAT(DISTINCT co.title SEPARATOR \', \') course_names FROM users u LEFT JOIN class_members cm ON cm.user_id=u.id LEFT JOIN classes c ON c.id=cm.class_id LEFT JOIN course_members cme ON cme.user_id=u.id LEFT JOIN courses co ON co.id=cme.course_id GROUP BY u.id ORDER BY u.created_at DESC');
    $editing=isset($_GET['id'])?(rows('SELECT * FROM users WHERE id=? LIMIT 1',[(int)$_GET['id']])[0]??null):null;
    $editingClassIds=$editing?array_map('intval',array_column(rows('SELECT class_id FROM class_members WHERE user_id=?',[$editing['id']]),'class_id')):[];
    $editingCourseIds=$editing?array_map('intval',array_column(rows('SELECT course_id FROM course_members WHERE user_id=?',[$editing['id']]),'course_id')):[];
    $allClasses=rows('SELECT id,name FROM classes ORDER BY grade,name');
    $allCourses=rows('SELECT c.id,c.title,c.class_id,cl.name class_name FROM courses c LEFT JOIN classes cl ON cl.id=c.class_id ORDER BY cl.grade,c.title');
    layout_start('Kullanıcılar','users');
    ?><div class="page-heading"><div><div class="eyebrow">KULLANICI VE SINIF YÖNETİMİ</div><h1>Kullanıcılar<span class="heading-period">.</span></h1><p>Öğretmenleri birden fazla sınıfa, öğrencileri birden fazla derse ata.</p></div><a href="?page=users&new=1" class="button button-primary">＋ Kullanıcı ekle</a></div>
    <?php if(isset($_GET['new'])||$editing): ?><section class="panel editor-panel"><div class="panel-heading"><div><h3><?= $editing?'Kullanıcıyı düzenle':'Yeni kullanıcı' ?></h3><p>Birden fazla sınıf ve öğrenci için birden fazla ders seçebilirsin.</p></div><a href="?page=users">Kapat ×</a></div><form method="post" class="form-stack" data-user-form><?= csrf_field() ?><input type="hidden" name="action" value="admin_save_user"><input type="hidden" name="id" value="<?= (int)($editing['id']??0) ?>"><div class="form-row"><label>Ad soyad<input name="name" required value="<?= e($editing['name']??'') ?>"></label><label>E-posta<input name="email" type="email" required value="<?= e($editing['email']??'') ?>"></label></div><label>Rol<select name="role" data-user-role><?php foreach(['student'=>'Öğrenci','teacher'=>'Öğretmen','admin'=>'Yönetici'] as $value=>$label): ?><option value="<?= $value ?>" <?= ($editing['role']??'student')===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label><div class="form-row"><label>Sınıf eşleştirmeleri <small>Birden fazla sınıf seçmek için Ctrl tuşunu basılı tutun.</small><select name="class_ids[]" multiple size="4" required data-class-assignments><?php foreach($allClasses as $class): ?><option value="<?= (int)$class['id'] ?>" <?= in_array((int)$class['id'],$editingClassIds,true)?'selected':'' ?>><?= e($class['name']) ?></option><?php endforeach; ?></select></label><label data-course-assignment>Öğrenciye atanacak dersler <small>Seçilen sınıflara ait birden fazla dersi seçebilirsiniz.</small><select name="course_ids[]" multiple size="4" data-course-assignments><?php foreach($allCourses as $course): ?><option value="<?= (int)$course['id'] ?>" data-class-id="<?= (int)($course['class_id']??0) ?>" <?= in_array((int)$course['id'],$editingCourseIds,true)?'selected':'' ?>><?= e(($course['class_name']?:'Genel').' · '.$course['title']) ?></option><?php endforeach; ?></select></label></div><label>Şifre <?= $editing?'<small>(değiştirmek istemiyorsan boş bırak)</small>':'' ?><input name="password" type="password" <?= $editing?'':'required minlength="8"' ?> minlength="8"></label><button class="button button-primary">Kullanıcıyı kaydet <span>↗</span></button></form></section><?php endif; ?>
    <section class="panel"><div class="panel-heading"><div><h3>Kayıtlı kullanıcılar</h3><p><?= count($members) ?> hesap · Öğrenci, öğretmen ve yöneticiler</p></div><div class="table-search"><span>⌕</span><input placeholder="Kullanıcı ara..." data-table-search></div></div><div class="table-wrap"><table data-search-table><thead><tr><th>KULLANICI</th><th>ROL</th><th>SINIFLAR / DERSLER</th><th>KAYIT TARİHİ</th><th>İŞLEM</th></tr></thead><tbody><?php foreach($members as $member): ?><tr><td><div class="member-cell"><span class="avatar"><?= e(mb_strtoupper(mb_substr($member['name'],0,1))) ?></span><span><strong><?= e($member['name']) ?></strong><small><?= e($member['email']) ?></small></span></div></td><td><span class="role-pill role-<?= e($member['role']) ?>"><?= ['admin'=>'Yönetici','teacher'=>'Öğretmen','student'=>'Öğrenci'][$member['role']] ?></span></td><td><?= e($member['class_names']?:'—') ?><small class="table-sub"><?= e($member['course_names']?:'Henüz ders atanmamış') ?></small></td><td><?= e(date('d.m.Y',strtotime($member['created_at']))) ?></td><td><div class="row-actions"><a href="?page=users&id=<?= (int)$member['id'] ?>">Düzenle</a><?php if((int)$member['id']!==(int)user()['id']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="admin_delete_user"><input type="hidden" name="id" value="<?= (int)$member['id'] ?>"><button data-confirm="Bu kullanıcı silinsin mi?">Sil</button></form><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div></section><?php layout_end();
}

function render_quizzes(): void
{
    $current=user(); $admin=$current['role']==='admin'; $teacher=$current['role']==='teacher';
    $scope=$admin?'1=1':($teacher?"(c.teacher_id=? OR c.class_id IN (SELECT class_id FROM class_members WHERE user_id=? AND member_role='teacher'))":"q.is_published=1 AND EXISTS (SELECT 1 FROM course_members cm WHERE cm.course_id=c.id AND cm.user_id=?)");
    $scopeParams=$admin?[]:($teacher?[$current['id'],$current['id']]:[$current['id']]);
    $quizzes=rows("SELECT q.*,c.title course_title,(SELECT COUNT(*) FROM questions WHERE quiz_id=q.id) question_count,(SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id=q.id AND user_id=?) attempts,(SELECT MAX(score) FROM quiz_attempts WHERE quiz_id=q.id AND user_id=?) best_score FROM quizzes q JOIN courses c ON c.id=q.course_id WHERE {$scope} ORDER BY q.created_at DESC",array_merge([$current['id'],$current['id']],$scopeParams));
    layout_start('Sınavlar','quizzes');
    $editCourses=$admin
        ? rows('SELECT id,title FROM courses ORDER BY title')
        : ($teacher
            ? rows("SELECT c.id,c.title FROM courses c WHERE c.teacher_id=? OR c.class_id IN (SELECT class_id FROM class_members WHERE user_id=? AND member_role='teacher') ORDER BY c.title",[$current['id'],$current['id']])
            : []);
    ?><div class="page-heading"><div><div class="eyebrow"><?= $admin||$teacher?'ÖLÇME VE DEĞERLENDİRME':'BİLGİNİ PEKİŞTİR' ?></div><h1><?= $admin||$teacher?'Sınav yönetimi':'Sınavlarım' ?><span class="heading-period">.</span></h1><p><?= $admin?'Sınavları oluştur ve yayınla.':($teacher?'Atandığın sınıfların dersleri için sınav oluştur ve yönet.':'Sana atanmış derslerdeki sınavlarla öğrendiklerini test et.') ?></p></div><?php if($admin||$teacher): ?><a class="button button-primary" href="?page=quizzes&new=1">＋ Sınav oluştur</a><?php endif; ?></div>
    <?php if(($admin||$teacher)&&isset($_GET['new'])): ?><section class="panel editor-panel"><div class="panel-heading"><div><h3>Yeni sınav oluştur</h3><p>Sınıfındaki bir derse bağla ve çoktan seçmeli sorular ekle.</p></div><a href="?page=quizzes">Kapat ×</a></div><form method="post" class="form-stack"><?= csrf_field() ?><input type="hidden" name="action" value="manage_save_quiz"><div class="form-row"><label>Sınav adı<input name="title" required placeholder="Örn. Python temelleri değerlendirme"></label><label>Bağlı ders<select name="course_id" required><?php foreach($editCourses as $course): ?><option value="<?= (int)$course['id'] ?>"><?= e($course['title']) ?></option><?php endforeach; ?></select></label></div><label>Açıklama<textarea name="description" rows="2"></textarea></label><label class="check-label"><input type="checkbox" name="is_published" checked><span>Sınavı öğrencilere yayınla</span></label><div class="question-builder"><h4>Sorular <button class="text-button" type="button" data-add-question>＋ Soru ekle</button></h4><div data-question-list></div></div><button class="button button-primary">Sınavı kaydet <span>↗</span></button></form></section><?php endif; ?>
    <div class="quiz-grid"><?php if(!$quizzes): ?><div class="empty-state small-empty"><span class="empty-icon">✎</span><h3>Henüz sınav bulunmuyor</h3><p><?= $teacher?'Sınıflarındaki dersler için sınav oluşturabilirsin.':($admin?'Sınavlar eklendiğinde burada görüntülenecek.':'Yöneticin ders ve sınav ataması yaptığında burada görüntülenecek.') ?></p></div><?php endif; foreach($quizzes as $quiz): ?><article class="quiz-card"><div class="quiz-card-top"><div class="quiz-icon">✎</div><?php if($admin||$teacher): ?><span class="status-chip <?= $quiz['is_published']?'':'status-draft' ?>"><?= $quiz['is_published']?'Yayında':'Taslak' ?></span><?php elseif($quiz['best_score']!==null): ?><span class="score-pill">En iyi: <?= (int)$quiz['best_score'] ?>%</span><?php endif; ?></div><div class="course-level"><?= e($quiz['course_title']) ?></div><h3><?= e($quiz['title']) ?></h3><p><?= e($quiz['description']?:'Öğrendiklerini test et ve gelişimini takip et.') ?></p><div class="quiz-meta"><span>☷ <?= (int)$quiz['question_count'] ?> soru</span><?php if(!$admin&&!$teacher): ?><span>↻ <?= (int)$quiz['attempts'] ?> deneme</span><?php endif; ?></div><?php if($admin||$teacher): ?><div class="admin-card-actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $admin?'admin_delete_quiz':'manage_delete_quiz' ?>"><input type="hidden" name="id" value="<?= (int)$quiz['id'] ?>"><button data-confirm="Sınav ve sonuçları silinsin mi?">Sil</button></form></div><?php else: ?><a class="button button-primary button-wide" href="?page=quiz&id=<?= (int)$quiz['id'] ?>">Sınava başla <span>↗</span></a><?php endif; ?></article><?php endforeach; ?></div><?php layout_end();
}

function render_lesson(): void
{
    $lessonId=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
    $lesson=rows('SELECT l.*,c.title course_title,c.track FROM lessons l JOIN courses c ON c.id=l.course_id WHERE l.id=?',[$lessonId])[0]??null;
    if(!$lesson) { flash('Ders bulunamadı.','error'); redirect('?page=courses'); }
    if(!can_access_course((int)$lesson['course_id'],user())) { http_response_code(403); exit('Bu derse erişim yetkiniz yok.'); }
    $done=(bool)scalar('SELECT is_completed FROM progress WHERE user_id=? AND lesson_id=?',[user()['id'],$lessonId]);
    layout_start($lesson['title'],'courses');
    ?><a href="?page=courses&open=<?= (int)$lesson['course_id'] ?>" class="back-link">← <?= e($lesson['course_title']) ?> dersine dön</a><div class="lesson-layout"><article class="lesson-main"><div class="eyebrow"><?= e($lesson['track']) ?> · <?= e(ucfirst($lesson['content_type'])) ?></div><h1><?= e($lesson['title']) ?></h1><p class="lesson-description"><?= e($lesson['description']??'') ?></p>
    <?php if($lesson['content_type']==='video'&&$lesson['video_url']): ?><div class="video-frame"><iframe src="<?= e($lesson['video_url']) ?>" title="<?= e($lesson['title']) ?>" allowfullscreen></iframe></div><?php elseif($lesson['file_path']&&$lesson['content_type']==='video'): ?><video class="video-frame" controls src="<?= e($lesson['file_path']) ?>"></video><?php elseif($lesson['file_path']&&$lesson['content_type']==='image'): ?><img class="lesson-image" src="<?= e($lesson['file_path']) ?>" alt="<?= e($lesson['title']) ?>"><?php elseif($lesson['file_path']): ?><div class="document-download"><span>▤</span><div><strong><?= e($lesson['original_name']) ?></strong><small>Bu dersin ek dokümanı</small></div><a class="button button-primary" href="<?= e($lesson['file_path']) ?>" download>Dosyayı indir ↓</a></div><?php else: ?><div class="lesson-placeholder"><span><?= $lesson['content_type']==='video'?'▶':($lesson['content_type']==='image'?'▧':'▤') ?></span><p>Bu derse ait ek içerik bulunmuyor.</p></div><?php endif; ?>
    <?php if($lesson['file_path']&&$lesson['content_type']!=='document'): ?><div class="lesson-file-link"><a href="<?= e($lesson['file_path']) ?>" download>Ek dosyayı indir: <?= e($lesson['original_name']) ?> ↓</a></div><?php endif; ?>
    <div class="lesson-complete"><?php if($done): ?><span class="completed-label">✓ Bu dersi tamamladın</span><?php else: ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="finish_lesson"><input type="hidden" name="lesson_id" value="<?= (int)$lessonId ?>"><button class="button button-primary">Dersi tamamlandı olarak işaretle <span>✓</span></button></form><?php endif; ?><a href="?page=quizzes&course=<?= (int)$lesson['course_id'] ?>">Bu dersin sınavları →</a></div></article><aside class="lesson-aside"><div class="panel"><div class="eyebrow">ÖĞRENME İPUCU</div><h3>Kendi hızında ilerle.</h3><p>Not al, dene ve öğrendiklerini küçük projelerde kullan. Her adım gelişiminin bir parçası.</p><div class="aside-motif">✳</div></div></aside></div><?php layout_end();
}

function render_quiz(): void
{
    $quizId=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
    $quiz=rows('SELECT q.*,c.title course_title FROM quizzes q JOIN courses c ON c.id=q.course_id WHERE q.id=? AND q.is_published=1',[$quizId])[0]??null;
    if(!$quiz) { flash('Sınav bulunamadı.','error'); redirect('?page=quizzes'); }
    if(!can_access_course((int)$quiz['course_id'],user())) { http_response_code(403); exit('Bu sınava erişim yetkiniz yok.'); }
    $questions=rows('SELECT id,question_text,option_a,option_b,option_c,option_d FROM questions WHERE quiz_id=? ORDER BY id',[$quizId]);
    layout_start($quiz['title'],'quizzes');
    ?><a href="?page=quizzes" class="back-link">← Sınavlara dön</a><div class="quiz-heading"><div class="eyebrow"><?= e($quiz['course_title']) ?> · <?= count($questions) ?> SORU</div><h1><?= e($quiz['title']) ?></h1><p><?= e($quiz['description']??'') ?></p></div>
    <?php if(!$questions): ?><div class="empty-state"><span class="empty-icon">✎</span><h3>Bu sınavda henüz soru yok</h3><p>Öğretmenin soruları eklediğinde sınavı çözebilirsin.</p></div><?php else: ?><form method="post" class="quiz-form"><?= csrf_field() ?><input type="hidden" name="action" value="submit_quiz"><input type="hidden" name="quiz_id" value="<?= (int)$quizId ?>"><?php foreach($questions as $i=>$question): ?><section class="panel question-card"><div class="question-number">SORU <?= str_pad((string)($i+1),2,'0',STR_PAD_LEFT) ?> <span>· <?= $i+1 ?> / <?= count($questions) ?></span></div><h3><?= e($question['question_text']) ?></h3><?php foreach(['option_a','option_b','option_c','option_d'] as $index=>$key): ?><label class="answer-option"><input type="radio" name="answer[<?= (int)$question['id'] ?>]" value="<?= $index ?>"><span class="option-letter"><?= chr(65+$index) ?></span><span><?= e($question[$key]) ?></span></label><?php endforeach; ?></section><?php endforeach; ?><button class="button button-primary">Sınavı tamamla <span>↗</span></button></form><?php endif; layout_end();
}
