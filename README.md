# Prompt

İçerisinde öğrenci kayıt/giriş sistemi; bilişim teknolojileri alanındaki dallarla(siber güvenlik ve yazılım geliştirme) ilgili sınıfların,ders ve video listeleri; sınav ve testler; öğrencinin ilerleme dururmlarını içeren; sınıf,öğretmen/öğrenci,ders ekleme/çıkarma/güncelleme,eşleştirme yönetim paneline ve dashboarduna sahip; bu dashboardda içerik(video,resim,dosya,döküman) ekleme/silme/update yapabilen;  php backend ve mysql database i kullanan online eğitim sitesini  Ui/ux dizaynını da modern şekilde geliştirerek oluşturur musun?(Backend ve diğer gerekli araçları da yükle.)

# Database
Evet. Bu kez önceki şemayı **sıfırdan denetleyip**, özellikle veri tekrarlarını, geçmiş kayıtların kaybolmasını, aynı dersin farklı yıllarda tekrar açılmasını, öğretmen-sınıf eşleştirmelerini, öğrencinin ders kayıtlarını ve yetki güvenliğini dikkate alarak daha sağlam bir yapı kuruyorum.

 Bu tasarımda kesinleşen kurallar:

 - Giriş **kullanıcı adı + şifre** ile yapılacak.
- **E-posta zorunlu değil; hatta bu tasarımda hiç tutulmuyor.**
- **Şifre sıfırlama sistemi yok.**
- Kullanıcıları **yalnızca yönetici oluşturuyor**.
- Yönetici kullanıcı adı ve ilk şifreyi belirleyebiliyor.
- Öğretmen birden fazla sınıfa atanabiliyor.
- Öğretmen birden fazla derse atanabiliyor.
- Öğrenci bir sınıfa kayıtlı olabilir.
- Öğrenci aynı anda birden fazla derse kayıtlı olabilir.
- Bir ders birden fazla sınıfta açılabilir.
- Bir dersin birden fazla öğretmeni olabilir.
- Öğretmen-sınıf atama/çıkarma/güncelleme geçmişi tutulacak.
- Sürükle-bırak sınıf ataması desteklenecek.
- Video, PDF, resim, Word, PowerPoint, genel dosya ve metin içerikleri desteklenecek.
- Video izleme konumu saklanacak.
- Öğrenci ilerlemesi saklanacak.
- Sınavlar, sorular, seçenekler, sınav denemeleri ve cevaplar tutulacak.
- Aynı dersin **2025-2026 ve 2026-2027 dönemlerindeki kayıtları birbirine karışmayacak.**
- Silinen kullanıcı/sınıf/ders gibi ana kayıtlar mümkün olduğunca **soft delete** ile korunacak.
- Kritik yönetim işlemleri `audit_logs` içinde tutulacak.
- Yetki yalnızca frontend'de değil, **backend/database ilişkileri üzerinden de kontrol edilecek.**

---

 # 1\. Önce en önemli mimari kararı verelim

 Burada önceki şemaya göre önemli bir iyileştirme yapıyorum.

 Şunu doğrudan yapmak yerine:

```
course
   ↓
course_classes
```

 bir **ders açılışı / ders şubesi** kavramı oluşturmak daha doğru.

 Çünkü örneğin:

```
PHP Programlama
```

 dersi:

```
2025-2026 → 10-A
2025-2026 → 10-B
2026-2027 → 11-A
2026-2027 → 11-B
```

 şeklinde tekrar tekrar açılabilir.

 Bu yüzden:

```
courses
```

 ile

```
course_offerings
```

 ayrılacak.

 ## `courses`

 Dersin kendisi:

```
PHP Programlama
```

 ## `course_offerings`

 O dersin belirli bir sınıfta ve belirli bir akademik dönemde açılmış hali:

```
PHP Programlama
→ 2026-2027
→ 10-A
→ 1. Dönem
```

 Bu ayrım bütün sistemin veri bütünlüğünü ciddi şekilde artırır.

---

 # 2\. Nihai veritabanı modülleri

 Önerdiğim yapı:

```
AUTHENTICATION
────────────────────────────
users
roles
permissions
user_roles
role_permissions

USER PROFILES
────────────────────────────
student_profiles
teacher_profiles

ACADEMIC STRUCTURE
────────────────────────────
departments
branches
academic_years
terms

CLASS MANAGEMENT
────────────────────────────
classes
class_teachers
class_students

COURSE MANAGEMENT
────────────────────────────
courses
course_offerings
offering_teachers
course_enrollments

CONTENT STRUCTURE
────────────────────────────
modules
lessons
contents
files
content_files
videos
content_versions

EXAM SYSTEM
────────────────────────────
exams
exam_offerings
exam_questions
exam_choices
exam_attempts
exam_answers

PROGRESS
────────────────────────────
course_progress
lesson_progress
content_progress
video_progress

COMMUNICATION
────────────────────────────
announcements
announcement_classes
announcement_courses
announcement_students
announcement_teachers
notifications

MANAGEMENT
────────────────────────────
student_notes
audit_logs
login_logs
media_folders
settings
```

 Bunun yanında bazı tabloların ilişkileri özellikle kritik.

---

 # 3\. `users`

 Bütün kullanıcıların ana tablosu.

```
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    username VARCHAR(100) NOT NULL,

    password_hash VARCHAR(255) NOT NULL,

    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,

    phone VARCHAR(30) NULL,

    status ENUM(
        'active',
        'inactive',
        'suspended'
    ) NOT NULL DEFAULT 'active',

    last_login_at DATETIME NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    deleted_at DATETIME NULL,

    UNIQUE KEY uq_users_username (username),

    INDEX idx_users_status (status),
    INDEX idx_users_deleted_at (deleted_at)
);
```

 ## Neden `email` yok?

 Çünkü sen özellikle:

 > mail değil kullanıcı adı olsun

 dedin.

 Dolayısıyla:

```
email
email_verified_at
```

 alanlarını tamamen kaldırıyoruz.

 Giriş:

```
username
password
```

 ile yapılacak.

---

 # 4\. Kullanıcı adı

 Database:

```
UNIQUE KEY uq_users_username (username)
```

 olduğu için:

```
ahmet.yilmaz
```

 ikinci kez oluşturulamaz.

 Örneğin:

```
admin
ahmet.yilmaz
ayse.demir
ali.kaya
ogrenci20260001
```

 geçerli kullanıcı adları olabilir.

 Backend tarafında ayrıca:

```
minimum 4 karakter
maksimum 100 karakter
```

 gibi kurallar uygulanmalı.

---

 # 5\. Şifre

 Database'e:

```
123456
```

 gibi bir şifre **asla** yazılmayacak.

 Database:

```
password_hash
```

 tutacak.

 Örneğin:

```
password_hash:
$2y$12$...
```

 Laravel kullanılırsa:

```
Hash::make($password)
```

 kullanılmalı.

 Girişte:

```
Hash::check($password, $user->password_hash)
```

 yapılmalı.

---

 # 6\. Şifre sıfırlama yok

 Bu sistemde kesinlikle:

```
password_reset_tokens
password_resets
forgot_password
reset_password_requests
```

 gibi tablolar bulunmayacak.

 Login ekranında:

```
Kullanıcı adı
Şifre

[Giriş Yap]
```

 olacak.

 **“Şifremi unuttum” olmayacak.**

 Şifre değişikliği:

```
Admin
 ↓
Kullanıcı Yönetimi
 ↓
Kullanıcı
 ↓
Şifre Değiştir
```

 şeklinde yapılacak.

---

 # 7\. Roller

```
CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,

    description TEXT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_roles_slug (slug)
);
```

 Başlangıç:

```
1 → Yönetici
2 → Öğretmen
3 → Öğrenci
```

---

 # 8\. Permissions

```
CREATE TABLE permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(150) NOT NULL,
    slug VARCHAR(150) NOT NULL,

    description TEXT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_permissions_slug (slug)
);
```

 Örnek:

```
users.view
users.create
users.update
users.delete

classes.view
classes.create
classes.update
classes.delete

classes.assign_teacher
classes.remove_teacher

students.view
students.assign_class
students.remove_class

courses.view
courses.create
courses.update
courses.delete

courses.assign_teacher
courses.assign_student

content.view
content.create
content.update
content.delete
content.upload

exam.view
exam.create
exam.update
exam.delete

progress.view

reports.view
audit_logs.view
```

