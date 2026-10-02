CREATE DATABASE IF NOT EXISTS online_egitim CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE online_egitim;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','teacher','student') NOT NULL DEFAULT 'student',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS classes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  track ENUM('Yazılım Geliştirme','Siber Güvenlik') NOT NULL,
  grade TINYINT UNSIGNED NOT NULL DEFAULT 9,
  description TEXT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS class_members (
  class_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  member_role ENUM('teacher','student') NOT NULL DEFAULT 'student',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (class_id,user_id),
  CONSTRAINT fk_members_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
  CONSTRAINT fk_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS courses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  description TEXT,
  track ENUM('Yazılım Geliştirme','Siber Güvenlik') NOT NULL,
  class_id BIGINT UNSIGNED NULL,
  teacher_id BIGINT UNSIGNED NULL,
  level ENUM('Başlangıç','Orta','İleri') NOT NULL DEFAULT 'Başlangıç',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_courses_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
  CONSTRAINT fk_courses_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS course_members (
  course_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (course_id,user_id),
  CONSTRAINT fk_course_members_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_course_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lessons (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  description TEXT,
  content_type ENUM('video','image','document') NOT NULL DEFAULT 'video',
  file_path VARCHAR(255) NULL,
  original_name VARCHAR(255) NULL,
  video_url VARCHAR(500) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lessons_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS progress (
  user_id BIGINT UNSIGNED NOT NULL,
  lesson_id BIGINT UNSIGNED NOT NULL,
  is_completed TINYINT(1) NOT NULL DEFAULT 0,
  completed_at DATETIME NULL,
  PRIMARY KEY (user_id,lesson_id),
  CONSTRAINT fk_progress_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_progress_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quizzes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  description TEXT,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_quizzes_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS questions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quiz_id BIGINT UNSIGNED NOT NULL,
  question_text TEXT NOT NULL,
  option_a VARCHAR(500) NOT NULL,
  option_b VARCHAR(500) NOT NULL,
  option_c VARCHAR(500) NOT NULL,
  option_d VARCHAR(500) NOT NULL,
  correct_option TINYINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_questions_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quiz_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  quiz_id BIGINT UNSIGNED NOT NULL,
  score TINYINT UNSIGNED NOT NULL,
  correct_count SMALLINT UNSIGNED NOT NULL,
  total_count SMALLINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_attempt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_attempt_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO classes (name,track,grade,description)
SELECT '9-A Yazılım Geliştirme','Yazılım Geliştirme',9,'Algoritmik düşünme ve programlama temelleri.'
WHERE NOT EXISTS (SELECT 1 FROM classes WHERE name='9-A Yazılım Geliştirme');
INSERT INTO classes (name,track,grade,description)
SELECT '10-A Siber Güvenlik','Siber Güvenlik',10,'Ağlar, sistem güvenliği ve dijital savunma.'
WHERE NOT EXISTS (SELECT 1 FROM classes WHERE name='10-A Siber Güvenlik');

INSERT INTO courses (title,description,track,class_id,level)
SELECT 'Python ile Programlamaya Giriş','Algoritmik düşünme ve Python ile ilk programlarını yaz.','Yazılım Geliştirme',id,'Başlangıç'
FROM classes WHERE name='9-A Yazılım Geliştirme'
AND NOT EXISTS (SELECT 1 FROM courses WHERE title='Python ile Programlamaya Giriş');
INSERT INTO courses (title,description,track,class_id,level)
SELECT 'Web Geliştirmenin Temelleri','HTML, CSS ve JavaScript ile etkileşimli web sayfaları oluştur.','Yazılım Geliştirme',id,'Başlangıç'
FROM classes WHERE name='9-A Yazılım Geliştirme'
AND NOT EXISTS (SELECT 1 FROM courses WHERE title='Web Geliştirmenin Temelleri');
INSERT INTO courses (title,description,track,class_id,level)
SELECT 'Siber Güvenliğe İlk Adım','Bilgi güvenliğinin temel kavramları ve güvenli dijital alışkanlıklar.','Siber Güvenlik',id,'Başlangıç'
FROM classes WHERE name='10-A Siber Güvenlik'
AND NOT EXISTS (SELECT 1 FROM courses WHERE title='Siber Güvenliğe İlk Adım');
INSERT INTO courses (title,description,track,class_id,level)
SELECT 'Bilgisayar Ağları ve Güvenlik','Ağların işleyişini, temel protokolleri ve koruma yöntemlerini öğren.','Siber Güvenlik',id,'Orta'
FROM classes WHERE name='10-A Siber Güvenlik'
AND NOT EXISTS (SELECT 1 FROM courses WHERE title='Bilgisayar Ağları ve Güvenlik');

INSERT INTO lessons (course_id,title,description,content_type,sort_order)
SELECT c.id,'Algoritma nedir?','Algoritmik düşünme ve problem çözmenin temel adımları.','video',1
FROM courses c WHERE c.title='Python ile Programlamaya Giriş'
AND NOT EXISTS (SELECT 1 FROM lessons WHERE course_id=c.id AND title='Algoritma nedir?');
INSERT INTO lessons (course_id,title,description,content_type,sort_order)
SELECT c.id,'Python ile ilk adımlar','Değişkenler, veri tipleri ve ekrana çıktı verme.','video',2
FROM courses c WHERE c.title='Python ile Programlamaya Giriş'
AND NOT EXISTS (SELECT 1 FROM lessons WHERE course_id=c.id AND title='Python ile ilk adımlar');
INSERT INTO lessons (course_id,title,description,content_type,sort_order)
SELECT c.id,'İnternette güvenliğin temelleri','Gizlilik, güçlü parola ve çevrimiçi güvenlik kavramları.','video',1
FROM courses c WHERE c.title='Siber Güvenliğe İlk Adım'
AND NOT EXISTS (SELECT 1 FROM lessons WHERE course_id=c.id AND title='İnternette güvenliğin temelleri');
INSERT INTO lessons (course_id,title,description,content_type,sort_order)
SELECT c.id,'Ağ nedir?','Bilgisayar ağlarının temel bileşenlerini tanı.','video',1
FROM courses c WHERE c.title='Bilgisayar Ağları ve Güvenlik'
AND NOT EXISTS (SELECT 1 FROM lessons WHERE course_id=c.id AND title='Ağ nedir?');

INSERT INTO quizzes (course_id,title,description,is_published)
SELECT id,'Python temelleri','Değişkenler ve programlama mantığı bilgini ölç.',1
FROM courses WHERE title='Python ile Programlamaya Giriş'
AND NOT EXISTS (SELECT 1 FROM quizzes WHERE title='Python temelleri');
INSERT INTO questions (quiz_id,question_text,option_a,option_b,option_c,option_d,correct_option)
SELECT q.id,'Python’da ekrana çıktı vermek için hangi fonksiyon kullanılır?','echo()','print()','write()','output()',1
FROM quizzes q WHERE q.title='Python temelleri'
AND NOT EXISTS (SELECT 1 FROM questions WHERE quiz_id=q.id);

INSERT INTO quizzes (course_id,title,description,is_published)
SELECT id,'Siber güvenliğe giriş','Güvenli dijital yaşamla ilgili temel bilgileri gözden geçir.',1
FROM courses WHERE title='Siber Güvenliğe İlk Adım'
AND NOT EXISTS (SELECT 1 FROM quizzes WHERE title='Siber güvenliğe giriş');
INSERT INTO questions (quiz_id,question_text,option_a,option_b,option_c,option_d,correct_option)
SELECT q.id,'Hesap güvenliği için en iyi parola yaklaşımı hangisidir?','Her yerde aynı kısa parolayı kullanmak','Tahmin edilmesi kolay bir tarih seçmek','Uzun ve her hesapta farklı parola kullanmak','Parolayı herkesle paylaşmak',2
FROM quizzes q WHERE q.title='Siber güvenliğe giriş'
AND NOT EXISTS (SELECT 1 FROM questions WHERE quiz_id=q.id);
