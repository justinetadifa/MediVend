<?php
declare(strict_types=1);

// Local XAMPP defaults. Optional environment overrides also support testing
// without editing this file or changing the existing database.
return [
    'host' => getenv('MEDIVEND_DB_HOST') ?: 'localhost',
    'port' => (int) (getenv('MEDIVEND_DB_PORT') ?: 3306),
    'database' => getenv('MEDIVEND_DB_NAME') ?: 'medivend_db',
    'username' => getenv('MEDIVEND_DB_USER') !== false ? getenv('MEDIVEND_DB_USER') : 'root',
    'password' => getenv('MEDIVEND_DB_PASSWORD') !== false ? getenv('MEDIVEND_DB_PASSWORD') : '',
    'charset' => 'utf8mb4',
];
