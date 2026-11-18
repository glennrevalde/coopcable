<?php
/**
 * G-SMaRT Coop Cable System - application configuration.
 * Set these to match your MySQL / MariaDB server (MySQL 5.5 or newer).
 * Environment variables (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, APP_DEBUG, APP_KEY) override these values.
 */
return [
    'db' => [
        'host'    => getenv('DB_HOST') ?: 'localhost',
        'port'    => getenv('DB_PORT') ?: '3306',
        // the database of Company 1; the platform (Super Admin) database is this name followed by _master
        'name'    => getenv('DB_NAME') ?: 'gsmart_coop_cable',
        'user'    => getenv('DB_USER') ?: 'root',
        // XAMPP's MySQL/MariaDB root has no password out of the box; put yours here (or set DB_PASS)
        'pass'    => getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name'        => 'G-SMaRT Coop Cable System',
        'timezone'    => 'Asia/Manila',
        'currency'    => '₱',
        'debug'       => (getenv('APP_DEBUG') ?: '0') === '1',
        // folder (inside the project) where uploaded receipts are stored
        'upload_dir'  => __DIR__ . '/../storage/receipts',
        // the key that encrypts network device, RADIUS, SMS, e-mail and payment gateway credentials (AES-256-GCM).
        // Leave it empty to have the system create storage/keys/app.key on first use; set APP_KEY in production.
        'key'         => getenv('APP_KEY') ?: '',
    ],
];
