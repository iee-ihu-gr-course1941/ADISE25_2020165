<?php
/**
 * Optional MySQL configuration.
 * If this file is missing OR $DB_DRIVER is not "mysql",
 * the app uses SQLite automatically for localhost.
 */

// Set this to "mysql" only when you are ready to use MySQL (e.g. users.iee.ihu.gr).
$DB_DRIVER = 'mysql';

$DB_HOST = '127.0.0.1';
$DB_PORT = 3306;     // users via SSH tunnel: 3307
$DB_NAME = 'xeri';
$DB_USER = 'root';
$DB_PASS = '';
$DB_CHARSET = 'utf8mb4';

// Optional direct socket mode on users (instead of host/port).
// define('DB_SOCKET', '/home/staff/USERNAME/mysql/run/mysql.sock');
