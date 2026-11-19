<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pdo = database();

$columns = [
    'brugere' => [
        'blacklisted' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'blacklist_reason' => 'VARCHAR(255) NULL',
        'adgangskode_hash' => 'VARCHAR(255) NULL',
        'reset_token' => 'VARCHAR(255) NULL',
        'reset_otp' => 'VARCHAR(16) NULL',
        'reset_expires' => 'DATETIME NULL',
    ],
    'udlaan' => [
        'afleveringsdato' => 'DATETIME NULL',
    ],
];

foreach ($columns as $table => $tableColumns) {
    foreach ($tableColumns as $column => $definition) {
        $check = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $check->execute([$table, $column]);
        if ((int) $check->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            fwrite(STDOUT, "Tilføjede $table.$column\n");
        }
    }
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS undervisere (
        id INT NOT NULL AUTO_INCREMENT,
        navn VARCHAR(150) NOT NULL,
        email VARCHAR(254) NOT NULL,
        afdeling VARCHAR(100) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_undervisere_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS computer_reservationer (
        id INT NOT NULL AUTO_INCREMENT,
        underviser_id INT NOT NULL,
        antal_computere INT NOT NULL,
        startdato DATE NOT NULL,
        slutdato DATE NOT NULL,
        formaal VARCHAR(255) NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'aktiv',
        oprettet_dato DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_reservation_periode (status, startdato, slutdato),
        CONSTRAINT fk_reservation_underviser
            FOREIGN KEY (underviser_id) REFERENCES undervisere (id),
        CONSTRAINT chk_reservation_antal CHECK (antal_computere > 0),
        CONSTRAINT chk_reservation_dato CHECK (slutdato >= startdato)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

ensureLoanApprovalTable($pdo);
ensureAdminProfileTable($pdo);
ensureStudentProfileTable($pdo);
ensureSupportTables($pdo);
ensureHardwareInventoryTable($pdo);
ensureComputerSerialNumberColumn($pdo);
ensureComputerArchivedAtColumn($pdo);
ensureStudentCprColumn($pdo);

fwrite(STDOUT, "Migration gennemført. Underviser-, reservationer-, afleveringsgodkendelses-, profil-, support- og hardwareinventartabeller er klar.\n");
