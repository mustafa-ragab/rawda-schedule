<?php
/**
 * ملف الإعدادات الرئيسي للمشروع
 * يحتوي على جميع الإعدادات والدوال المشتركة
 * 
 * @version 1.0
 * @author Rawda Schedule System
 */

// منع الوصول المباشر
defined('PHP_VERSION') or die('Direct access not allowed');

// إعدادات المشروع
define('DB_FILE', __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'schedule.json');
define('IMAGES_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR);
define('UPLOAD_MAX_SIZE', 5 * 1024 * 1024); // 5MB

// إعدادات قاعدة البيانات
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'rawda_schedule');

// الاتصال بقاعدة البيانات
function getDBConnection() {
    static $conn = null;
    // التحقق من أن الاتصال موجود وليس مغلق
    if ($conn === null) {
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            $conn->set_charset("utf8mb4");
            if ($conn->connect_error) {
                die("فشل الاتصال بقاعدة البيانات: " . $conn->connect_error);
            }
        } catch (Exception $e) {
            die("خطأ في الاتصال بقاعدة البيانات: " . $e->getMessage());
        }
    } else {
        // التحقق من أن الاتصال لا يزال نشطاً
        try {
            // محاولة استخدام ping للتحقق من الاتصال
            // إذا كان الاتصال مغلقاً، سيتم التقاط الخطأ
            if (!$conn->ping()) {
                // إذا فشل ping، إنشاء اتصال جديد
                $conn->close();
                $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
                $conn->set_charset("utf8mb4");
                if ($conn->connect_error) {
                    die("فشل الاتصال بقاعدة البيانات: " . $conn->connect_error);
                }
            }
        } catch (Exception $e) {
            // إذا كان الاتصال مغلق أو غير صالح، إنشاء اتصال جديد
            try {
                if (is_object($conn)) {
                    @$conn->close();
                }
                $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
                $conn->set_charset("utf8mb4");
                if ($conn->connect_error) {
                    die("فشل الاتصال بقاعدة البيانات: " . $conn->connect_error);
                }
            } catch (Exception $e2) {
                die("خطأ في الاتصال بقاعدة البيانات: " . $e2->getMessage());
            }
        }
    }
    return $conn;
}

// إنشاء المجلدات المطلوبة
if (!file_exists(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0755, true);
}
if (!file_exists(IMAGES_DIR)) {
    mkdir(IMAGES_DIR, 0755, true);
}

// دالة لقراءة البيانات
function getScheduleData() {
    if (!file_exists(DB_FILE)) {
        return ['weeks' => []];
    }
    $content = file_get_contents(DB_FILE);
    $data = json_decode($content, true);
    if (!$data || !is_array($data)) {
        return ['weeks' => []];
    }
    if (!isset($data['weeks']) || !is_array($data['weeks'])) {
        $data['weeks'] = [];
    }
    return $data;
}

// دالة لحفظ البيانات
function saveScheduleData($data) {
    file_put_contents(DB_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// دالة لتحميل الصور أو PDF
function uploadImage($file, $sessionId) {
    // التحقق من وجود الملف
    if (!isset($file) || !is_array($file) || empty($file['name'])) {
        return ['success' => false, 'message' => 'لم يتم اختيار ملف'];
    }
    
    // التحقق من وجود خطأ في الرفع
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'الملف أكبر من الحد المسموح به في PHP',
            UPLOAD_ERR_FORM_SIZE => 'الملف أكبر من الحد المسموح به في الفورم',
            UPLOAD_ERR_PARTIAL => 'تم رفع الملف جزئياً فقط',
            UPLOAD_ERR_NO_FILE => 'لم يتم اختيار ملف',
            UPLOAD_ERR_NO_TMP_DIR => 'مجلد الملفات المؤقتة غير موجود',
            UPLOAD_ERR_CANT_WRITE => 'فشل كتابة الملف على القرص',
            UPLOAD_ERR_EXTENSION => 'تم إيقاف الرفع بواسطة إضافة PHP'
        ];
        $errorMsg = $errorMessages[$file['error']] ?? 'خطأ غير معروف في رفع الملف';
        return ['success' => false, 'message' => $errorMsg];
    }
    
    // التحقق من نوع الملف
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
    $fileType = mime_content_type($file['tmp_name']);
    if (!in_array($fileType, $allowedTypes) && !in_array($file['type'], $allowedTypes)) {
        // محاولة التحقق من الامتداد كبديل
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
        if (!in_array($extension, $allowedExtensions)) {
            return ['success' => false, 'message' => 'نوع الملف غير مدعوم. يجب أن يكون صورة أو PDF'];
        }
    }
    
    // التحقق من حجم الملف
    if ($file['size'] > UPLOAD_MAX_SIZE) {
        return ['success' => false, 'message' => 'حجم الملف كبير جداً (الحد الأقصى: ' . (UPLOAD_MAX_SIZE / 1024 / 1024) . 'MB)'];
    }
    
    // إنشاء اسم الملف
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'session_' . $sessionId . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $extension;
    $filepath = IMAGES_DIR . $filename;
    
    // التأكد من وجود المجلد
    if (!is_dir(IMAGES_DIR)) {
        mkdir(IMAGES_DIR, 0755, true);
    }
    
    // رفع الملف
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => $filename];
    }
    
    return ['success' => false, 'message' => 'فشل رفع الملف. تحقق من صلاحيات المجلد'];
}

