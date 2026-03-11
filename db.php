<?php

require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        // MAMP использует Unix-сокет (skip-networking в my.cnf)
        $dsn = sprintf(
            'mysql:unix_socket=/Applications/MAMP/tmp/mysql/mysql.sock;dbname=%s;charset=utf8mb4',
            DB_NAME
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    return $pdo;
}
