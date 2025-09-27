<?php
if (!defined('BASE_URL')) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $base_dir = '/TMS/public/';
    define('BASE_URL', $protocol . '://' . $host . $base_dir);
}
?>