---

 # 9\. `user_roles`

```
CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,

    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (user_id, role_id),

    CONSTRAINT fk_user_roles_user
        FOREIGN KEY (user_id)
        REFERENCES users(id),

    CONSTRAINT fk_user_roles_role
        FOREIGN KEY (role_id)
        REFERENCES roles(id)
);
```

---

 # 10\. `role_permissions`

```
CREATE TABLE role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (role_id, permission_id),

    CONSTRAINT fk_role_permissions_role
        FOREIGN KEY (role_id)
        REFERENCES roles(id),

    CONSTRAINT fk_role_permissions_permission
        FOREIGN KEY (permission_id)
        REFERENCES permissions(id)
);
```

---

 # 11\. Öğrenci profili

```
CREATE TABLE student_profiles (
    user_id BIGINT UNSIGNED PRIMARY KEY,

    student_no VARCHAR(50) NOT NULL,

    birth_date DATE NULL,

    parent_name VARCHAR(200) NULL,

    parent_phone VARCHAR(30) NULL,

    enrollment_date DATE NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_student_profiles_student_no (student_no),

    CONSTRAINT fk_student_profiles_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
);
```

 Örnek:

```
users
────────────────────────
id: 25
username: ali.kaya
first_name: Ali
last_name: Kaya

student_profiles
────────────────────────
user_id: 25
student_no: 20260025
```

---

 # 12\. Öğretmen profili

```
CREATE TABLE teacher_profiles (
    user_id BIGINT UNSIGNED PRIMARY KEY,

    teacher_no VARCHAR(50) NOT NULL,

    employment_date DATE NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_teacher_profiles_teacher_no (teacher_no),

    CONSTRAINT fk_teacher_profiles_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
);
```

---

 # 13\. Alanlar

```
CREATE TABLE departments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(150) NOT NULL,
    slug VARCHAR(150) NOT NULL,

    description TEXT NULL,

    status ENUM(
        'active',
        'inactive'
    ) NOT NULL DEFAULT 'active',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_departments_slug (slug)
);
```

 Örneğin:

```
Bilişim Teknolojileri
```

---

 # 14\. Dallar

```
CREATE TABLE branches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    department_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(150) NOT NULL,
    slug VARCHAR(150) NOT NULL,

    description TEXT NULL,

    status ENUM(
        'active',
        'inactive'
    ) NOT NULL DEFAULT 'active',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_branches_slug (slug),

    INDEX idx_branches_department (department_id),

    CONSTRAINT fk_branches_department
        FOREIGN KEY (department_id)
        REFERENCES departments(id)
);
```

 Yapı:

```
Bilişim Teknolojileri
│
├── Yazılım Geliştirme
│
└── Siber Güvenlik
```

---

 # 15\. Akademik yıl

```
CREATE TABLE academic_years (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(50) NOT NULL,

    start_date DATE NOT NULL,
    end_date DATE NOT NULL,

    is_current BOOLEAN NOT NULL DEFAULT FALSE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_academic_years_name (name)
);
```

 Örneğin:

```
2025-2026
2026-2027
2027-2028
```

---

 # 16\. Dönem

```
CREATE TABLE terms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    academic_year_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(100) NOT NULL,

    start_date DATE NOT NULL,
    end_date DATE NOT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_terms_academic_year (academic_year_id),

    CONSTRAINT fk_terms_academic_year
        FOREIGN KEY (academic_year_id)
        REFERENCES academic_years(id)
);
```

 Örneğin:

```
2026-2027
 ├── 1. Dönem
 └── 2. Dönem
```

---

 # 17\. Sınıflar

```
CREATE TABLE classes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    branch_id BIGINT UNSIGNED NOT NULL,

    academic_year_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(100) NOT NULL,

    code VARCHAR(50) NOT NULL,

    grade TINYINT UNSIGNED NOT NULL,

    capacity SMALLINT UNSIGNED NULL,

    status ENUM(
        'active',
        'inactive',
        'archived'
    ) NOT NULL DEFAULT 'active',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    deleted_at DATETIME NULL,

    INDEX idx_classes_branch (branch_id),
    INDEX idx_classes_year (academic_year_id),
    INDEX idx_classes_status (status),

    UNIQUE KEY uq_classes_code_year (
        code,
        academic_year_id
    ),

    CONSTRAINT fk_classes_branch
        FOREIGN KEY (branch_id)
        REFERENCES branches(id),

    CONSTRAINT fk_classes_year
        FOREIGN KEY (academic_year_id)
        REFERENCES academic_years(id)
);
```

 Örneğin:

```
2026-2027
│
└── Yazılım Geliştirme
    │
    ├── 10-A
    ├── 10-B
    ├── 11-A
    └── 12-A
```

---

 # 18\. Öğretmen ↔ sınıf

 Bu sistemin en önemli tablolarından biri.

```
CREATE TABLE class_teachers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    class_id BIGINT UNSIGNED NOT NULL,

    teacher_id BIGINT UNSIGNED NOT NULL,

    role ENUM(
        'teacher',
        'advisor',
        'co_teacher'
    ) NOT NULL DEFAULT 'teacher',

    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    unassigned_at DATETIME NULL,

    assigned_by BIGINT UNSIGNED NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_class_teachers_class (
        class_id
    ),

    INDEX idx_class_teachers_teacher (
        teacher_id
    ),

    INDEX idx_class_teachers_active (
        class_id,
        teacher_id,
        unassigned_at
    ),

    CONSTRAINT fk_class_teachers_class
        FOREIGN KEY (class_id)
        REFERENCES classes(id),

    CONSTRAINT fk_class_teachers_teacher
        FOREIGN KEY (teacher_id)
        REFERENCES users(id),

    CONSTRAINT fk_class_teachers_assigned_by
        FOREIGN KEY (assigned_by)
        REFERENCES users(id)
);
```

---

 # 19\. Neden doğrudan DELETE yapmıyoruz?

 Örneğin:

```
Ahmet
10-A
01.09.2026 → 15.10.2026
```

 sonra sınıftan çıkarıldı.

 Kaydı silersek geçmiş kaybolur.

 Bunun yerine:

```
assigned_at:
2026-09-01

unassigned_at:
2026-10-15
```

 olacak.

 Aktif öğretmen:

```
unassigned_at IS NULL
```

 ile bulunur.

---

 # 20\. Sürükle-bırak öğretmen sınıf ataması

 UI:

```
Öğretmen
Ahmet Yılmaz

ATANMIŞ SINIFLAR
────────────────
[10-A]
[10-B]
[11-A]

TÜM SINIFLAR
────────────────
[10-A] ✓
[10-B] ✓
[11-A] ✓
[11-B]
[12-A]
```

 Sürükleyip:

```
11-B
```

 atanan bölüme bırakınca:

```
class_teachers
```

 insert edilir.

 Çıkarınca:

```
unassigned_at = NOW()
```

 yapılır.

---

 # 21\. Öğrenci ↔ sınıf

```
CREATE TABLE class_students (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    class_id BIGINT UNSIGNED NOT NULL,

    student_id BIGINT UNSIGNED NOT NULL,

    enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    left_at DATETIME NULL,

    status ENUM(
        'active',
        'transferred',
        'completed',
        'removed'
    ) NOT NULL DEFAULT 'active',

    assigned_by BIGINT UNSIGNED NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_class_students_class (
        class_id
    ),

    INDEX idx_class_students_student (
        student_id
    ),

    CONSTRAINT fk_class_students_class
        FOREIGN KEY (class_id)
        REFERENCES classes(id),

    CONSTRAINT fk_class_students_student
        FOREIGN KEY (student_id)
        REFERENCES users(id),

    CONSTRAINT fk_class_students_assigned_by
        FOREIGN KEY (assigned_by)
        REFERENCES users(id)
);
```

---

 # 22\. Burada önemli bir kural

 Bir öğrencinin aynı anda **iki aktif sınıfta** bulunması normalde engellenmeli.

 Örneğin:

