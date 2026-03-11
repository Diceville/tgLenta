<?php
echo json_encode([
    'php'        => PHP_VERSION,
    'pdo_mysql'  => extension_loaded('pdo_mysql'),
    'curl'       => extension_loaded('curl'),
    'db_host'    => getenv('DB_HOST') ?: 'not set',
    'db_name'    => getenv('DB_NAME') ?: 'not set',
    'bot_token'  => getenv('BOT_TOKEN') ? 'set' : 'not set',
]);
