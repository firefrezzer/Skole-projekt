<?php
declare(strict_types=1);

require_once __DIR__ . '/php_config.php';

function ensureStudentPasswordResetColumns(PDO $pdo): void
{
    foreach (['blacklisted', 'blacklist_reason', 'adgangskode_hash', 'reset_token', 'reset_otp', 'reset_expires'] as $column) {
        $check = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $check->execute(['brugere', $column]);
        if ((int) $check->fetchColumn() === 0) {
            $definition = match ($column) {
                'blacklisted' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'blacklist_reason' => 'VARCHAR(255) NULL',
                'adgangskode_hash' => 'VARCHAR(255) NULL',
                'reset_token' => 'VARCHAR(255) NULL',
                'reset_otp' => 'VARCHAR(16) NULL',
                'reset_expires' => 'DATETIME NULL',
            };
            $pdo->exec("ALTER TABLE `brugere` ADD COLUMN `$column` $definition");
        }
    }
}

function database(): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    ensureStudentPasswordResetColumns($pdo);
    return $pdo;
}

function ensureLoanApprovalTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS udlaan_godkendelsesgrupper (
            id INT NOT NULL AUTO_INCREMENT,
            token VARCHAR(128) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            response_at DATETIME NULL,
            response_note VARCHAR(255) NULL,
            renewed_until DATE NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_approval_group_token (token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS udlaan_godkendelser (
            loan_id INT NOT NULL,
            group_id INT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            token VARCHAR(128) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            response_at DATETIME NULL,
            response_note VARCHAR(255) NULL,
            renewed_until DATE NULL,
            PRIMARY KEY (loan_id),
            UNIQUE KEY uq_approval_token (token),
            KEY idx_approval_group (group_id),
            CONSTRAINT fk_approval_loan
                FOREIGN KEY (loan_id) REFERENCES udlaan (id) ON DELETE CASCADE,
            CONSTRAINT fk_approval_group
                FOREIGN KEY (group_id) REFERENCES udlaan_godkendelsesgrupper (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $columnCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $columnCheck->execute(['udlaan_godkendelser', 'group_id']);
    if ((int) $columnCheck->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE udlaan_godkendelser ADD COLUMN group_id INT NULL AFTER loan_id');
    }

    $indexCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $indexCheck->execute(['udlaan_godkendelser', 'idx_approval_group']);
    if ((int) $indexCheck->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE udlaan_godkendelser ADD KEY idx_approval_group (group_id)');
    }

    $constraintCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
    );
    $constraintCheck->execute(['udlaan_godkendelser', 'fk_approval_group']);
    if ((int) $constraintCheck->fetchColumn() === 0) {
        $pdo->exec(
            'ALTER TABLE udlaan_godkendelser
             ADD CONSTRAINT fk_approval_group
             FOREIGN KEY (group_id) REFERENCES udlaan_godkendelsesgrupper (id) ON DELETE CASCADE'
        );
    }
}

function ensureAdminRegistrationTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_registrations (
            id INT NOT NULL AUTO_INCREMENT,
            username VARCHAR(32) NOT NULL,
            username_key VARCHAR(32) NOT NULL,
            email VARCHAR(254) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_admin_registration_username (username_key),
            UNIQUE KEY uq_admin_registration_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensureAdminProfileTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_profiles (
            username_key VARCHAR(32) NOT NULL,
            display_name VARCHAR(80) NOT NULL,
            animation VARCHAR(16) NOT NULL DEFAULT 'none',
            background VARCHAR(16) NOT NULL DEFAULT 'blue',
            avatar_gif MEDIUMBLOB NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (username_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensureStudentProfileTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS elev_profiler (
            elevnummer VARCHAR(64) NOT NULL,
            display_name VARCHAR(80) NOT NULL,
            animation VARCHAR(16) NOT NULL DEFAULT 'none',
            background VARCHAR(16) NOT NULL DEFAULT 'blue',
            avatar_gif MEDIUMBLOB NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (elevnummer)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensureSupportTables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS support_tickets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_reference VARCHAR(24) NOT NULL,
            student_number VARCHAR(64) NOT NULL,
            student_name VARCHAR(150) NOT NULL,
            student_email VARCHAR(254) NOT NULL,
            subject VARCHAR(120) NOT NULL,
            status ENUM('open', 'resolved', 'inactive') NOT NULL DEFAULT 'open',
            public_token_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME NULL,
            closed_by VARCHAR(16) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_support_ticket_reference (ticket_reference),
            UNIQUE KEY uq_support_ticket_token (public_token_hash),
            KEY idx_support_ticket_activity (status, last_activity_at),
            KEY idx_support_ticket_student (student_number, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS support_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            sender_type ENUM('student', 'admin') NOT NULL,
            sender_username VARCHAR(32) NULL,
            message_text TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_support_message_ticket (ticket_id, created_at),
            CONSTRAINT fk_support_message_ticket
                FOREIGN KEY (ticket_id) REFERENCES support_tickets (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensureHardwareInventoryTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS hardware_inventory (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            asset_number VARCHAR(50) NOT NULL,
            category VARCHAR(100) NOT NULL,
            name VARCHAR(150) NOT NULL,
            brand VARCHAR(100) NOT NULL DEFAULT '',
            model VARCHAR(100) NOT NULL DEFAULT '',
            serial_number VARCHAR(120) NOT NULL DEFAULT '',
            status ENUM('pa_lager', 'i_brug', 'service', 'udgaaet') NOT NULL DEFAULT 'pa_lager',
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_hardware_inventory_asset_number (asset_number),
            KEY idx_hardware_inventory_category_name (category, name),
            KEY idx_hardware_inventory_serial_number (serial_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensureComputerSerialNumberColumn(PDO $pdo): void
{
    $columnCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $columnCheck->execute(['computere', 'serial_number']);
    if ((int) $columnCheck->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE computere
             ADD COLUMN serial_number VARCHAR(120) NOT NULL DEFAULT '' AFTER model"
        );
    }

    $indexCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $indexCheck->execute(['computere', 'idx_computere_serial_number']);
    if ((int) $indexCheck->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE computere ADD KEY idx_computere_serial_number (serial_number)');
    }
}

function ensureComputerArchivedAtColumn(PDO $pdo): void
{
    $columnCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $columnCheck->execute(['computere', 'archived_at']);
    if ((int) $columnCheck->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE computere ADD COLUMN archived_at DATETIME NULL');
    }
}

function ensureStudentCprColumn(PDO $pdo): void
{
    $columnCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $columnCheck->execute(['brugere', 'cpr']);
    if ((int) $columnCheck->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE brugere ADD COLUMN cpr VARCHAR(11) NULL AFTER navn');
    }
}