```
Ali
├── 10-A → aktif
└── 10-B → aktif
```

 olmamalı.

 MySQL'de bunu `UNIQUE` ile doğrudan:

```
student_id + left_at
```

 şeklinde çözmek güvenilir değil.

 Bu yüzden backend servisinde transaction içinde:

```
1. Öğrencinin aktif sınıfını bul
2. Varsa kapat
3. Yeni sınıfa ata
4. Transaction commit
```

 uygulanmalı.

 Bu iş için ayrıca:

```
class_enrollment_service
```

 gibi bir servis katmanı öneriyorum.

---

 # 23\. Ders ana tablosu

```
CREATE TABLE courses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    branch_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(200) NOT NULL,

    slug VARCHAR(200) NOT NULL,

    code VARCHAR(50) NULL,

    short_description TEXT NULL,

    description LONGTEXT NULL,

    thumbnail_file_id BIGINT UNSIGNED NULL,

    level ENUM(
        'beginner',
        'intermediate',
        'advanced'
    ) NULL,

    estimated_duration_minutes INT UNSIGNED NULL,

    status ENUM(
        'draft',
        'published',
        'archived'
    ) NOT NULL DEFAULT 'draft',

    created_by BIGINT UNSIGNED NOT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    deleted_at DATETIME NULL,

    INDEX idx_courses_branch (branch_id),
    INDEX idx_courses_status (status),

    UNIQUE KEY uq_courses_slug (slug),

    CONSTRAINT fk_courses_branch
        FOREIGN KEY (branch_id)
        REFERENCES branches(id),

    CONSTRAINT fk_courses_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
);
```

 Örnek:

```
PHP Programlama
```

 burada yalnızca **dersin kendisi**.

---

 # 24\. Dersin açıldığı sınıf/dönem

 Asıl kritik tablo:

```
CREATE TABLE course_offerings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    course_id BIGINT UNSIGNED NOT NULL,

    class_id BIGINT UNSIGNED NOT NULL,

    term_id BIGINT UNSIGNED NOT NULL,

    name VARCHAR(255) NULL,

    start_date DATE NULL,

    end_date DATE NULL,

    status ENUM(
        'planned',
        'active',
        'completed',
        'cancelled'
    ) NOT NULL DEFAULT 'planned',

    created_by BIGINT UNSIGNED NOT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_course_offerings_course (
        course_id
    ),

    INDEX idx_course_offerings_class (
        class_id
    ),

    INDEX idx_course_offerings_term (
        term_id
    ),

    UNIQUE KEY uq_course_offering (
        course_id,
        class_id,
        term_id
    ),

    CONSTRAINT fk_course_offerings_course
        FOREIGN KEY (course_id)
        REFERENCES courses(id),

    CONSTRAINT fk_course_offerings_class
        FOREIGN KEY (class_id)
        REFERENCES classes(id),

    CONSTRAINT fk_course_offerings_term
        FOREIGN KEY (term_id)
        REFERENCES terms(id),

    CONSTRAINT fk_course_offerings_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
);
```

 Bu çok önemli.

 Örneğin:

```
PHP Programlama
```

 tek ders.

 Ama:

```
Offering #1
PHP → 10-A → 2026-2027 1. Dönem

Offering #2
PHP → 10-B → 2026-2027 1. Dönem

Offering #3
PHP → 11-A → 2027-2028 1. Dönem
```

 olabilir.

---

 # 25\. Öğretmen ↔ ders açılışı

```
CREATE TABLE offering_teachers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    offering_id BIGINT UNSIGNED NOT NULL,

    teacher_id BIGINT UNSIGNED NOT NULL,

    role ENUM(
        'teacher',
        'lead_teacher',
        'assistant'
    ) NOT NULL DEFAULT 'teacher',

    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    unassigned_at DATETIME NULL,

    assigned_by BIGINT UNSIGNED NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_offering_teachers_offering (
        offering_id
    ),

    INDEX idx_offering_teachers_teacher (
        teacher_id
    ),

    CONSTRAINT fk_offering_teachers_offering
        FOREIGN KEY (offering_id)
        REFERENCES course_offerings(id),

    CONSTRAINT fk_offering_teachers_teacher
        FOREIGN KEY (teacher_id)
        REFERENCES users(id),

    CONSTRAINT fk_offering_teachers_assigned_by
        FOREIGN KEY (assigned_by)
        REFERENCES users(id)
);
```

 Bu sayede:

```
PHP → 10-A
```

 dersini:

```
Ahmet
Ayşe
```

 birlikte verebilir.

---

 # 26\. Öğrenci ↔ ders açılışı

 Burada önceki şemaya göre daha doğru bir isim kullanıyorum:

```
course_enrollments
```

```
CREATE TABLE course_enrollments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    offering_id BIGINT UNSIGNED NOT NULL,

    student_id BIGINT UNSIGNED NOT NULL,

    enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    withdrawn_at DATETIME NULL,

    status ENUM(
        'active',
        'completed',
        'withdrawn',
        'failed'
    ) NOT NULL DEFAULT 'active',

    enrolled_by BIGINT UNSIGNED NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_course_enrollments_offering (
        offering_id
    ),

    INDEX idx_course_enrollments_student (
        student_id
    ),

    UNIQUE KEY uq_course_enrollment (
        offering_id,
        student_id
    ),

    CONSTRAINT fk_course_enrollments_offering
        FOREIGN KEY (offering_id)
        REFERENCES course_offerings(id),

    CONSTRAINT fk_course_enrollments_student
        FOREIGN KEY (student_id)
        REFERENCES users(id),

    CONSTRAINT fk_course_enrollments_enrolled_by
        FOREIGN KEY (enrolled_by)
        REFERENCES users(id)
);
```

---

 # 27\. Bu yapı öğrencinin birden fazla derse atanmasını nasıl çözüyor?

 Örneğin:

```
Ali
```

 öğrencisi:

```
10-A
```

 sınıfında.

 Dersler:

```
PHP
MySQL
JavaScript
Siber Güvenlik
```

 olabilir.

 Database:

```
course_enrollments

Ali → PHP
Ali → MySQL
Ali → JavaScript
Ali → Siber Güvenlik
```

 şeklinde olur.

 Bu tam olarak istediğin:

 > Belirli sınıftaki öğrenci birden fazla derse atanabilir.

 özelliğini karşılar.

---

 # 28\. Modüller

```
CREATE TABLE modules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    course_id BIGINT UNSIGNED NOT NULL,

    title VARCHAR(200) NOT NULL,

    description TEXT NULL,

    sort_order INT NOT NULL DEFAULT 0,

    status ENUM(
        'draft',
        'published',
        'archived'
    ) NOT NULL DEFAULT 'draft',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_modules_course_order (
        course_id,
        sort_order
    ),

    CONSTRAINT fk_modules_course
        FOREIGN KEY (course_id)
        REFERENCES courses(id)
);
```

---

 # 29\. Ders konuları

```
CREATE TABLE lessons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    module_id BIGINT UNSIGNED NOT NULL,

    title VARCHAR(200) NOT NULL,

    slug VARCHAR(200) NOT NULL,

    description TEXT NULL,

    sort_order INT NOT NULL DEFAULT 0,

    estimated_duration_minutes INT UNSIGNED NULL,

    is_required BOOLEAN NOT NULL DEFAULT TRUE,

    status ENUM(
        'draft',
        'published',
        'archived'
    ) NOT NULL DEFAULT 'draft',

    created_by BIGINT UNSIGNED NOT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    deleted_at DATETIME NULL,

    INDEX idx_lessons_module_order (
        module_id,
        sort_order
    ),

    CONSTRAINT fk_lessons_module
        FOREIGN KEY (module_id)
        REFERENCES modules(id),

    CONSTRAINT fk_lessons_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
);
```

---

 # 30\. İçerikler

