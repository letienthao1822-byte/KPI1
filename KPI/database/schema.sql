-- KPI personnel evaluation schema for MySQL 8.0+ / InnoDB.
CREATE DATABASE IF NOT EXISTS kpi_system
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kpi_system;

CREATE TABLE departments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_departments_name (name)
) ENGINE=InnoDB;

CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  department_id BIGINT UNSIGNED NULL,
  employee_code VARCHAR(30) NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  position_title VARCHAR(150) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_employee_code (employee_code),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_department (department_id),
  KEY idx_users_active (is_active),
  CONSTRAINT fk_users_department FOREIGN KEY (department_id)
    REFERENCES departments (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE roles (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_key VARCHAR(50) NOT NULL,
  name VARCHAR(100) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_key (role_key)
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  permission_key VARCHAR(80) NOT NULL,
  description VARCHAR(200) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_key (permission_key)
) ENGINE=InnoDB;

CREATE TABLE user_roles (
  user_id BIGINT UNSIGNED NOT NULL,
  role_id SMALLINT UNSIGNED NOT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id),
  KEY idx_user_roles_role (role_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id)
    REFERENCES roles (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  role_id SMALLINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  KEY idx_role_permissions_permission (permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id)
    REFERENCES roles (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id)
    REFERENCES permissions (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE kpi_criteria (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  criterion_code VARCHAR(40) NOT NULL,
  name VARCHAR(200) NOT NULL,
  description TEXT NULL,
  target_type VARCHAR(30) NOT NULL DEFAULT 'official',
  weight DECIMAL(5,2) NOT NULL,
  max_score DECIMAL(5,2) NOT NULL DEFAULT 100.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_criteria_code (criterion_code),
  KEY idx_criteria_active (is_active),
  CONSTRAINT chk_criteria_weight CHECK (weight > 0 AND weight <= 100),
  CONSTRAINT chk_criteria_max_score CHECK (max_score > 0)
) ENGINE=InnoDB;

CREATE TABLE kpi_evaluations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subject_user_id BIGINT UNSIGNED NOT NULL,
  evaluator_user_id BIGINT UNSIGNED NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  status ENUM('draft', 'submitted', 'manager_approved', 'approved', 'rejected') NOT NULL DEFAULT 'draft',
  overall_score DECIMAL(7,2) NULL,
  reviewer_comment TEXT NULL,
  submitted_at DATETIME NULL,
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_evaluation_subject_period (subject_user_id, period_start, period_end),
  KEY idx_evaluations_subject_status (subject_user_id, status),
  KEY idx_evaluations_evaluator_status (evaluator_user_id, status),
  KEY idx_evaluations_status (status),
  KEY idx_evaluations_period (period_start, period_end),
  CONSTRAINT chk_evaluation_period CHECK (period_end >= period_start),
  CONSTRAINT chk_evaluation_score CHECK (overall_score IS NULL OR overall_score >= 0),
  CONSTRAINT fk_evaluations_subject FOREIGN KEY (subject_user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_evaluations_evaluator FOREIGN KEY (evaluator_user_id)
    REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE kpi_details (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  evaluation_id BIGINT UNSIGNED NOT NULL,
  criterion_id BIGINT UNSIGNED NOT NULL,
  self_score DECIMAL(5,2) NULL,
  manager_score DECIMAL(5,2) NULL,
  score DECIMAL(5,2) NULL,
  evidence_path VARCHAR(500) NULL,
  comment TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_detail_evaluation_criterion (evaluation_id, criterion_id),
  KEY idx_details_criterion (criterion_id),
  CONSTRAINT chk_detail_self_score CHECK (self_score IS NULL OR (self_score >= 0 AND self_score <= 100)),
  CONSTRAINT chk_detail_manager_score CHECK (manager_score IS NULL OR (manager_score >= 0 AND manager_score <= 100)),
  CONSTRAINT chk_detail_score CHECK (score IS NULL OR score >= 0),
  CONSTRAINT fk_details_evaluation FOREIGN KEY (evaluation_id)
    REFERENCES kpi_evaluations (id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_details_criterion FOREIGN KEY (criterion_id)
    REFERENCES kpi_criteria (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;
