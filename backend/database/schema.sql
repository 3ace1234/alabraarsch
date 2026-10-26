-- ===========================================================================
-- Al-Falaah School Portal — MySQL / MariaDB schema
-- Target: MariaDB 10.4+ / MySQL 8.0+
--
-- Apply with: php scripts/install.php
-- Reset with: php scripts/install.php --fresh   (destructive)
-- ===========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36)        NOT NULL,
    email               VARCHAR(190)    NOT NULL,
    password_hash       VARCHAR(255)    NOT NULL,
    role                ENUM('student','parent','teacher','admin') NOT NULL,
    first_name          VARCHAR(80)     NOT NULL,
    last_name           VARCHAR(80)     NOT NULL,
    phone               VARCHAR(30)     NULL,
    status              ENUM('active','suspended','inactive') NOT NULL DEFAULT 'active',
    password_changed_at DATETIME        NULL,
    last_login_at       DATETIME        NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_uuid (uuid),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academic_sessions (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36)        NOT NULL,
    name        VARCHAR(40)     NOT NULL,
    starts_on   DATE            NOT NULL,
    ends_on     DATE            NOT NULL,
    is_current  TINYINT(1)      NOT NULL DEFAULT 0,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sessions_uuid (uuid),
    UNIQUE KEY uq_sessions_name (name),
    KEY idx_sessions_current (is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS terms (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                  CHAR(36)        NOT NULL,
    session_id            BIGINT UNSIGNED NOT NULL,
    name                  VARCHAR(40)     NOT NULL,
    sequence              TINYINT UNSIGNED NOT NULL,
    starts_on             DATE            NOT NULL,
    ends_on               DATE            NOT NULL,
    is_current            TINYINT(1)      NOT NULL DEFAULT 0,
    results_published_at  DATETIME        NULL,
    created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_terms_uuid (uuid),
    UNIQUE KEY uq_terms_session_sequence (session_id, sequence),
    KEY idx_terms_current (is_current),
    CONSTRAINT fk_terms_session FOREIGN KEY (session_id) REFERENCES academic_sessions (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classes (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36)        NOT NULL,
    name            VARCHAR(60)     NOT NULL,
    level           ENUM('nursery','primary','junior_secondary','senior_secondary') NOT NULL,
    form_teacher_id BIGINT UNSIGNED NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_classes_uuid (uuid),
    UNIQUE KEY uq_classes_name (name),
    KEY idx_classes_level (level),
    CONSTRAINT fk_classes_form_teacher FOREIGN KEY (form_teacher_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subjects (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36)        NOT NULL,
    name        VARCHAR(80)     NOT NULL,
    code        VARCHAR(20)     NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subjects_uuid (uuid),
    UNIQUE KEY uq_subjects_code (code),
    UNIQUE KEY uq_subjects_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS students (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid          CHAR(36)        NOT NULL,
    user_id       BIGINT UNSIGNED NULL,
    admission_no  VARCHAR(30)     NOT NULL,
    first_name    VARCHAR(80)     NOT NULL,
    last_name     VARCHAR(80)     NOT NULL,
    date_of_birth DATE            NULL,
    gender        ENUM('male','female') NULL,
    class_id      BIGINT UNSIGNED NOT NULL,
    house         VARCHAR(60)     NULL,
    status        ENUM('active','graduated','withdrawn') NOT NULL DEFAULT 'active',
    admitted_on   DATE            NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_students_uuid (uuid),
    UNIQUE KEY uq_students_user (user_id),
    UNIQUE KEY uq_students_admission (admission_no),
    KEY idx_students_class_status (class_id, status),
    CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_students_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_guardians (
    student_id   BIGINT UNSIGNED NOT NULL,
    user_id      BIGINT UNSIGNED NOT NULL,
    relationship ENUM('father','mother','guardian') NOT NULL DEFAULT 'guardian',
    is_primary   TINYINT(1)      NOT NULL DEFAULT 1,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (student_id, user_id),
    KEY idx_guardians_user (user_id),
    CONSTRAINT fk_guardians_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_guardians_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_subjects (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    class_id    BIGINT UNSIGNED NOT NULL,
    subject_id  BIGINT UNSIGNED NOT NULL,
    teacher_id  BIGINT UNSIGNED NULL,
    session_id  BIGINT UNSIGNED NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_class_subject_session (class_id, subject_id, session_id),
    KEY idx_class_subjects_teacher (teacher_id),
    CONSTRAINT fk_cs_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cs_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cs_teacher FOREIGN KEY (teacher_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_cs_session FOREIGN KEY (session_id) REFERENCES academic_sessions (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS timetable_slots (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36)        NOT NULL,
    term_id     BIGINT UNSIGNED NOT NULL,
    class_id    BIGINT UNSIGNED NOT NULL,
    subject_id  BIGINT UNSIGNED NOT NULL,
    teacher_id  BIGINT UNSIGNED NULL,
    day_of_week TINYINT UNSIGNED NOT NULL COMMENT '1=Monday … 5=Friday',
    period_no   TINYINT UNSIGNED NOT NULL,
    starts_at   TIME            NOT NULL,
    ends_at     TIME            NOT NULL,
    room        VARCHAR(60)     NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_timetable_uuid (uuid),
    UNIQUE KEY uq_timetable_slot (term_id, class_id, day_of_week, period_no),
    KEY idx_timetable_teacher (teacher_id, day_of_week),
    CONSTRAINT fk_tt_term FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_tt_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_tt_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_tt_teacher FOREIGN KEY (teacher_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_registers (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36)        NOT NULL,
    class_id    BIGINT UNSIGNED NOT NULL,
    term_id     BIGINT UNSIGNED NOT NULL,
    date        DATE            NOT NULL,
    marked_by   BIGINT UNSIGNED NULL,
    marked_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_registers_uuid (uuid),
    UNIQUE KEY uq_registers_class_date (class_id, term_id, date),
    KEY idx_registers_date (date),
    CONSTRAINT fk_reg_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_reg_term FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_reg_marked_by FOREIGN KEY (marked_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_records (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id  BIGINT UNSIGNED NOT NULL,
    term_id     BIGINT UNSIGNED NOT NULL,
    date        DATE            NOT NULL,
    status      ENUM('present','absent','late','excused') NOT NULL,
    note        VARCHAR(190)    NULL,
    recorded_by BIGINT UNSIGNED NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_student_date (student_id, date),
    KEY idx_attendance_date_status (date, status),
    KEY idx_attendance_term (term_id),
    CONSTRAINT fk_att_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_att_term FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_att_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS result_sheets (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid          CHAR(36)        NOT NULL,
    class_id      BIGINT UNSIGNED NOT NULL,
    subject_id    BIGINT UNSIGNED NOT NULL,
    term_id       BIGINT UNSIGNED NOT NULL,
    teacher_id    BIGINT UNSIGNED NULL,
    status        ENUM('draft','submitted','approved','published') NOT NULL DEFAULT 'draft',
    submitted_at  DATETIME        NULL,
    approved_by   BIGINT UNSIGNED NULL,
    approved_at   DATETIME        NULL,
    review_note   VARCHAR(255)    NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sheets_uuid (uuid),
    UNIQUE KEY uq_sheets_class_subject_term (class_id, subject_id, term_id),
    KEY idx_sheets_status (term_id, status),
    KEY idx_sheets_teacher (teacher_id),
    CONSTRAINT fk_sheet_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sheet_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sheet_term FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sheet_teacher FOREIGN KEY (teacher_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_sheet_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS results (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id      BIGINT UNSIGNED NOT NULL,
    term_id         BIGINT UNSIGNED NOT NULL,
    subject_id      BIGINT UNSIGNED NOT NULL,
    result_sheet_id BIGINT UNSIGNED NULL,
    ca_score        DECIMAL(5,2)    NOT NULL DEFAULT 0,
    exam_score      DECIMAL(5,2)    NOT NULL DEFAULT 0,
    total           DECIMAL(5,2)    NOT NULL DEFAULT 0,
    grade           CHAR(1)         NOT NULL DEFAULT 'F',
    remark          VARCHAR(40)     NOT NULL DEFAULT 'Needs improvement',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_results_student_term_subject (student_id, term_id, subject_id),
    KEY idx_results_term_subject (term_id, subject_id),
    KEY idx_results_sheet (result_sheet_id),
    CONSTRAINT fk_results_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_results_term FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_results_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_results_sheet FOREIGN KEY (result_sheet_id) REFERENCES result_sheets (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fee_invoices (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36)        NOT NULL,
    student_id  BIGINT UNSIGNED NOT NULL,
    term_id     BIGINT UNSIGNED NOT NULL,
    reference   VARCHAR(40)     NOT NULL,
    description VARCHAR(160)    NOT NULL,
    amount      DECIMAL(12,2)   NOT NULL,
    status      ENUM('unpaid','partial','paid','waived') NOT NULL DEFAULT 'unpaid',
    issued_on   DATE            NOT NULL,
    due_on      DATE            NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoices_uuid (uuid),
    UNIQUE KEY uq_invoices_reference (reference),
    KEY idx_invoices_student_term (student_id, term_id),
    KEY idx_invoices_status_due (status, due_on),
    CONSTRAINT fk_invoice_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_invoice_term FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fee_payments (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36)        NOT NULL,
    invoice_id  BIGINT UNSIGNED NOT NULL,
    student_id  BIGINT UNSIGNED NOT NULL,
    term_id     BIGINT UNSIGNED NOT NULL,
    reference   VARCHAR(40)     NOT NULL,
    amount      DECIMAL(12,2)   NOT NULL,
    method      VARCHAR(40)     NOT NULL,
    paid_on     DATE            NOT NULL,
    received_by BIGINT UNSIGNED NULL,
    note        VARCHAR(190)    NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payments_uuid (uuid),
    UNIQUE KEY uq_payments_reference (reference),
    KEY idx_payments_student_term (student_id, term_id),
    KEY idx_payments_term_date (term_id, paid_on),
    CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES fee_invoices (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payment_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payment_term FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payment_received_by FOREIGN KEY (received_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid         CHAR(36)        NOT NULL,
    title        VARCHAR(160)    NOT NULL,
    body         TEXT            NOT NULL,
    category     VARCHAR(30)     NOT NULL DEFAULT 'School',
    is_pinned    TINYINT(1)      NOT NULL DEFAULT 0,
    status       ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    published_at DATETIME        NULL,
    created_by   BIGINT UNSIGNED NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_announcements_uuid (uuid),
    KEY idx_announcements_status_date (status, published_at),
    CONSTRAINT fk_announcement_author FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcement_audiences (
    announcement_id BIGINT UNSIGNED NOT NULL,
    audience        ENUM('student','parent','teacher','admin') NOT NULL,
    PRIMARY KEY (announcement_id, audience),
    CONSTRAINT fk_audience_announcement FOREIGN KEY (announcement_id) REFERENCES announcements (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)        NOT NULL,
    user_id        BIGINT UNSIGNED NOT NULL,
    title          VARCHAR(160)    NOT NULL,
    body           VARCHAR(500)    NOT NULL,
    type           VARCHAR(40)     NOT NULL DEFAULT 'general',
    related_type   VARCHAR(40)     NULL,
    related_uuid   CHAR(36)        NULL,
    is_read        TINYINT(1)      NOT NULL DEFAULT 0,
    read_at        DATETIME        NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notifications_uuid (uuid),
    KEY idx_notifications_user (user_id, is_read, created_at),
    CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admission_applications (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)        NOT NULL,
    applicant_name VARCHAR(120)    NOT NULL,
    guardian_name  VARCHAR(120)    NOT NULL,
    email          VARCHAR(190)    NULL,
    phone          VARCHAR(30)     NULL,
    level          ENUM('nursery','primary','junior_secondary','senior_secondary') NOT NULL,
    status         ENUM('new','assessment','offer','enrolled','declined') NOT NULL DEFAULT 'new',
    notes          TEXT            NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admissions_uuid (uuid),
    KEY idx_admissions_status (status),
    KEY idx_admissions_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assignments (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid        CHAR(36)        NOT NULL,
    class_id    BIGINT UNSIGNED NOT NULL,
    subject_id  BIGINT UNSIGNED NOT NULL,
    term_id     BIGINT UNSIGNED NOT NULL,
    title       VARCHAR(160)    NOT NULL,
    description TEXT            NULL,
    due_on      DATE            NOT NULL,
    created_by  BIGINT UNSIGNED NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assignments_uuid (uuid),
    KEY idx_assignments_class_due (class_id, due_on),
    CONSTRAINT fk_assignment_class FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_assignment_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_assignment_term FOREIGN KEY (term_id) REFERENCES terms (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_assignment_author FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assignment_submissions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assignment_id BIGINT UNSIGNED NOT NULL,
    student_id    BIGINT UNSIGNED NOT NULL,
    status        ENUM('pending','submitted','graded') NOT NULL DEFAULT 'pending',
    submitted_at  DATETIME        NULL,
    score         DECIMAL(5,2)    NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_submission_assignment_student (assignment_id, student_id),
    CONSTRAINT fk_submission_assignment FOREIGN KEY (assignment_id) REFERENCES assignments (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_submission_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NULL,
    action      VARCHAR(80)     NOT NULL,
    entity_type VARCHAR(60)     NULL,
    entity_uuid CHAR(36)        NULL,
    ip          VARCHAR(45)     NULL,
    user_agent  VARCHAR(255)    NULL,
    meta        JSON            NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_user_date (user_id, created_at),
    KEY idx_audit_action_date (action, created_at),
    KEY idx_audit_entity (entity_type, entity_uuid),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email        VARCHAR(190)    NOT NULL,
    ip           VARCHAR(45)     NOT NULL,
    success      TINYINT(1)      NOT NULL DEFAULT 0,
    attempted_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_email_date (email, attempted_at),
    KEY idx_login_ip_date (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