```
CREATE TABLE contents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    lesson_id BIGINT UNSIGNED NOT NULL,

    title VARCHAR(255) NOT NULL,

    content_type ENUM(
        'video',
        'image',
        'pdf',
        'document',
        'presentation',
        'file',
        'text',
        'link'
    ) NOT NULL,

    description TEXT NULL,

    text_content LONGTEXT NULL,

    external_url VARCHAR(1000) NULL,

    sort_order INT NOT NULL DEFAULT 0,

    is_required BOOLEAN NOT NULL DEFAULT FALSE,

    status ENUM(
        'draft',
        'published',
        'archived'
    ) NOT NULL DEFAULT 'draft',

    created_by BIGINT UNSIGNED NOT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    deleted_at DATETIME NULL,

    INDEX idx_contents_lesson_order (
        lesson_id,
        sort_order
    ),

    INDEX idx_contents_type (
        content_type
    ),

    CONSTRAINT fk_contents_lesson
        FOREIGN KEY (lesson_id)
        REFERENCES lessons(id),

    CONSTRAINT fk_contents_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
);
```

---

 # 31\. Dosya tablosu

 Dosyanın kendisini MySQL'e `BLOB` olarak koymayı önermiyorum.

 MySQL:

```
dosya bilgisi
```

 tutacak.

 Gerçek dosya:

```
storage/
```

 veya S3/MinIO benzeri object storage'da tutulabilir.

```
CREATE TABLE files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    original_name VARCHAR(255) NOT NULL,

    stored_name VARCHAR(255) NOT NULL,

    path VARCHAR(1000) NOT NULL,

    disk VARCHAR(100) NOT NULL DEFAULT 'local',

    mime_type VARCHAR(150) NOT NULL,

    extension VARCHAR(30) NULL,

    size_bytes BIGINT UNSIGNED NOT NULL,

    checksum VARCHAR(128) NULL,

    uploaded_by BIGINT UNSIGNED NOT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    deleted_at DATETIME NULL,

    INDEX idx_files_uploaded_by (
        uploaded_by
    ),

    INDEX idx_files_checksum (
        checksum
    ),

    CONSTRAINT fk_files_uploaded_by
        FOREIGN KEY (uploaded_by)
        REFERENCES users(id)
);
```

---

 # 32\. İçerik ↔ dosya

```
CREATE TABLE content_files (
    content_id BIGINT UNSIGNED NOT NULL,

    file_id BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (
        content_id,
        file_id
    ),

    CONSTRAINT fk_content_files_content
        FOREIGN KEY (content_id)
        REFERENCES contents(id),

    CONSTRAINT fk_content_files_file
        FOREIGN KEY (file_id)
        REFERENCES files(id)
);
```

---

 # 33\. Video

```
CREATE TABLE videos (
    content_id BIGINT UNSIGNED PRIMARY KEY,

    file_id BIGINT UNSIGNED NULL,

    provider ENUM(
        'local',
        'youtube',
        'vimeo',
        'external'
    ) NOT NULL DEFAULT 'local',

    video_url VARCHAR(1000) NULL,

    duration_seconds INT UNSIGNED NULL,

    thumbnail_file_id BIGINT UNSIGNED NULL,

    CONSTRAINT fk_videos_content
        FOREIGN KEY (content_id)
        REFERENCES contents(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_videos_file
        FOREIGN KEY (file_id)
        REFERENCES files(id),

    CONSTRAINT fk_videos_thumbnail
        FOREIGN KEY (thumbnail_file_id)
        REFERENCES files(id)
);
```

---

 # 34\. İçerik versiyonları

 Öğretmen bir PDF'yi güncellerse eski dosya kaybolmamalı.

```
CREATE TABLE content_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    content_id BIGINT UNSIGNED NOT NULL,

    version_number INT UNSIGNED NOT NULL,

    file_id BIGINT UNSIGNED NULL,

    text_content LONGTEXT NULL,

    external_url VARCHAR(1000) NULL,

    created_by BIGINT UNSIGNED NOT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_content_version (
        content_id,
        version_number
    ),

    CONSTRAINT fk_content_versions_content
        FOREIGN KEY (content_id)
        REFERENCES contents(id),

    CONSTRAINT fk_content_versions_file
        FOREIGN KEY (file_id)
        REFERENCES files(id),

    CONSTRAINT fk_content_versions_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
);
```

---

 # 35\. Sınav

```
CREATE TABLE exams (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    lesson_id BIGINT UNSIGNED NULL,

    title VARCHAR(255) NOT NULL,

    description TEXT NULL,

    duration_minutes INT UNSIGNED NULL,

    pass_score DECIMAL(5,2) NOT NULL DEFAULT 50.00,

    max_attempts INT UNSIGNED NULL,

    shuffle_questions BOOLEAN NOT NULL DEFAULT FALSE,

    shuffle_answers BOOLEAN NOT NULL DEFAULT FALSE,

    show_result_immediately BOOLEAN NOT NULL DEFAULT TRUE,

    show_correct_answers BOOLEAN NOT NULL DEFAULT FALSE,

    status ENUM(
        'draft',
        'published',
        'closed'
    ) NOT NULL DEFAULT 'draft',

    created_by BIGINT UNSIGNED NOT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_exams_lesson (
        lesson_id
    ),

    CONSTRAINT fk_exams_lesson
        FOREIGN KEY (lesson_id)
        REFERENCES lessons(id),

    CONSTRAINT fk_exams_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
);
```

---

 # 36\. Sınavın hangi sınıf/ders açılışına ait olduğu

 Burada çok önemli bir iyileştirme yapıyorum.

 Bir sınav sadece `exam.lesson_id` ile bağlı olursa, öğretmenin aynı sınavı farklı sınıflara farklı şekilde vermesi zorlaşabilir.

 Bu yüzden:

```
CREATE TABLE exam_offerings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    exam_id BIGINT UNSIGNED NOT NULL,

    offering_id BIGINT UNSIGNED NOT NULL,

    start_at DATETIME NULL,

    end_at DATETIME NULL,

    max_attempts INT UNSIGNED NULL,

    status ENUM(
        'scheduled',
        'active',
        'closed'
    ) NOT NULL DEFAULT 'scheduled',

    created_by BIGINT UNSIGNED NOT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_exam_offering (
        exam_id,
        offering_id
    ),

    INDEX idx_exam_offerings_exam (
        exam_id
    ),

    INDEX idx_exam_offerings_offering (
        offering_id
    ),

    CONSTRAINT fk_exam_offerings_exam
        FOREIGN KEY (exam_id)
        REFERENCES exams(id),

    CONSTRAINT fk_exam_offerings_offering
        FOREIGN KEY (offering_id)
        REFERENCES course_offerings(id),

    CONSTRAINT fk_exam_offerings_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
);
```

 Böylece:

```
PHP Final Sınavı
        │
        ├── PHP → 10-A
        └── PHP → 10-B
```

 şeklinde olabilir.

---

 # 37\. Sorular

```
CREATE TABLE exam_questions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    exam_id BIGINT UNSIGNED NOT NULL,

    question_text LONGTEXT NOT NULL,

    question_type ENUM(
        'single_choice',
        'multiple_choice',
        'true_false',
        'short_answer'
    ) NOT NULL,

    points DECIMAL(6,2) NOT NULL DEFAULT 1.00,

    sort_order INT NOT NULL DEFAULT 0,

    explanation TEXT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_exam_questions_exam_order (
        exam_id,
        sort_order
    ),

    CONSTRAINT fk_exam_questions_exam
        FOREIGN KEY (exam_id)
        REFERENCES exams(id)
        ON DELETE CASCADE
);
```

---

 # 38\. Şıklar

```
CREATE TABLE exam_choices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    question_id BIGINT UNSIGNED NOT NULL,

    choice_text TEXT NOT NULL,

    is_correct BOOLEAN NOT NULL DEFAULT FALSE,

    sort_order INT NOT NULL DEFAULT 0,

    INDEX idx_exam_choices_question (
        question_id
    ),

    CONSTRAINT fk_exam_choices_question
        FOREIGN KEY (question_id)
        REFERENCES exam_questions(id)
        ON DELETE CASCADE
);
```

---

 # 39\. Sınav denemesi

