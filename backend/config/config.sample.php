<?php
/**
 * Copy this file to config.php (same folder) and fill in the real values.
 * config.php must NEVER be placed inside public_html and must never be committed or emailed.
 * This sample contains no real credentials.
 */
return [
    'app' => [
        // Random string, at least 32 characters. Used to key hashes of IP addresses and names. Generate one with:
        //   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
        'secret'        => 'REPLACE_WITH_A_LONG_RANDOM_STRING',
        'timezone'      => 'Africa/Nairobi',
        'require_https' => true,   // keep true in production; set false only for local testing
        'trust_proxy'   => false,  // true only if the host puts a trusted proxy in front of PHP (then X-Forwarded-For is used)
    ],
    'db' => [
        'host' => 'localhost',
        'name' => 'REPLACE_cpanel_database_name',
        'user' => 'REPLACE_cpanel_database_user',
        'pass' => 'REPLACE_database_password',
    ],
    'session' => [
        'idle_minutes'   => 30,    // sign out after this long without activity
        'absolute_hours' => 8,     // sign out after this long regardless of activity
    ],
    'limits' => [
        'login_ip'   => 20,        // failed sign-ins per IP per 15 minutes before blocking
        'login_name' => 6,         // failed sign-ins per student name per 15 minutes before blocking
    ],
    'uploads' => [
        'max_mb' => 20,            // also limited by PHP upload_max_filesize / post_max_size on the host
    ],
    'mail' => [
        // Messages from the contact and application forms are emailed here AND stored in the database.
        'enquiries_to' => 'harvardextentioncollege@gmail.com',
        // Staff alerts for password-reset requests (leave '' to rely on the admin panel only).
        'notify_to'    => 'harvardextentioncollege@gmail.com',
        // A mailbox on the college's own domain (cPanel > Email Accounts), e.g. no-reply@yourdomain.co.ke.
        // Leave '' to disable email; enquiries are still stored and visible in the admin panel.
        'from'         => '',
        'from_name'    => 'Harvard Extention Training College',
    ],
];
