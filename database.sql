CREATE DATABASE IF NOT EXISTS azmoon_payambaran
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE azmoon_payambaran;

CREATE TABLE admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE exams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    time_per_question INT NOT NULL DEFAULT 10,
    attempts_limit INT DEFAULT 1,
    starts_at DATETIME,
    ends_at DATETIME,
    status ENUM('draft','active','archived','trash')
        DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('multiple','text') NOT NULL,
    correct_answer TEXT NOT NULL,
    hint_text TEXT,
    hint_enabled BOOLEAN DEFAULT FALSE,
    hint_mode ENUM('continue','pause','add')
        DEFAULT 'add',
    hint_extra_seconds INT DEFAULT 3,
    FOREIGN KEY (exam_id) REFERENCES exams(id)
        ON DELETE CASCADE
);

CREATE TABLE question_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    option_text TEXT NOT NULL,
    FOREIGN KEY (question_id) REFERENCES questions(id)
        ON DELETE CASCADE
);

CREATE TABLE participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    participant_id INT NOT NULL,
    correct_count INT DEFAULT 0,
    wrong_count INT DEFAULT 0,
    unanswered_count INT DEFAULT 0,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME,
    FOREIGN KEY (exam_id) REFERENCES exams(id)
        ON DELETE CASCADE,
    FOREIGN KEY (participant_id) REFERENCES participants(id)
        ON DELETE CASCADE
);

CREATE TABLE answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT NOT NULL,
    question_id INT NOT NULL,
    submitted_answer TEXT,
    is_correct BOOLEAN,
    answered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (attempt_id) REFERENCES attempts(id)
        ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id)
        ON DELETE CASCADE
);

CREATE TABLE settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT
);
ALTER TABLE exams
ADD COLUMN hint_enabled BOOLEAN DEFAULT FALSE,
ADD COLUMN hint_mode ENUM('continue','pause','add') DEFAULT 'add',
ADD COLUMN hint_extra_seconds INT DEFAULT 3;
CREATE TABLE IF NOT EXISTS accepted_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    answer_text VARCHAR(500) NOT NULL,

    FOREIGN KEY (question_id)
    REFERENCES questions(id)
    ON DELETE CASCADE
);
ALTER TABLE exams
ADD COLUMN theme_background VARCHAR(7)
    NOT NULL DEFAULT '#071e18',
ADD COLUMN theme_primary VARCHAR(7)
    NOT NULL DEFAULT '#d6b45b',
ADD COLUMN theme_accent VARCHAR(7)
    NOT NULL DEFAULT '#168348';