```
CREATE TABLE exam_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    exam_offering_id BIGINT UNSIGNED NOT NULL,

    student_id BIGINT UNSIGNED NOT NULL,

    attempt_number INT UNSIGNED NOT NULL,

    started_at DATETIME NOT NULL,

    submitted_at DATETIME NULL,

    score DECIMAL(6,2) NULL,

    correct_count INT UNSIGNED NOT NULL DEFAULT 0,

    wrong_count INT UNSIGNED NOT NULL DEFAULT 0,

    blank_count INT UNSIGNED NOT NULL DEFAULT 0,

    status ENUM(
        'in_progress',
        'submitted',
        'expired',
        'cancelled'
    ) NOT NULL DEFAULT 'in_progress',

    UNIQUE KEY uq_exam_attempt (
        exam_offering_id,
        student_id,
        attempt_number
    ),

    INDEX idx_exam_attempts_student (
        student_id
    ),

    INDEX idx_exam_attempts_offering (
        exam_offering_id
    ),

    CONSTRAINT fk_exam_attempts_offering
        FOREIGN KEY (exam_offering_id)
        REFERENCES exam_offerings(id),

    CONSTRAINT fk_exam_attempts_student
        FOREIGN KEY (student_id)
        REFERENCES users(id)
);
```

 Bu sayede:

```
Ali
PHP Final
Attempt 1 → 55
Attempt 2 → 72
Attempt 3 → 91
```

 tutulabilir.

---

 # 40\. Cevaplar

```
CREATE TABLE exam_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    attempt_id BIGINT UNSIGNED NOT NULL,

    question_id BIGINT UNSIGNED NOT NULL,

    selected_choice_id BIGINT UNSIGNED NULL,

    answer_text LONGTEXT NULL,

    is_correct BOOLEAN NULL,

    points_earned DECIMAL(6,2) NULL,

    answered_at DATETIME NULL,

    UNIQUE KEY uq_exam_answer (
        attempt_id,
        question_id
    ),

    CONSTRAINT fk_exam_answers_attempt
        FOREIGN KEY (attempt_id)
        REFERENCES exam_attempts(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_exam_answers_question
        FOREIGN KEY (question_id)
        REFERENCES exam_questions(id),

    CONSTRAINT fk_exam_answers_choice
        FOREIGN KEY (selected_choice_id)
        REFERENCES exam_choices(id)
);
```

---

 # 41\. Ders ilerlemesi

 Burada da önemli değişiklik var.

 İlerlemeyi sadece:

```
student_id + course_id
```

 ile tutmak yeterli değil.

 Çünkü:

```
Ali → PHP → 2025
Ali → PHP → 2026
```

 iki farklı eğitim deneyimidir.

 Bu nedenle `offering_id` kullanılmalı.

```
CREATE TABLE course_progress (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    enrollment_id BIGINT UNSIGNED NOT NULL,

    progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,

    completed_lessons INT UNSIGNED NOT NULL DEFAULT 0,

    total_lessons INT UNSIGNED NOT NULL DEFAULT 0,

    first_started_at DATETIME NULL,

    last_accessed_at DATETIME NULL,

    completed_at DATETIME NULL,

    UNIQUE KEY uq_course_progress_enrollment (
        enrollment_id
    ),

    CONSTRAINT fk_course_progress_enrollment
        FOREIGN KEY (enrollment_id)
        REFERENCES course_enrollments(id)
);
```

---

 # 42\. Konu ilerlemesi

```
CREATE TABLE lesson_progress (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    enrollment_id BIGINT UNSIGNED NOT NULL,

    lesson_id BIGINT UNSIGNED NOT NULL,

    progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,

    status ENUM(
        'not_started',
        'in_progress',
        'completed'
    ) NOT NULL DEFAULT 'not_started',

    started_at DATETIME NULL,

    last_accessed_at DATETIME NULL,

    completed_at DATETIME NULL,

    UNIQUE KEY uq_lesson_progress (
        enrollment_id,
        lesson_id
    ),

    CONSTRAINT fk_lesson_progress_enrollment
        FOREIGN KEY (enrollment_id)
        REFERENCES course_enrollments(id),

    CONSTRAINT fk_lesson_progress_lesson
        FOREIGN KEY (lesson_id)
        REFERENCES lessons(id)
);
```

---

 # 43\. İçerik ilerlemesi

```
CREATE TABLE content_progress (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    enrollment_id BIGINT UNSIGNED NOT NULL,

    content_id BIGINT UNSIGNED NOT NULL,

    status ENUM(
        'not_started',
        'viewed',
        'completed'
    ) NOT NULL DEFAULT 'not_started',

    viewed_at DATETIME NULL,

    completed_at DATETIME NULL,

    UNIQUE KEY uq_content_progress (
        enrollment_id,
        content_id
    ),

    CONSTRAINT fk_content_progress_enrollment
        FOREIGN KEY (enrollment_id)
        REFERENCES course_enrollments(id),

    CONSTRAINT fk_content_progress_content
        FOREIGN KEY (content_id)
        REFERENCES contents(id)
);
```

---

 # 44\. Video ilerlemesi

 Bu tablo özellikle öğrencinin videoyu kaldığı yerden devam ettirmesi için.

```
CREATE TABLE video_progress (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    enrollment_id BIGINT UNSIGNED NOT NULL,

    video_content_id BIGINT UNSIGNED NOT NULL,

    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,

    watched_seconds INT UNSIGNED NOT NULL DEFAULT 0,

    last_position_seconds INT UNSIGNED NOT NULL DEFAULT 0,

    progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,

    completed BOOLEAN NOT NULL DEFAULT FALSE,

    first_started_at DATETIME NULL,

    last_watched_at DATETIME NULL,

    completed_at DATETIME NULL,

    UNIQUE KEY uq_video_progress (
        enrollment_id,
        video_content_id
    ),

    INDEX idx_video_progress_enrollment (
        enrollment_id
    ),

    CONSTRAINT fk_video_progress_enrollment
        FOREIGN KEY (enrollment_id)
        REFERENCES course_enrollments(id),

    CONSTRAINT fk_video_progress_video
        FOREIGN KEY (video_content_id)
        REFERENCES videos(content_id)
);
```

 Örneğin:

```
PHP Değişkenler.mp4
────────────────────────
Süre: 32:15
İzlenen: 18:40
Kaldığı yer: 18:40
İlerleme: 57.7%
```

---

 # 45\. Neden progress `course_enrollment_id` üzerinden?

 Bu çok önemli.

 Örneğin Ali:

```
2025-2026
PHP
10-A
```

 dersini tamamladı.

 Daha sonra:

```
2026-2027
PHP
11-A
```

 dersine yeniden kayıt oldu.

 Eski ilerleme:

```
100%
```

 yeni derse taşınmamalı.

 Bu tasarımla:

```
Enrollment #100
→ 2025 PHP
→ %100

Enrollment #450
→ 2026 PHP
→ %0
```

 olabilir.

---

 # 46\. Duyurular

 Ana duyuru:

```
CREATE TABLE announcements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    title VARCHAR(255) NOT NULL,

    content LONGTEXT NOT NULL,

    created_by BIGINT UNSIGNED NOT NULL,

    publish_at DATETIME NULL,

    expire_at DATETIME NULL,

    status ENUM(
        'draft',
        'published',
        'archived'
    ) NOT NULL DEFAULT 'draft',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_announcements_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
);
```

---

 # 47\. Duyuru → sınıf

 Burada önceki `announcement_targets` yapısını özellikle değiştirmeyi öneriyorum.

 Çünkü polymorphic:

```
target_type
target_id
```

 yapısı database foreign key bütünlüğünü zayıflatıyor.

 Bunun yerine ayrı tablolar:

```
CREATE TABLE announcement_classes (
    announcement_id BIGINT UNSIGNED NOT NULL,

    class_id BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (
        announcement_id,
        class_id
    ),

    CONSTRAINT fk_announcement_classes_announcement
        FOREIGN KEY (announcement_id)
        REFERENCES announcements(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_announcement_classes_class
        FOREIGN KEY (class_id)
        REFERENCES classes(id)
);
```

---

 # 48\. Duyuru → ders

```
CREATE TABLE announcement_courses (
    announcement_id BIGINT UNSIGNED NOT NULL,

    offering_id BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (
        announcement_id,
        offering_id
    ),

    CONSTRAINT fk_announcement_courses_announcement
        FOREIGN KEY (announcement_id)
        REFERENCES announcements(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_announcement_courses_offering
        FOREIGN KEY (offering_id)
        REFERENCES course_offerings(id)
);
```

