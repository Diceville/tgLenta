<?php
$vars = ['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASS',
         'MYSQLHOST','MYSQLPORT','MYSQLDATABASE','MYSQLUSER','MYSQLPASSWORD',
         'MYSQL_URL','DATABASE_URL','BOT_TOKEN'];

$result = [];
foreach ($vars as $v) {
    $val = getenv($v);
    $result[$v] = $val === false ? 'NOT SET' : (in_array($v, ['DB_PASS','MYSQLPASSWORD','BOT_TOKEN']) ? '***' : $val);
}

echo json_encode($result, JSON_PRETTY_PRINT);