// دالة للحصول على رابط الصورة
function getImageUrl($filename) {
    if (empty($filename)) {
        return null;
    }
    // تنظيف اسم الملف من أي مسارات خطيرة
    $filename = basename($filename);
    // التأكد من أن الملف موجود
    $filepath = IMAGES_DIR . $filename;
    if (!file_exists($filepath)) {
        return null;
    }
    return 'images/' . $filename;
}

// دالة لحذف الصورة
function deleteImage($filename) {
    if (empty($filename)) {
        return ['success' => false, 'message' => 'اسم الملف فارغ'];
    }
    
    // تنظيف اسم الملف من أي مسارات خطيرة
    $filename = basename($filename);
    $filepath = IMAGES_DIR . $filename;
    
    // التأكد من أن الملف داخل المجلد المحدد (منع directory traversal)
    $realPath = realpath($filepath);
    $realImagesDir = realpath(IMAGES_DIR);
    
    if ($realPath === false || $realImagesDir === false || strpos($realPath, $realImagesDir) !== 0) {
        return ['success' => false, 'message' => 'مسار الملف غير صالح'];
    }
    
    if (file_exists($filepath)) {
        if (unlink($filepath)) {
            return ['success' => true, 'message' => 'تم حذف الصورة بنجاح'];
        } else {
            return ['success' => false, 'message' => 'فشل حذف الملف'];
        }
    } else {
        return ['success' => false, 'message' => 'الملف غير موجود'];
    }
}

/**
 * تنظيف المدخلات من HTML و XSS
 * 
 * @param mixed $input المدخل المراد تنظيفه
 * @return mixed المدخل المنظف
 */
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    if (!is_string($input)) {
        return $input;
    }
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * التحقق من صحة التاريخ
 * 
 * @param string $date التاريخ المراد التحقق منه
 * @param string $format تنسيق التاريخ (افتراضي: Y-m-d)
 * @return bool true إذا كان التاريخ صحيحاً
 */
function validateDate($date, $format = 'Y-m-d') {
    if (empty($date) || !is_string($date)) {
        return false;
    }
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * التحقق من صحة رقم المكتب
 * 
 * @param mixed $officeId رقم المكتب
 * @return bool true إذا كان الرقم صحيحاً
 */
function validateOfficeId($officeId) {
    return is_numeric($officeId) && (int)$officeId > 0;
}

/**
 * التحقق من صحة رقم الأسبوع
 * 
 * @param mixed $weekId رقم الأسبوع
 * @return bool true إذا كان الرقم صحيحاً
 */
function validateWeekId($weekId) {
    return is_numeric($weekId) && (int)$weekId > 0;
}

/**
 * بناء رابط آمن مع معاملات GET
 * 
 * @param string $page اسم الصفحة
 * @param array $params معاملات GET
 * @return string الرابط الكامل
 */
function buildUrl($page, $params = []) {
    $query = http_build_query($params);
    return htmlspecialchars($page . ($query ? '?' . $query : ''), ENT_QUOTES, 'UTF-8');
}