---

 # 49\. Duyuru → öğrenci

```
CREATE TABLE announcement_students (
    announcement_id BIGINT UNSIGNED NOT NULL,

    student_id BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (
        announcement_id,
        student_id
    ),

    CONSTRAINT fk_announcement_students_announcement
        FOREIGN KEY (announcement_id)
        REFERENCES announcements(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_announcement_students_student
        FOREIGN KEY (student_id)
        REFERENCES users(id)
);
```

---

 # 50\. Duyuru → öğretmen

```
CREATE TABLE announcement_teachers (
    announcement_id BIGINT UNSIGNED NOT NULL,

    teacher_id BIGINT UNSIGNED NOT NULL,

    PRIMARY KEY (
        announcement_id,
        teacher_id
    ),

    CONSTRAINT fk_announcement_teachers_announcement
        FOREIGN KEY (announcement_id)
        REFERENCES announcements(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_announcement_teachers_teacher
        FOREIGN KEY (teacher_id)
        REFERENCES users(id)
);
```

 Bu şekilde gerçek FK bütünlüğü sağlanır.

---

 # 51\. Bildirimler

```
CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NOT NULL,

    title VARCHAR(255) NOT NULL,

    message TEXT NOT NULL,

    type VARCHAR(100) NULL,

    reference_type VARCHAR(100) NULL,

    reference_id BIGINT UNSIGNED NULL,

    is_read BOOLEAN NOT NULL DEFAULT FALSE,

    read_at DATETIME NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_notifications_user (
        user_id,
        is_read,
        created_at
    ),

    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
);
```

---

 # 52\. Öğretmen öğrenci notları

```
CREATE TABLE student_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    student_id BIGINT UNSIGNED NOT NULL,

    teacher_id BIGINT UNSIGNED NOT NULL,

    offering_id BIGINT UNSIGNED NULL,

    note TEXT NOT NULL,

    is_private BOOLEAN NOT NULL DEFAULT TRUE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_student_notes_student (
        student_id
    ),

    INDEX idx_student_notes_teacher (
        teacher_id
    ),

    CONSTRAINT fk_student_notes_student
        FOREIGN KEY (student_id)
        REFERENCES users(id),

    CONSTRAINT fk_student_notes_teacher
        FOREIGN KEY (teacher_id)
        REFERENCES users(id),

    CONSTRAINT fk_student_notes_offering
        FOREIGN KEY (offering_id)
        REFERENCES course_offerings(id)
);
```

---

 # 53\. Audit log

 Admin panelinde:

```
Ahmet öğretmene 10-A sınıfı eklendi.
```

 veya:

```
PHP dersindeki Video 3 silindi.
```

 gibi işlemler kaydedilmeli.

```
CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NULL,

    action VARCHAR(100) NOT NULL,

    entity_type VARCHAR(100) NOT NULL,

    entity_id BIGINT UNSIGNED NULL,

    old_values JSON NULL,

    new_values JSON NULL,

    ip_address VARCHAR(45) NULL,

    user_agent TEXT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_audit_entity (
        entity_type,
        entity_id
    ),

    INDEX idx_audit_user (
        user_id,
        created_at
    ),

    CONSTRAINT fk_audit_logs_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
);
```

 Örneğin:

```
user:
admin

action:
assign_teacher

entity:
class

entity_id:
15

old_values:
{}

new_values:
{
    "teacher_id": 42
}
```

---

 # 54\. Login kayıtları

 Şifre sıfırlama yok ama güvenlik nedeniyle giriş kayıtları olmalı.

```
CREATE TABLE login_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id BIGINT UNSIGNED NULL,

    username_attempted VARCHAR(100) NULL,

    ip_address VARCHAR(45) NULL,

    user_agent TEXT NULL,

    success BOOLEAN NOT NULL,

    failure_reason VARCHAR(255) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_login_logs_user (
        user_id,
        created_at
    ),

    INDEX idx_login_logs_username (
        username_attempted,
        created_at
    )
);
```

 Bu sayede:

```
Admin
 ↓
Güvenlik
 ↓
Giriş Kayıtları
```

 ekranı yapılabilir.

---

 # 55\. Medya klasörleri

 Dashboard'daki dosya yöneticisi için:

```
CREATE TABLE media_folders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    parent_id BIGINT UNSIGNED NULL,

    name VARCHAR(255) NOT NULL,

    created_by BIGINT UNSIGNED NOT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_media_folders_parent (
        parent_id
    ),

    CONSTRAINT fk_media_folders_parent
        FOREIGN KEY (parent_id)
        REFERENCES media_folders(id),

    CONSTRAINT fk_media_folders_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
);
```

 Örneğin:

```
Medya
│
├── Yazılım
│   ├── PHP
│   ├── MySQL
│   └── JavaScript
│
└── Siber Güvenlik
    ├── Linux
    ├── Ağ
    └── Güvenlik
```

---

 # 56\. Sistem ayarları

```
CREATE TABLE settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    setting_key VARCHAR(150) NOT NULL,

    setting_value LONGTEXT NULL,

    setting_type ENUM(
        'string',
        'integer',
        'boolean',
        'json'
    ) NOT NULL DEFAULT 'string',

    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_settings_key (
        setting_key
    )
);
```

 Örneğin:

```
site_name
site_logo
default_language
video_completion_percentage
max_upload_size
maintenance_mode
```

---

 # 57\. Dosya ve thumbnail ilişkisi hakkında önemli nokta

 Burada `courses.thumbnail_file_id` gibi bir alan var.

 Bu alanı oluştururken `files` tablosu henüz oluşturulmadıysa migration sırasına dikkat etmek gerekir.

 Laravel'de en sağlıklısı:

```
1. users
2. roles
3. permissions
...
20. files
21. courses
```

 şeklinde migration sırasını ayarlamak veya foreign key'i sonradan eklemek.

 Aksi halde migration:

```
courses
 → files
```

 ilişkisi nedeniyle sıralama hatası verebilir.

---

 # 58\. Tam ER ilişki ağacı

 Şimdi bütün yapıyı tek bir şemada görelim:

```
                         USERS
                           │
             ┌─────────────┼─────────────┐
             │             │             │
             ▼             ▼             ▼
         STUDENT        TEACHER        ROLES
         PROFILE        PROFILE          │
                                         ▼
                                    PERMISSIONS

DEPARTMENT
    │
    ▼
  BRANCH
    │
    ├──────────────────────┐
    ▼                      ▼
 CLASSES                 COURSES
    │                      │
    │                      │
    ├── CLASS_TEACHERS     ├── MODULES
    │                      │      │
    │                      │      ▼
    └── CLASS_STUDENTS     │   LESSONS
                           │      │
                           │      ├── CONTENTS
                           │      │      │
                           │      │      ├── FILES
                           │      │      ├── VIDEOS
                           │      │      └── VERSIONS
                           │      │
                           │      └── EXAMS
                           │             │
                           │             ├── QUESTIONS
                           │             │      └── CHOICES
                           │             │
                           │             └── EXAM OFFERINGS
                           │                    │
                           │                    ▼
                           │                  ATTEMPTS
                           │                    │
                           │                    ▼
                           │                  ANSWERS
                           │
                           ▼
                    COURSE OFFERINGS
                           │
                ┌──────────┼───────────┐
                │          │           │
                ▼          ▼           ▼
        OFFERING       COURSE       PROGRESS
        TEACHERS      ENROLLMENTS      │
                            │           ├── COURSE
                            │           ├── LESSON
                            │           ├── CONTENT
                            │           └── VIDEO
```

---

 # 59\. Çok önemli: `course` ile `course_offering` ayrımı

 Bu tasarımın en önemli farklarından biri bu.

 Örneğin:

```
COURSE

PHP Programlama
ID: 10
```

 Bu sadece dersin tanımı.

 Sonra:

```
COURSE OFFERINGS

ID 101
PHP
10-A
2026-2027
1. Dönem

ID 102
PHP
10-B
2026-2027
1. Dönem

ID 103
PHP
11-A
2027-2028
1. Dönem
```

 olabilir.

 Böylece geçmiş veriler birbirine karışmaz.

---

 # 60\. Öğretmenin sınıf yetkisi ile ders yetkisi aynı şey değil

 Bu da çok önemli.

 Öğretmen:

