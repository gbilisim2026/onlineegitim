USE online_egitim;

CREATE TABLE IF NOT EXISTS course_members (
  course_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (course_id,user_id),
  CONSTRAINT fk_course_members_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_course_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO course_members (course_id,user_id)
SELECT c.id,cm.user_id
FROM courses c
JOIN class_members cm ON cm.class_id=c.class_id AND cm.member_role='student';
