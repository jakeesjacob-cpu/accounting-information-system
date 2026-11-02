-- Run once in phpMyAdmin on the accounting_information_system database (MariaDB/XAMPP).

-- 1) Word-document tasks
ALTER TABLE assignments
  ADD COLUMN file_name VARCHAR(255) NULL,
  ADD COLUMN file_path VARCHAR(255) NULL;

-- 2) Program management: subjects, sections, schedules
CREATE TABLE IF NOT EXISTS subjects (
  subject_id   INT AUTO_INCREMENT PRIMARY KEY,
  course_id    INT NOT NULL,
  subject_code VARCHAR(30)  NOT NULL,
  subject_name VARCHAR(150) NOT NULL,
  UNIQUE KEY uq_subject (course_id, subject_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sections (
  section_id   INT AUTO_INCREMENT PRIMARY KEY,
  course_id    INT NOT NULL,
  section_name VARCHAR(60) NOT NULL,
  UNIQUE KEY uq_section (course_id, section_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS class_schedules (
  schedule_id INT AUTO_INCREMENT PRIMARY KEY,
  subject_id  INT NOT NULL,
  section_id  INT NOT NULL,
  day_of_week VARCHAR(10) NOT NULL,
  start_time  TIME NOT NULL,
  end_time    TIME NOT NULL,
  KEY idx_section (section_id),
  KEY idx_subject (subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Student management: groups and enlistment (one section per student)
CREATE TABLE IF NOT EXISTS student_groups (
  group_id   INT AUTO_INCREMENT PRIMARY KEY,
  section_id INT NOT NULL,
  group_name VARCHAR(60) NOT NULL,
  UNIQUE KEY uq_group (section_id, group_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS enrollments (
  enrollment_id INT AUTO_INCREMENT PRIMARY KEY,
  student_id    INT NOT NULL,
  section_id    INT NOT NULL,
  group_id      INT NULL,
  enrolled_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_student (student_id),
  KEY idx_section (section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