```
Ahmet
```

 şu sınıflara atanmış olabilir:

```
10-A
10-B
```

 Ama dersleri:

```
PHP
MySQL
```

 olabilir.

 Bir başka öğretmen:

```
Ayşe
```

 aynı:

```
10-A
```

 sınıfında olabilir ama sadece:

```
JavaScript
```

 dersini verebilir.

 Bu nedenle:

```
class_teachers
```

 ve:

```
offering_teachers
```

 ayrı tutuluyor.

 Bu güvenlik açısından çok daha doğru.

---

 # 61\. Öğretmen yetki kontrolü

 Örneğin Ahmet:

```
class_teachers
10-A
10-B
```

 ve:

```
offering_teachers
PHP → 10-A
MySQL → 10-B
```

 ilişkilerine sahip.

 Ahmet:

```
PHP
```

 dersine video ekleyebilir.

 Ama:

```
Java
```

 dersine atanmadıysa:

```
POST /api/courses/java/content
```

 yaparak video ekleyememeli.

 Backend:

```
Authenticated User
        ↓
Teacher?
        ↓
offering_teachers
        ↓
Bu offering'e atanmış mı?
        ↓
EVET → işlem
HAYIR → 403
```

 şeklinde çalışmalı.

---

 # 62\. Admin her şeyi yönetebilir

 Admin:

```
Kullanıcı
├── ekle
├── düzenle
├── aktif/pasif
├── şifre değiştir
└── rol değiştir

Sınıf
├── ekle
├── düzenle
├── sil/arsivle
├── öğretmen ata
├── öğretmen çıkar
└── öğrenci ata

Ders
├── ekle
├── düzenle
├── sil/arsivle
├── sınıfa aç
├── öğretmen ata
└── öğrenci ata

İçerik
├── video
├── PDF
├── resim
├── dosya
├── doküman
├── güncelle
└── sil

Sınav
├── oluştur
├── soru ekle
├── soru düzenle
├── soru sil
└── sonuçları görüntüle
```

---

 # 63\. Sürükle-bırak sınıf yönetimi

 Frontend:

```
┌──────────────────────────────────────────────────────────────┐
│ Ahmet Yılmaz                                                 │
├─────────────────────────┬────────────────────────────────────┤
│ MEVCUT SINIFLAR         │ ATANABİLECEK SINIFLAR             │
│                         │                                    │
│ ┌─────────┐             │ ┌─────────┐                      │
│ │ 10-A    │             │ │ 11-B    │                      │
│ └─────────┘             │ └─────────┘                      │
│                         │                                    │
│ ┌─────────┐             │ ┌─────────┐                      │
│ │ 10-B    │             │ │ 12-A    │                      │
│ └─────────┘             │ └─────────┘                      │
│                         │                                    │
└─────────────────────────┴────────────────────────────────────┘
```

 Sürükle:

```
11-B
```

 sol tarafa.

 Backend:

```
class_teachers
```

 insert.

 Birden fazla seç:

```
☑ 11-B
☑ 12-A
☑ 12-B
```

 ve:

```
[Seçilenleri Ata]
```

 dediğinde transaction ile toplu ekleme.

---

 # 64\. Öğrenci ders atama ekranı

 Örneğin:

```
Öğrenci:
Ali Kaya

Sınıf:
10-A

MEVCUT DERSLER
────────────────
☑ PHP
☑ MySQL
☐ JavaScript
☐ Linux
☐ Siber Güvenlik

[Seçilenleri Ata]
[Seçilenleri Çıkar]
```

 Database:

```
course_enrollments
```

 üzerinden yönetilir.

---

 # 65\. Öğrencinin dashboard verisi

 Öğrenci giriş yaptığında:

```
Ali Kaya

Derslerim
────────────────────────

PHP
████████████████░░░░ 82%

MySQL
███████████░░░░░░░░░ 58%

JavaScript
██████░░░░░░░░░░░░░░ 30%
```

 Bu verinin kaynağı:

```
course_enrollments
        │
        └── course_progress
```

 olacak.

 Ayrıntıya girerse:

```
PHP
│
├── Modül 1 ✓
├── Modül 2 ✓
├── Modül 3 75%
└── Modül 4 0%
```

---

 # 66\. Öğretmen dashboard

 Örneğin:

```
Ahmet Yılmaz

Sınıflarım
────────────────────
10-A    24 öğrenci
10-B    21 öğrenci
11-A    19 öğrenci

Derslerim
────────────────────
PHP
MySQL
JavaScript

Son Aktiviteler
────────────────────
Ali → PHP sınavı → 82
Mehmet → MySQL → %70
Ayşe → PHP videosu → tamamlandı
```

---

 # 67\. Admin dashboard

```
TOPLAM ÖĞRENCİ
842

TOPLAM ÖĞRETMEN
42

AKTİF SINIF
36

AKTİF DERS
94

VIDEO
486

PDF/DOSYA
723

SINAV
138
```

 Ayrıca:

```
Son kayıtlar
Son sınavlar
En aktif dersler
Sınıf dağılımı
Öğretmen dağılımı
Öğrenci ilerlemeleri
```

 gösterilebilir.

---

 # 68\. Soft delete stratejisi

 Soft delete kullanılacak ana tablolar:

```
users
classes
courses
lessons
contents
files
```

 Örneğin:

```
deleted_at = NULL
```

 aktif.

```
deleted_at = 2026-10-02 14:32:00
```

 silinmiş.

 Ancak:

```
exam_answers
login_logs
audit_logs
```

 gibi geçmiş kayıtlarını normalde silmemek daha doğru.

---

 # 69\. Foreign key silme stratejisi

 Her tabloya körü körüne:

```
ON DELETE CASCADE
```

 vermemek gerekiyor.

 Örneğin:

```
users
 ↓
exam_attempts
```

 kullanıcı silindiğinde öğrencinin sınav geçmişinin yok olması istenmeyebilir.

 Bu yüzden ana eğitim kayıtlarında:

```
soft delete
```

 tercih edilmeli.

 `CASCADE` daha çok:

```
exam
 ↓
exam_questions
```

 gibi bağımlı alt yapılarda kullanılmalı.

 Örneğin soru silinirse seçeneklerinin de silinmesi mantıklı:

```
exam_questions
     ↓
exam_choices
```

---

 # 70\. Database seviyesinde tutulması gereken indexler

 Özellikle:

```
users.username
users.status

classes.branch_id
classes.academic_year_id

class_teachers.class_id
class_teachers.teacher_id

class_students.class_id
class_students.student_id

courses.branch_id

course_offerings.course_id
course_offerings.class_id
course_offerings.term_id

offering_teachers.offering_id
offering_teachers.teacher_id

course_enrollments.offering_id
course_enrollments.student_id

contents.lesson_id
lessons.module_id
modules.course_id

exam_questions.exam_id
exam_choices.question_id

exam_attempts.student_id
exam_attempts.exam_offering_id

course_progress.enrollment_id
lesson_progress.enrollment_id
content_progress.enrollment_id
video_progress.enrollment_id
```

 indexlenmeli.

 Bu, dashboard ve öğrenci listelemelerinde ciddi fark yaratır.

---

 # 71\. Benzersizlik kuralları

 Özellikle:

```
users.username
```

 unique.

```
student_profiles.student_no
```

 unique.

```
teacher_profiles.teacher_no
```

 unique.

```
classes.code + academic_year_id
```

 unique.

```
courses.slug
```

 unique.

```
course_offerings.course_id
              + class_id
              + term_id
```

 unique.

```
course_enrollments.offering_id
                   + student_id
```

 unique.

```
exam_attempts.exam_offering_id
             + student_id
             + attempt_number
```

 unique.

 Bunlar veri tekrarını ciddi şekilde önler.

---

 # 72\. Database'de bulunmaması gerekenler

 Bu projede **özellikle bulunmamasını** önerdiğim tablolar:

```
password_resets
password_reset_tokens
email_verifications
email_verification_tokens
social_logins
```

 Çünkü senin istediğin sistem:

```
Kullanıcı adı
+
Şifre
```

 ile çalışıyor.

