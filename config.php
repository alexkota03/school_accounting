<?php
// إعدادات قاعدة البيانات
define('DB_HOST', 'localhost');
define('DB_NAME', 'school_accounting');
define('DB_USER', 'root');
define('DB_PASS', '');

// إعدادات الجلسة - تم التصحيح
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// إعدادات عامة
define('SITE_NAME', 'نظام المحاسبة المدرسية المتقدم');
define('CURRENCY', 'ج.م');
define('SCHOOL_NAME', 'المدرسة النموذجية');
define('CURRENT_YEAR', '2024-2025');

// إعدادات النظام
define('MAX_FILE_SIZE', 10485760); // 10MB
define('BACKUP_PATH', '../backups/');
define('LOGO_PATH', '../assets/images/logo.png');

// ألوان النظام
define('PRIMARY_COLOR', '#3498db');
define('SECONDARY_COLOR', '#2c3e50');
define('SUCCESS_COLOR', '#27ae60');
define('DANGER_COLOR', '#e74c3c');
define('WARNING_COLOR', '#f39c12');

// إعدادات الوقت
date_default_timezone_set('Africa/Cairo');

// معالجة الأخطاء
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>