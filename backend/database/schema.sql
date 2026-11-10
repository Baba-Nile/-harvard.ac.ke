-- Harvard Extention Training College: online learning database
-- MySQL 5.7+ / MariaDB 10.3+, InnoDB, utf8mb4. Import once with phpMyAdmin (cPanel) into an EMPTY database.
-- Seeds ONLY the college's 7 departments and 50 courses. No students, lessons or materials are created.
-- Every programme starts with online_enabled = 0 (not available online) until an administrator enables it.

SET NAMES utf8mb4;

CREATE TABLE departments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE programmes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  department_id INT UNSIGNED NOT NULL,
  slug VARCHAR(120) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  online_enabled TINYINT(1) NOT NULL DEFAULT 0,
  online_summary VARCHAR(600) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_prog_dept FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Official student records. admission_no and full_name come from the registry; passwords are stored only as hashes.
CREATE TABLE students (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  admission_no VARCHAR(40) NOT NULL UNIQUE,
  full_name VARCHAR(150) NOT NULL,
  full_name_key VARCHAR(150) NOT NULL,           -- lower-cased, whitespace-collapsed copy of full_name used for sign-in lookup
  email VARCHAR(190) NULL,
  phone VARCHAR(30) NULL,
  status ENUM('active','suspended','withdrawn') NOT NULL DEFAULT 'active',
  password_hash VARCHAR(255) NOT NULL,           -- password_hash() output; initial value is a hash of the admission number
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  password_changed_at DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  KEY idx_students_name (full_name_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE enrolments (
  student_id INT UNSIGNED NOT NULL,
  programme_id INT UNSIGNED NOT NULL,
  enrolled_at DATETIME NOT NULL,
  PRIMARY KEY (student_id, programme_id),
  CONSTRAINT fk_enr_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_enr_prog FOREIGN KEY (programme_id) REFERENCES programmes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lessons (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  programme_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  published TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_lesson_prog FOREIGN KEY (programme_id) REFERENCES programmes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- kind = file (stored privately, served only through api/download.php), video (authorised https link), link (https link)
CREATE TABLE materials (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  lesson_id INT UNSIGNED NOT NULL,
  kind ENUM('file','video','link') NOT NULL,
  title VARCHAR(200) NOT NULL,
  stored_name VARCHAR(80) NULL,                  -- random file name inside backend/storage/materials
  original_name VARCHAR(200) NULL,
  mime VARCHAR(100) NULL,
  size_bytes INT UNSIGNED NULL,
  url VARCHAR(600) NULL,
  published TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_mat_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE assignments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  programme_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  instructions TEXT NULL,
  due_at DATETIME NULL,
  published TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_asg_prog FOREIGN KEY (programme_id) REFERENCES programmes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE announcements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  programme_id INT UNSIGNED NULL,                -- NULL = visible to every signed-in student
  title VARCHAR(200) NOT NULL,
  body TEXT NOT NULL,
  published TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_ann_prog FOREIGN KEY (programme_id) REFERENCES programmes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE staff (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  full_name VARCHAR(150) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','editor') NOT NULL DEFAULT 'editor',   -- admin: everything incl. staff accounts; editor: students and content
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE password_reset_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id INT UNSIGNED NOT NULL,
  contact VARCHAR(190) NULL,
  status ENUM('open','done','dismissed') NOT NULL DEFAULT 'open',
  requested_at DATETIME NOT NULL,
  handled_by INT UNSIGNED NULL,
  handled_at DATETIME NULL,
  CONSTRAINT fk_rst_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE enquiries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  type ENUM('contact','application') NOT NULL,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(190) NULL,
  department VARCHAR(120) NULL,
  course VARCHAR(160) NULL,
  message TEXT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rate limiting. key_hash is a SHA-256 of the IP address or normalised name, never the raw value.
CREATE TABLE rate_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  scope VARCHAR(24) NOT NULL,
  key_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_rate (scope, key_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_type ENUM('student','staff','system') NOT NULL,
  actor_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  detail VARCHAR(400) NULL,
  ip_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_audit (actor_type, actor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---- Seed: departments ----
INSERT INTO departments (id, slug, name, sort_order) VALUES (1, 'health', 'Health & Social Sciences', 1);
INSERT INTO departments (id, slug, name, sort_order) VALUES (2, 'business', 'Business', 2);
INSERT INTO departments (id, slug, name, sort_order) VALUES (3, 'eng', 'Engineering & ICT', 3);
INSERT INTO departments (id, slug, name, sort_order) VALUES (4, 'hospitality', 'Hospitality', 4);
INSERT INTO departments (id, slug, name, sort_order) VALUES (5, 'languages', 'Foreign Languages', 5);
INSERT INTO departments (id, slug, name, sort_order) VALUES (6, 'cosmetology', 'Cosmetology', 6);
INSERT INTO departments (id, slug, name, sort_order) VALUES (7, 'fashion', 'Fashion & Design', 7);

-- ---- Seed: the 50 courses (online learning NOT enabled for any of them) ----
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (1, 'perioperative-theatre-technology', 'Perioperative Theatre Technology', 0, 1);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (1, 'certified-nurse-assistant-cna', 'Certified Nurse Assistant (CNA)', 0, 2);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (1, 'health-records-and-it', 'Health Records & IT', 0, 3);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (1, 'community-health-nursing', 'Community Health Nursing', 0, 4);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (1, 'nutrition-and-dietetics', 'Nutrition and Dietetics', 0, 5);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (1, 'hiv-training-services-hts', 'HIV Training Services (HTS)', 0, 6);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (1, 'social-work-and-community-development', 'Social Work & Community Development', 0, 7);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (1, 'counselling-psychology', 'Counselling Psychology', 0, 8);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (1, 'criminology-and-legal-studies', 'Criminology & Legal Studies', 0, 9);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (1, 'science-laboratory-technology', 'Science Laboratory Technology', 0, 10);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (1, 'emergency-training', 'Emergency Training', 0, 11);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (2, 'human-resource-management', 'Human Resource Management', 0, 1);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (2, 'accountancy', 'Accountancy', 0, 2);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (2, 'banking-and-finance', 'Banking and Finance', 0, 3);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (2, 'sales-and-marketing', 'Sales and Marketing', 0, 4);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (2, 'secretarial-studies', 'Secretarial Studies', 0, 5);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (2, 'project-management', 'Project Management', 0, 6);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (2, 'certified-public-accountancy-cpa', 'Certified Public Accountancy (CPA)', 0, 7);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (2, 'public-administration', 'Public Administration', 0, 8);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (2, 'store-keeping', 'Store Keeping', 0, 9);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (3, 'electrical-and-electronic-engineering', 'Electrical & Electronic Engineering', 0, 1);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (3, 'automotive-engineering', 'Automotive Engineering', 0, 2);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (3, 'plumbing', 'Plumbing', 0, 3);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (3, 'general-agriculture', 'General Agriculture', 0, 4);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (3, 'information-communication-technology-ict', 'Information Communication Technology (ICT)', 0, 5);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (3, 'computer-science', 'Computer Science', 0, 6);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (3, 'software-engineering', 'Software Engineering', 0, 7);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (3, 'programming-and-ai', 'Programming and AI', 0, 8);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (3, 'advanced-computer-packages', 'Advanced Computer Packages', 0, 9);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (3, 'building-technology', 'Building Technology', 0, 10);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (3, 'journalism-and-mass-communication', 'Journalism & Mass Communication', 0, 11);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (3, 'music-and-deejaying', 'Music & Deejaying', 0, 12);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (4, 'food-and-beverage', 'Food and Beverage', 0, 1);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (4, 'catering-and-accommodation', 'Catering and Accommodation', 0, 2);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (4, 'hospitality-management', 'Hospitality Management', 0, 3);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (4, 'tour-and-travels', 'Tour and Travels', 0, 4);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (4, 'flight-attendant', 'Flight Attendant', 0, 5);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (5, 'french', 'French', 0, 1);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (5, 'german', 'German', 0, 2);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (5, 'chinese', 'Chinese', 0, 3);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (5, 'english', 'English', 0, 4);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (5, 'kiswahili', 'Kiswahili', 0, 5);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (6, 'hair-dressing', 'Hair Dressing', 0, 1);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (6, 'beauty-therapy', 'Beauty Therapy', 0, 2);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (6, 'skin-care', 'Skin Care', 0, 3);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (6, 'shaving', 'Shaving', 0, 4);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (6, 'massage-training', 'Massage Training', 0, 5);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (7, 'suits', 'Suits', 0, 1);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (7, 'african-wear', 'African Wear', 0, 2);
INSERT INTO programmes (department_id, slug, name, online_enabled, sort_order) VALUES (7, 'kids-wear', 'Kids Wear', 0, 3);