---

 # 73\. Nihai tablo listesi

 Son haliyle database'i şu şekilde kabul edebiliriz:

 ### Authentication

```
users
roles
permissions
user_roles
role_permissions
```

 ### Profiles

```
student_profiles
teacher_profiles
```

 ### Akademik yapı

```
departments
branches
academic_years
terms
```

 ### Sınıflar

```
classes
class_teachers
class_students
```

 ### Ders

```
courses
course_offerings
offering_teachers
course_enrollments
```

 ### Ders içeriği

```
modules
lessons
contents
files
content_files
videos
content_versions
```

 ### Sınav

```
exams
exam_offerings
exam_questions
exam_choices
exam_attempts
exam_answers
```

 ### İlerleme

```
course_progress
lesson_progress
content_progress
video_progress
```

 ### İletişim

```
announcements
announcement_classes
announcement_courses
announcement_students
announcement_teachers
notifications
```

 ### Yönetim

```
student_notes
audit_logs
login_logs
media_folders
settings
```

---

 # 74\. En önemli ilişki özeti

 Son haliyle sistemin omurgası:

```
USERS
│
├── STUDENT_PROFILE
│
├── TEACHER_PROFILE
│
└── ROLES
     │
     └── PERMISSIONS

DEPARTMENT
│
└── BRANCH
     │
     ├── CLASSES
     │    ├── CLASS_TEACHERS
     │    └── CLASS_STUDENTS
     │
     └── COURSES
          │
          ├── MODULES
          │    └── LESSONS
          │         ├── CONTENTS
          │         │    ├── FILES
          │         │    ├── VIDEOS
          │         │    └── CONTENT_VERSIONS
          │         │
          │         └── EXAMS
          │              ├── QUESTIONS
          │              │    └── CHOICES
          │              │
          │              └── EXAM_OFFERINGS
          │                   └── ATTEMPTS
          │                        └── ANSWERS
          │
          └── COURSE_OFFERINGS
               │
               ├── OFFERING_TEACHERS
               │
               └── COURSE_ENROLLMENTS
                    │
                    ├── COURSE_PROGRESS
                    ├── LESSON_PROGRESS
                    ├── CONTENT_PROGRESS
                    └── VIDEO_PROGRESS
```

 ## 75\. Bu şemada özellikle düzelttiğim kritik noktalar

 Önceki tasarıma göre en önemli iyileştirmeler şunlar:

 1. **E-posta tamamen çıkarıldı.** Giriş anahtarı `username`.
2. **Şifre sıfırlama sistemi tamamen çıkarıldı.**
3. Kullanıcıları **admin oluşturuyor**.
4. Öğretmen-sınıf ilişkisi geçmişi korunuyor.
5. Öğrenci-sınıf ilişkisi geçmişi korunuyor.
6. `course` ile `course_offering` birbirinden ayrıldı.
7. Böylece aynı ders farklı sınıflarda ve farklı yıllarda açılabiliyor.
8. Öğretmen doğrudan sadece derse değil, **belirli ders açılışına** atanabiliyor.
9. Öğrenci doğrudan sadece derse değil, **belirli ders açılışına** kayıtlı oluyor.
10. Progress kayıtları `course_id` yerine **enrollment** üzerinden tutuluyor.
11. Böylece 2025'teki PHP ilerlemesi 2026'daki PHP'ye karışmıyor.
12. Sınavlar da belirli `exam_offering` üzerinden uygulanabiliyor.
13. Duyurularda polymorphic `target_type/target_id` yerine gerçek FK'ler kullanıldı.
14. Öğretmen yetkisi sınıf ve ders seviyesinde ayrı ayrı kontrol edilebiliyor.
15. Video ilerlemesi ayrı tutuluyor.
16. İçerik versiyonlama eklendi.
17. Audit log eklendi.
18. Login log eklendi.
19. Soft delete stratejisi eklendi.
20. Database'in kritik yerlerine unique constraint ve indexler eklendi.

 **Bence bu aşamadaki en doğru sonraki adım**, artık şemayı tekrar teorik olarak büyütmek değil; bu tasarımı **tek bir çalıştırılabilir MySQL 8.x `schema.sql` dosyasına** dönüştürmek. O dosyada tabloları doğru migration/oluşturma sırasına koyup **foreign key'leri, indexleri, unique constraint'leri, `ON DELETE` davranışlarını, başlangıç rollerini (`admin/teacher/student`), bütün permission kayıtlarını ve örnek `Bilişim Teknolojileri → Yazılım Geliştirme/Siber Güvenlik → sınıflar → dersler` seed verilerini** de birlikte tanımlamak gerekir. Bu noktadan sonra Laravel modelleri ve API endpoint'leri doğrudan bu şemaya bağlanabilir.
 
# Pusula Akademi

PHP 8.2 ve MySQL/MariaDB ile çalışan, bağımlılıksız çevrimiçi eğitim platformu. Arayüz Türkçedir; PHP PDO, MySQL ve modern tarayıcı yeterlidir.

## Gereksinimler

- PHP 8.1 veya üstü (`pdo_mysql`, `fileinfo`, `mbstring` eklentileri)
- MySQL 8 veya MariaDB 10.4+

Node.js, Composer veya ek PHP paketi gerekmez.

## Windows / XAMPP kurulumu

1. XAMPP Control Panel'den Apache ve MySQL servislerini başlatın.
2. phpMyAdmin'de `database/schema.sql` dosyasını içe aktarın veya proje klasöründe:

   ```powershell
   C:\xampp\mysql\bin\mysql.exe --default-character-set=utf8mb4 -u root < database\schema.sql
   ```

3. Gerekirse `config.php` içindeki veritabanı bağlantısını ya da `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` ortam değişkenlerini düzenleyin. Varsayılan ayarlar XAMPP'ın yerel `root` / boş şifre kurulumuna uygundur.
4. Mevcut kurulumunuzu güncelliyorsanız çoklu ders atama tablosunu ve mevcut sınıf öğrencilerinin ders kayıtlarını ekleyin:

   ```powershell
   C:\xampp\mysql\bin\mysql.exe --default-character-set=utf8mb4 -u root < database\migrations\20261002_multi_assignments.sql
   ```

5. Yönetici hesabını güçlü ve benzersiz bir parolayla oluşturun:

   ```powershell
   php database\seed_admin.php yonetici@ornek.com "GucluBirParola123!"
   ```

6. Proje klasöründen yerel geliştirme sunucusunu çalıştırın:

   ```powershell
   php -S 127.0.0.1:8000
   ```

   Ardından `http://127.0.0.1:8000` adresini açın. XAMPP Apache kullanıyorsanız proje klasörünü `htdocs` içine koyup `http://localhost/onlineegitim` adresini ziyaret edin.

Bu çalışma alanında oluşturulmuş yerel deneme yönetici hesabı: `admin@pusula.local` / `PusulaAdmin!2026`. Gerçek kullanım öncesinde `php database\seed_admin.php` komutunu kendi e-posta adresiniz ve güçlü parolanızla çalıştırarak bu hesabı değiştirin.

## Özellikler

- Öğrenci kaydı, oturum açma ve rol tabanlı öğrenci / öğretmen / yönetici alanları
- Siber Güvenlik ve Yazılım Geliştirme alanları için sınıf ve ders yönetimi
- Öğretmen/öğrenci oluşturma, güncelleme, silme ve sınıf eşleştirmeleri
- Öğretmenleri birden fazla sınıfa eşleştirme; öğretmenin yalnızca atanmış sınıfların derslerinde içerik ve sınav yönetmesi
- Öğrencileri sınıflarına ait birden fazla derse eşleştirme ve öğrenci ekranlarında yalnızca atanmış dersleri gösterme
- Video bağlantısı veya video, görsel ve belge dosyası ekleme, düzenleme, silme
- Çoktan seçmeli sınav oluşturma, yayınlama, cevapları puanlama ve öğrenci sonuç takibi
- Ders tamamlama ve öğrenci ilerleme göstergeleri
- CSRF koruması, PDO prepared statements, parola hashleme ve dosya türü/boyutu doğrulama

İlk kez kayıt olan hesaplar öğrenci rolüyle açılır. Yönetici hesabı yalnızca komut satırındaki `seed_admin.php` betiği ile oluşturulur.
