<?php
require_once 'config.php';

// بدء الجلسة
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$message = '';
$messageType = '';

// جلب المكاتب - كود محسّن واحترافي
$conn = getDBConnection();
if (!$conn) {
    die('خطأ في الاتصال بقاعدة البيانات');
}

$offices = [];
$officesQuery = "SELECT * FROM offices ORDER BY name";
$officesResult = $conn->query($officesQuery);
if ($officesResult) {
$offices = $officesResult->fetch_all(MYSQLI_ASSOC);
} else {
    error_log("Error fetching offices: " . $conn->error);
}

// معالجة إضافة أسبوع جديد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_week') {
        $officeId = (int)$_POST['office_id'];
        $startDate = $_POST['start_date'];
        
        if ($officeId <= 0) {
            $message = 'يجب اختيار مكتب';
            $messageType = 'error';
        } elseif (empty($startDate)) {
            $message = 'يجب تحديد تاريخ حجز الروضة';
            $messageType = 'error';
        } else {
            // حساب رقم الأسبوع
            $lastWeekQuery = "SELECT week_number FROM weeks WHERE office_id = ? ORDER BY week_number DESC LIMIT 1";
            $stmt = $conn->prepare($lastWeekQuery);
            $stmt->bind_param("i", $officeId);
            $stmt->execute();
            $result = $stmt->get_result();
            $lastWeek = $result->fetch_assoc();
            $stmt->close();
            
            $newWeekNumber = $lastWeek ? $lastWeek['week_number'] + 1 : 1;
            
            // إضافة الجلسات للأسبوع (7 أيام)
            // ترتيب الأيام من الأحد إلى السبت (ثابت)
            $days = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
            $startDateObj = new DateTime($startDate);
            
            // حساب يوم الأسبوع والبدء من الأحد
            $dayOfWeek = (int)$startDateObj->format('w'); // 0 = الأحد, 6 = السبت
            // إذا كان اليوم ليس الأحد، نرجع للخلف حتى نصل للأحد
            if ($dayOfWeek != 0) {
            $startDateObj->modify('-' . $dayOfWeek . ' days');
            }
            
            // حفظ تاريخ الأحد (بداية الأسبوع الفعلية) في قاعدة البيانات
            $actualStartDate = $startDateObj->format('Y-m-d');
            
            // التحقق من وجود أسبوع موجود لنفس المكتب والتاريخ - كود محسّن
            $existingWeekQuery = "SELECT id FROM weeks WHERE office_id = ? AND start_date = ? LIMIT 1";
            $stmt = $conn->prepare($existingWeekQuery);
            $stmt->bind_param("is", $officeId, $actualStartDate);
            $stmt->execute();
            $existingWeekResult = $stmt->get_result();
            $existingWeek = $existingWeekResult ? $existingWeekResult->fetch_assoc() : null;
            $stmt->close();
            
            // إذا كان هناك أسبوع موجود، استخدمه. وإلا أنشئ أسبوع جديد
            if ($existingWeek && !empty($existingWeek['id'])) {
                $weekId = (int)$existingWeek['id'];
                $message = 'تم استخدام الأسبوع الموجود وإضافة الملفات الجديدة إليه!';
                $messageType = 'success';
            } else {
                // إدراج الأسبوع الجديد باستخدام تاريخ الأحد
            $insertWeek = "INSERT INTO weeks (office_id, week_number, start_date) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($insertWeek);
                if ($stmt) {
            $stmt->bind_param("iis", $officeId, $newWeekNumber, $actualStartDate);
                    if ($stmt->execute()) {
            $weekId = $conn->insert_id;
                        $message = 'تم إضافة الأسبوع بنجاح!';
                        $messageType = 'success';
                    } else {
                        $message = 'حدث خطأ أثناء إضافة الأسبوع: ' . $stmt->error;
                        $messageType = 'error';
                        error_log("Error inserting week: " . $stmt->error);
                        $weekId = 0;
                    }
            $stmt->close();
                } else {
                    $message = 'حدث خطأ أثناء إعداد استعلام قاعدة البيانات';
                    $messageType = 'error';
                    error_log("Error preparing week insert query: " . $conn->error);
                    $weekId = 0;
                }
            }
            
            // إذا كان هناك week_id صحيح، أضف الجلسات والملفات
            if ($weekId > 0) {
            for ($i = 0; $i < 7; $i++) {
                $date = clone $startDateObj;
                $date->modify("+$i days");
                $dateStr = $date->format('Y-m-d');
                $dayName = $days[$i];
                
                    // بيانات الرجال والنساء (بدون وقت وبدون تفعيل - دائماً مفعّل)
                    $menTrainer = '';
                    $womenTrainer = '';
                    
                    // التحقق من وجود جلسة موجودة لهذا اليوم - كود محسّن
                    $existingSessionQuery = "SELECT id FROM sessions WHERE week_id = ? AND date = ? LIMIT 1";
                    $stmt = $conn->prepare($existingSessionQuery);
                    if ($stmt) {
                        $stmt->bind_param("is", $weekId, $dateStr);
                        if ($stmt->execute()) {
                            $existingSessionResult = $stmt->get_result();
                            $existingSession = $existingSessionResult ? $existingSessionResult->fetch_assoc() : null;
                        } else {
                            error_log("Error executing existing session query: " . $stmt->error);
                            $existingSession = null;
                        }
                        $stmt->close();
                    } else {
                        error_log("Error preparing existing session query: " . $conn->error);
                        $existingSession = null;
                    }
                    
                    // إذا كان هناك جلسة موجودة، استخدمها. وإلا أنشئ جلسة جديدة
                    if ($existingSession && !empty($existingSession['id'])) {
                        $sessionId = (int)$existingSession['id'];
                        } else {
                        // إدراج الجلسة الجديدة (بدون ملفات)
                        $insertSession = "INSERT INTO sessions (week_id, day_name, date, session_type, men_time, men_trainer, men_image, men_enabled, women_time, women_trainer, women_image, women_enabled) VALUES (?, ?, ?, 'both', '', ?, '', 1, '', ?, '', 1)";
                        $stmt = $conn->prepare($insertSession);
                        if ($stmt) {
                            $stmt->bind_param("issss", 
                                $weekId,        // i
                                $dayName,       // s
                                $dateStr,       // s
                                $menTrainer,    // s
                                $womenTrainer   // s
                            );
                            
                            if ($stmt->execute()) {
                                $sessionId = $conn->insert_id;
                            } else {
                                error_log("Error inserting session: " . $stmt->error);
                                $message = 'حدث خطأ أثناء حفظ البيانات: ' . $stmt->error;
                                $messageType = 'error';
                                $stmt->close();
                                continue;
                            }
                            $stmt->close();
                    } else {
                            error_log("Error preparing session insert query: " . $conn->error);
                            continue;
                        }
                    }
                    
                    // رفع ملفات الرجال المتعددة - كود محسّن واحترافي
                    if (isset($_FILES['men_files']['name'][$i])) {
                        $menFilesArray = [];
                        
                        // معالجة الملفات المتعددة
                        if (is_array($_FILES['men_files']['name'][$i])) {
                            // ملفات متعددة
                            $menFilesCount = count($_FILES['men_files']['name'][$i]);
                            for ($f = 0; $f < $menFilesCount; $f++) {
                                if (!empty($_FILES['men_files']['name'][$i][$f]) && $_FILES['men_files']['error'][$i][$f] === UPLOAD_ERR_OK) {
                                    $menFilesArray[] = [
                                        'name' => $_FILES['men_files']['name'][$i][$f],
                                        'type' => $_FILES['men_files']['type'][$i][$f],
                                        'tmp_name' => $_FILES['men_files']['tmp_name'][$i][$f],
                                        'error' => $_FILES['men_files']['error'][$i][$f],
                                        'size' => $_FILES['men_files']['size'][$i][$f]
                                    ];
                                }
                            }
                        } else {
                            // ملف واحد فقط
                            if (!empty($_FILES['men_files']['name'][$i]) && $_FILES['men_files']['error'][$i] === UPLOAD_ERR_OK) {
                                $menFilesArray[] = [
                                    'name' => $_FILES['men_files']['name'][$i],
                                    'type' => $_FILES['men_files']['type'][$i],
                                    'tmp_name' => $_FILES['men_files']['tmp_name'][$i],
                                    'error' => $_FILES['men_files']['error'][$i],
                                    'size' => $_FILES['men_files']['size'][$i]
                                ];
                            }
                        }
                    
                    // رفع جميع الملفات - ملاحظة مهمة: هذا الكود يضيف الملفات الجديدة فقط
                    // ولا يحذف الملفات القديمة - الملفات القديمة تبقى موجودة حتى يتم حذفها يدوياً
                    foreach ($menFilesArray as $f => $fileArray) {
                        $uploadResult = uploadImage($fileArray, $sessionId . '_men_' . $i . '_' . time() . '_' . $f);
                        if ($uploadResult['success']) {
                            // حفظ الملف في جدول session_files - إضافة فقط، بدون حذف الملفات القديمة
                            $insertFile = "INSERT INTO session_files (session_id, file_type, file_name, file_path) VALUES (?, 'men', ?, ?)";
                            $fileStmt = $conn->prepare($insertFile);
                            if ($fileStmt) {
                                $filePath = getImageUrl($uploadResult['filename']);
                                $fileStmt->bind_param("iss", $sessionId, $uploadResult['filename'], $filePath);
                                if (!$fileStmt->execute()) {
                                    error_log("Error inserting men file for day $i, file $f: " . $fileStmt->error);
                                }
                                $fileStmt->close();
                        }
                    } else {
                            error_log("Error uploading men file for day $i, file $f: " . ($uploadResult['message'] ?? 'Unknown error'));
                        }
                    }
                }
                
                // رفع ملفات النساء المتعددة - كود محسّن واحترافي
                if (isset($_FILES['women_files']['name'][$i])) {
                    $womenFilesArray = [];
                    
                    // معالجة الملفات المتعددة
                    if (is_array($_FILES['women_files']['name'][$i])) {
                        // ملفات متعددة
                        $womenFilesCount = count($_FILES['women_files']['name'][$i]);
                        for ($f = 0; $f < $womenFilesCount; $f++) {
                            if (!empty($_FILES['women_files']['name'][$i][$f]) && $_FILES['women_files']['error'][$i][$f] === UPLOAD_ERR_OK) {
                                $womenFilesArray[] = [
                                    'name' => $_FILES['women_files']['name'][$i][$f],
                                    'type' => $_FILES['women_files']['type'][$i][$f],
                                    'tmp_name' => $_FILES['women_files']['tmp_name'][$i][$f],
                                    'error' => $_FILES['women_files']['error'][$i][$f],
                                    'size' => $_FILES['women_files']['size'][$i][$f]
                                ];
                            }
                        }
                    } else {
                        // ملف واحد فقط
                        if (!empty($_FILES['women_files']['name'][$i]) && $_FILES['women_files']['error'][$i] === UPLOAD_ERR_OK) {
                            $womenFilesArray[] = [
                                'name' => $_FILES['women_files']['name'][$i],
                                'type' => $_FILES['women_files']['type'][$i],
                                'tmp_name' => $_FILES['women_files']['tmp_name'][$i],
                                'error' => $_FILES['women_files']['error'][$i],
                                'size' => $_FILES['women_files']['size'][$i]
                            ];
                        }
                    }
                    
                    // رفع جميع الملفات - ملاحظة مهمة: هذا الكود يضيف الملفات الجديدة فقط
                    // ولا يحذف الملفات القديمة - الملفات القديمة تبقى موجودة حتى يتم حذفها يدوياً
                    foreach ($womenFilesArray as $f => $fileArray) {
                        $uploadResult = uploadImage($fileArray, $sessionId . '_women_' . $i . '_' . time() . '_' . $f);
                        if ($uploadResult['success']) {
                            // حفظ الملف في جدول session_files - إضافة فقط، بدون حذف الملفات القديمة
                            $insertFile = "INSERT INTO session_files (session_id, file_type, file_name, file_path) VALUES (?, 'women', ?, ?)";
                            $fileStmt = $conn->prepare($insertFile);
                            if ($fileStmt) {
                                $filePath = getImageUrl($uploadResult['filename']);
                                $fileStmt->bind_param("iss", $sessionId, $uploadResult['filename'], $filePath);
                                if (!$fileStmt->execute()) {
                                    error_log("Error inserting women file for day $i, file $f: " . $fileStmt->error);
                                }
                                $fileStmt->close();
                            }
                        } else {
                            error_log("Error uploading women file for day $i, file $f: " . ($uploadResult['message'] ?? 'Unknown error'));
                        }
                    }
                }
            }
            
                if ($messageType !== 'error') {
                    $message = 'تم إضافة الأسبوع بنجاح!';
                    $messageType = 'success';
                    // حفظ week_id للانتقال إلى صفحة التعديل
                    $_SESSION['last_added_week_id'] = $weekId;
                }
            }
        }
    }
    
    // معالجة حذف ملف من admin.php - كود محسّن واحترافي
    if ($_POST['action'] === 'delete_file_from_admin') {
        $fileId = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;
        
        if ($fileId > 0) {
            // جلب معلومات الملف
            $fileQuery = "SELECT * FROM session_files WHERE id = ?";
            $stmt = $conn->prepare($fileQuery);
            if ($stmt) {
                $stmt->bind_param("i", $fileId);
                if ($stmt->execute()) {
                    $fileResult = $stmt->get_result();
                    $file = $fileResult ? $fileResult->fetch_assoc() : null;
                    $stmt->close();
                    
                    if ($file && !empty($file['file_name'])) {
                        // حذف الملف من السيرفر
                        $filePath = IMAGES_DIR . $file['file_name'];
                        if (file_exists($filePath) && is_file($filePath)) {
                            @unlink($filePath);
                        }
                        
                        // حذف الملف من قاعدة البيانات
                        $deleteQuery = "DELETE FROM session_files WHERE id = ?";
                        $deleteStmt = $conn->prepare($deleteQuery);
                        if ($deleteStmt) {
                            $deleteStmt->bind_param("i", $fileId);
                            if ($deleteStmt->execute()) {
                                $message = 'تم حذف الملف بنجاح!';
                                $messageType = 'success';
                    } else {
                                $message = 'حدث خطأ أثناء حذف الملف من قاعدة البيانات';
                    $messageType = 'error';
                                error_log("Error deleting file from database: " . $deleteStmt->error);
                            }
                            $deleteStmt->close();
                } else {
                            $message = 'حدث خطأ أثناء إعداد استعلام الحذف';
                            $messageType = 'error';
                            error_log("Error preparing delete query: " . $conn->error);
                        }
                    } else {
                        $message = 'الملف غير موجود';
                        $messageType = 'error';
                    }
                } else {
                    $message = 'حدث خطأ أثناء جلب معلومات الملف';
                    $messageType = 'error';
                    error_log("Error executing file query: " . $stmt->error);
                $stmt->close();
                }
            } else {
                $message = 'حدث خطأ أثناء إعداد استعلام قاعدة البيانات';
                $messageType = 'error';
                error_log("Error preparing file query: " . $conn->error);
            }
            
            // إعادة تحميل الصفحة مع الحفاظ على المكتب والتاريخ
            $redirectUrl = 'admin.php';
            $params = [];
            if (isset($_GET['office_id']) && validateOfficeId($_GET['office_id'])) {
                $params['office_id'] = (int)$_GET['office_id'];
            }
            if (isset($_GET['start_date']) && validateDate($_GET['start_date'])) {
                $params['start_date'] = urlencode($_GET['start_date']);
            }
            if (!empty($params)) {
                $redirectUrl .= '?' . http_build_query($params);
            }
            header("Location: $redirectUrl");
            exit;
        } else {
            $message = 'معرف الملف غير صحيح';
            $messageType = 'error';
        }
    }
    
    // معالجة حذف أسبوع كامل - كود محسّن واحترافي
    if ($_POST['action'] === 'delete_week') {
        $weekId = isset($_POST['week_id']) ? (int)$_POST['week_id'] : 0;
        
        if ($weekId > 0 && validateWeekId($weekId)) {
            // بدء معاملة قاعدة البيانات
            $conn->begin_transaction();
            
            try {
                // جلب جميع session_id المرتبطة بهذا الأسبوع
                $sessionsQuery = "SELECT id FROM sessions WHERE week_id = ?";
                $stmt = $conn->prepare($sessionsQuery);
                if ($stmt) {
                    $stmt->bind_param("i", $weekId);
                    $stmt->execute();
                    $sessionsResult = $stmt->get_result();
                    $sessionIds = [];
                    while ($row = $sessionsResult->fetch_assoc()) {
                        $sessionIds[] = (int)$row['id'];
                    }
                    $stmt->close();
                    
                    // حذف جميع الملفات المرتبطة بالجلسات
                    if (!empty($sessionIds)) {
                        $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
                        $deleteFilesQuery = "DELETE FROM session_files WHERE session_id IN ($placeholders)";
                        $fileStmt = $conn->prepare($deleteFilesQuery);
                        if ($fileStmt) {
                            $types = str_repeat('i', count($sessionIds));
                            $fileStmt->bind_param($types, ...$sessionIds);
                            $fileStmt->execute();
                            $fileStmt->close();
                        }
                        
                        // حذف جميع الجلسات
                        $deleteSessionsQuery = "DELETE FROM sessions WHERE week_id = ?";
                        $sessionStmt = $conn->prepare($deleteSessionsQuery);
                        if ($sessionStmt) {
                            $sessionStmt->bind_param("i", $weekId);
                            $sessionStmt->execute();
                            $sessionStmt->close();
                        }
                    }
                    
                    // حذف الأسبوع نفسه
                    $deleteWeekQuery = "DELETE FROM weeks WHERE id = ?";
                    $weekStmt = $conn->prepare($deleteWeekQuery);
                    if ($weekStmt) {
                        $weekStmt->bind_param("i", $weekId);
                        if ($weekStmt->execute()) {
                            $conn->commit();
                            $message = 'تم حذف الأسبوع وجميع الملفات والجلسات المرتبطة به بنجاح!';
                            $messageType = 'success';
                        } else {
                            throw new Exception("Error deleting week: " . $weekStmt->error);
                        }
                        $weekStmt->close();
                    } else {
                        throw new Exception("Error preparing week delete query: " . $conn->error);
                    }
                } else {
                    throw new Exception("Error preparing sessions query: " . $conn->error);
                }
            } catch (Exception $e) {
                $conn->rollback();
                $message = 'حدث خطأ أثناء حذف الأسبوع: ' . $e->getMessage();
                    $messageType = 'error';
                error_log("Error deleting week: " . $e->getMessage());
            }
            
            // إعادة تحميل الصفحة
            $redirectUrl = 'admin.php';
            $params = [];
            if (isset($_GET['office_id']) && validateOfficeId($_GET['office_id'])) {
                $params['office_id'] = (int)$_GET['office_id'];
            }
            if (isset($_GET['start_date']) && validateDate($_GET['start_date'])) {
                $params['start_date'] = urlencode($_GET['start_date']);
            }
            if (!empty($params)) {
                $redirectUrl .= '?' . http_build_query($params);
            }
            header("Location: $redirectUrl");
            exit;
                } else {
            $message = 'معرف الأسبوع غير صحيح';
            $messageType = 'error';
        }
    }
    
    // معالجة إضافة ملفات جديدة من admin.php - كود محسّن واحترافي مع معالجة أخطاء شاملة
    if ($_POST['action'] === 'add_files_from_admin') {
        $sessionId = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
        $fileType = isset($_POST['file_type']) && in_array($_POST['file_type'], ['men', 'women']) ? $_POST['file_type'] : '';
        $dayIndex = isset($_POST['day_index']) ? (int)$_POST['day_index'] : 0;
        
        // التحقق من صحة البيانات
        if ($sessionId <= 0) {
            $message = 'خطأ: معرف الجلسة غير صحيح';
            $messageType = 'error';
        } elseif (empty($fileType)) {
            $message = 'خطأ: نوع الملف غير صحيح';
            $messageType = 'error';
        } else {
            // التحقق من وجود الجلسة
            $sessionQuery = "SELECT * FROM sessions WHERE id = ? LIMIT 1";
            $stmt = $conn->prepare($sessionQuery);
            if ($stmt) {
                $stmt->bind_param("i", $sessionId);
                if ($stmt->execute()) {
                    $sessionResult = $stmt->get_result();
                    $session = $sessionResult ? $sessionResult->fetch_assoc() : null;
                $stmt->close();
                    
                    if ($session && isset($_FILES['add_files']['name'][$dayIndex])) {
            $uploaded = 0;
            $errors = [];
            $filesArray = [];
            
            // معالجة الملفات المتعددة - كود محسّن واحترافي
            if (is_array($_FILES['add_files']['name'][$dayIndex])) {
                // ملفات متعددة
                $filesCount = count($_FILES['add_files']['name'][$dayIndex]);
                for ($f = 0; $f < $filesCount; $f++) {
                    if (!empty($_FILES['add_files']['name'][$dayIndex][$f]) && $_FILES['add_files']['error'][$dayIndex][$f] === UPLOAD_ERR_OK) {
                        $filesArray[] = [
                            'name' => $_FILES['add_files']['name'][$dayIndex][$f],
                            'type' => $_FILES['add_files']['type'][$dayIndex][$f],
                            'tmp_name' => $_FILES['add_files']['tmp_name'][$dayIndex][$f],
                            'error' => $_FILES['add_files']['error'][$dayIndex][$f],
                            'size' => $_FILES['add_files']['size'][$dayIndex][$f]
                        ];
                    }
                }
            } else {
                // ملف واحد فقط
                if (!empty($_FILES['add_files']['name'][$dayIndex]) && $_FILES['add_files']['error'][$dayIndex] === UPLOAD_ERR_OK) {
                    $filesArray[] = [
                        'name' => $_FILES['add_files']['name'][$dayIndex],
                        'type' => $_FILES['add_files']['type'][$dayIndex],
                        'tmp_name' => $_FILES['add_files']['tmp_name'][$dayIndex],
                        'error' => $_FILES['add_files']['error'][$dayIndex],
                        'size' => $_FILES['add_files']['size'][$dayIndex]
                    ];
                }
            }
            
            // رفع جميع الملفات - ملاحظة مهمة: هذا الكود يضيف الملفات الجديدة فقط
            // ولا يحذف الملفات القديمة - الملفات القديمة تبقى موجودة حتى يتم حذفها يدوياً
            foreach ($filesArray as $f => $fileArray) {
                $uploadResult = uploadImage($fileArray, $sessionId . '_' . $fileType . '_' . $dayIndex . '_' . time() . '_' . $f);
                if ($uploadResult['success']) {
                    // حفظ الملف في جدول session_files - إضافة فقط، بدون حذف الملفات القديمة
                    $insertFile = "INSERT INTO session_files (session_id, file_type, file_name, file_path) VALUES (?, ?, ?, ?)";
                    $fileStmt = $conn->prepare($insertFile);
                    if ($fileStmt) {
                        $filePath = getImageUrl($uploadResult['filename']);
                        $fileStmt->bind_param("isss", $sessionId, $fileType, $uploadResult['filename'], $filePath);
                        if ($fileStmt->execute()) {
                            $uploaded++;
                        } else {
                            $errors[] = "خطأ في حفظ الملف: " . $fileStmt->error;
                            error_log("Error inserting file: " . $fileStmt->error);
                        }
                        $fileStmt->close();
                    } else {
                        $errors[] = "خطأ في إعداد استعلام قاعدة البيانات";
                        error_log("Error preparing insert file query: " . $conn->error);
                    }
                } else {
                    $errors[] = "خطأ في رفع الملف: " . ($uploadResult['message'] ?? 'خطأ غير معروف');
                    error_log("Error uploading file: " . ($uploadResult['message'] ?? 'Unknown error'));
                }
            }
            
                        if ($uploaded > 0) {
                            $message = "تم إضافة $uploaded ملف بنجاح!";
                $messageType = 'success';
                        } else {
                            $message = 'لم يتم رفع أي ملفات. ' . (!empty($errors) ? implode(', ', $errors) : 'يرجى التأكد من اختيار ملفات صحيحة.');
                    $messageType = 'error';
                        }
                } else {
                        if (!$session) {
                            $message = 'خطأ: الجلسة غير موجودة';
                            $messageType = 'error';
                        } else {
                            $message = 'خطأ: لم يتم اختيار أي ملفات';
                            $messageType = 'error';
                        }
                    }
                } else {
                    $message = 'خطأ في جلب معلومات الجلسة: ' . $stmt->error;
                    $messageType = 'error';
                    $stmt->close();
                }
            } else {
                $message = 'خطأ في إعداد استعلام قاعدة البيانات: ' . $conn->error;
                $messageType = 'error';
            }
        }
        
        // إعادة تحميل الصفحة مع الحفاظ على المكتب والتاريخ
        $redirectUrl = 'admin.php';
        $params = [];
        if (isset($_GET['office_id']) && validateOfficeId($_GET['office_id'])) {
            $params['office_id'] = (int)$_GET['office_id'];
        }
        if (isset($_GET['start_date']) && validateDate($_GET['start_date'])) {
            $params['start_date'] = urlencode($_GET['start_date']);
        }
        if (!empty($params)) {
            $redirectUrl .= '?' . http_build_query($params);
        }
        header("Location: $redirectUrl");
        exit;
    }
}

// البحث عن week_id من office_id و start_date (إذا كانا موجودين في GET)
// سيتم جلب البيانات لاحقاً في الكود

// جلب الأسابيع للمكتب المحدد فقط - كود محسّن واحترافي
$selectedOfficeIdForTable = 0;
if (isset($_GET['office_id']) && !empty($_GET['office_id'])) {
    $selectedOfficeIdForTable = (int)$_GET['office_id'];
} elseif (isset($currentWeekData) && !empty($currentWeekData['office_id'])) {
    $selectedOfficeIdForTable = (int)$currentWeekData['office_id'];
}

$allWeeks = [];
$officeNameForTable = '';

if ($selectedOfficeIdForTable > 0) {
    // جلب اسم المكتب - كود محسّن واحترافي
    $officeNameQuery = "SELECT name FROM offices WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($officeNameQuery);
    if ($stmt) {
        $stmt->bind_param("i", $selectedOfficeIdForTable);
        if ($stmt->execute()) {
            $officeResult = $stmt->get_result();
            $officeRow = $officeResult ? $officeResult->fetch_assoc() : null;
            $officeNameForTable = $officeRow && !empty($officeRow['name']) ? htmlspecialchars(trim($officeRow['name']), ENT_QUOTES, 'UTF-8') : '';
        } else {
            error_log("Error executing office name query: " . $stmt->error);
        }
        $stmt->close();
    } else {
        error_log("Error preparing office name query: " . $conn->error);
    }
    
    // جلب الأسابيع للمكتب المحدد فقط - كود محسّن واحترافي
    $allWeeksQuery = "SELECT w.*, o.name as office_name 
                       FROM weeks w 
                       LEFT JOIN offices o ON w.office_id = o.id 
                       WHERE w.office_id = ?
                       ORDER BY w.start_date DESC, w.week_number DESC 
                       LIMIT 50";
    $stmt = $conn->prepare($allWeeksQuery);
    if ($stmt) {
        $stmt->bind_param("i", $selectedOfficeIdForTable);
        if ($stmt->execute()) {
            $allWeeksResult = $stmt->get_result();
            if ($allWeeksResult) {
                $allWeeks = $allWeeksResult->fetch_all(MYSQLI_ASSOC);
            }
        } else {
            error_log("Error executing weeks query: " . $stmt->error);
        }
        $stmt->close();
    } else {
        error_log("Error preparing weeks query: " . $conn->error);
    }
    
    // جلب الملفات لكل أسبوع - كود محسّن واحترافي
    foreach ($allWeeks as &$week) {
        $weekId = isset($week['id']) ? (int)$week['id'] : 0;
        
        if ($weekId <= 0) {
            continue;
        }
        
        // جلب الجلسات للأسبوع - كود محسّن واحترافي
        $sessionsQuery = "SELECT * FROM sessions WHERE week_id = ? ORDER BY date ASC";
        $stmt = $conn->prepare($sessionsQuery);
        if ($stmt) {
            $stmt->bind_param("i", $weekId);
            if ($stmt->execute()) {
                $sessionsResult = $stmt->get_result();
                $sessions = [];
                if ($sessionsResult) {
                    while ($row = $sessionsResult->fetch_assoc()) {
                        if (!empty($row['date'])) {
                            $sessions[$row['date']] = $row;
                        }
                    }
                }
            } else {
                error_log("Error executing sessions query for week $weekId: " . $stmt->error);
            }
            $stmt->close();
        } else {
            error_log("Error preparing sessions query for week $weekId: " . $conn->error);
        }
        
        // حساب تواريخ الأيام
        $days = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        $startDate = new DateTime($week['start_date']);
        $dayOfWeek = (int)$startDate->format('w');
        if ($dayOfWeek != 0) {
            $startDate->modify('-' . $dayOfWeek . ' days');
        }
        
        $week['days_files'] = [];
        for ($i = 0; $i < 7; $i++) {
            $date = clone $startDate;
            $date->modify("+$i days");
            $dateStr = $date->format('Y-m-d');
            
            $session = isset($sessions[$dateStr]) ? $sessions[$dateStr] : null;
            $dayFiles = [
                'day_name' => $days[$i],
                'date' => $dateStr,
                'men_files' => [],
                'women_files' => []
            ];
            
            if ($session) {
                // جلب ملفات الرجال مع معلومات كاملة - كود محسّن واحترافي
                $menFilesQuery = "SELECT id, file_name, file_path FROM session_files WHERE session_id = ? AND file_type = 'men' ORDER BY id ASC";
                $fileStmt = $conn->prepare($menFilesQuery);
                if ($fileStmt) {
                    $fileStmt->bind_param("i", $session['id']);
                    if ($fileStmt->execute()) {
                        $menFilesResult = $fileStmt->get_result();
                        while ($fileRow = $menFilesResult->fetch_assoc()) {
                            $dayFiles['men_files'][] = [
                                'id' => $fileRow['id'],
                                'file_name' => $fileRow['file_name'],
                                'file_path' => $fileRow['file_path']
                            ];
                        }
                    }
                    $fileStmt->close();
                }
                
                // دعم البيانات القديمة
                if (empty($dayFiles['men_files']) && !empty($session['men_image'])) {
                    $dayFiles['men_files'][] = [
                        'id' => 0,
                        'file_name' => $session['men_image'],
                        'file_path' => getImageUrl($session['men_image']),
                        'is_old' => true
                    ];
                }
                
                // جلب ملفات النساء مع معلومات كاملة - كود محسّن واحترافي
                $womenFilesQuery = "SELECT id, file_name, file_path FROM session_files WHERE session_id = ? AND file_type = 'women' ORDER BY id ASC";
                $fileStmt = $conn->prepare($womenFilesQuery);
                if ($fileStmt) {
                    $fileStmt->bind_param("i", $session['id']);
                    if ($fileStmt->execute()) {
                        $womenFilesResult = $fileStmt->get_result();
                        while ($fileRow = $womenFilesResult->fetch_assoc()) {
                            $dayFiles['women_files'][] = [
                                'id' => $fileRow['id'],
                                'file_name' => $fileRow['file_name'],
                                'file_path' => $fileRow['file_path']
                            ];
                        }
                    }
                    $fileStmt->close();
                }
                
                // دعم البيانات القديمة
                if (empty($dayFiles['women_files']) && !empty($session['women_image'])) {
                    $dayFiles['women_files'][] = [
                        'id' => 0,
                        'file_name' => $session['women_image'],
                        'file_path' => getImageUrl($session['women_image']),
                        'is_old' => true
                    ];
                }
                
                // حفظ session_id للاستخدام في الأزرار
                $dayFiles['session_id'] = $session['id'];
            }
            
            $week['days_files'][] = $dayFiles;
        }
    }
    unset($week);
}

// جلب بيانات الأسبوع المحدد (إذا كان موجوداً) لعرض الملفات
$currentWeekId = isset($_GET['week_id']) ? (int)$_GET['week_id'] : 0;
$currentWeekData = null;
$currentWeekFiles = [];

// إذا لم يكن هناك week_id، حاول البحث من office_id و start_date
if ($currentWeekId <= 0 && isset($_GET['office_id']) && isset($_GET['start_date'])) {
    $searchOfficeId = (int)$_GET['office_id'];
    $searchStartDate = $_GET['start_date'];
    
    // حساب تاريخ الأحد
    $searchDateObj = new DateTime($searchStartDate);
    $dayOfWeek = (int)$searchDateObj->format('w');
    if ($dayOfWeek != 0) {
        $searchDateObj->modify('-' . $dayOfWeek . ' days');
    }
    $actualStartDate = $searchDateObj->format('Y-m-d');
    
    // البحث عن الأسبوع
    $searchWeekQuery = "SELECT * FROM weeks WHERE office_id = ? AND start_date = ? LIMIT 1";
    $stmt = $conn->prepare($searchWeekQuery);
    if ($stmt) {
        $stmt->bind_param("is", $searchOfficeId, $actualStartDate);
        $stmt->execute();
        $searchResult = $stmt->get_result();
        $foundWeek = $searchResult->fetch_assoc();
        $stmt->close();
        
        if ($foundWeek) {
            $currentWeekId = $foundWeek['id'];
            $currentWeekData = $foundWeek;
        }
    }
}

// إذا لم يكن هناك currentWeekData، جلبها من week_id
if ($currentWeekId > 0 && !$currentWeekData) {
    $weekQuery = "SELECT * FROM weeks WHERE id = ?";
    $stmt = $conn->prepare($weekQuery);
    if ($stmt) {
        $stmt->bind_param("i", $currentWeekId);
        $stmt->execute();
        $weekResult = $stmt->get_result();
        $currentWeekData = $weekResult->fetch_assoc();
        $stmt->close();
    }
}

// جلب الملفات إذا كان هناك أسبوع محدد (سواء تم جلب currentWeekData من قبل أم لا)
if ($currentWeekId > 0 && $currentWeekData) {
    // جلب الجلسات
    $sessionsQuery = "SELECT * FROM sessions WHERE week_id = ? ORDER BY date ASC";
    $stmt = $conn->prepare($sessionsQuery);
    if ($stmt) {
        $stmt->bind_param("i", $currentWeekId);
        $stmt->execute();
        $sessionsResult = $stmt->get_result();
        $sessions = [];
        while ($row = $sessionsResult->fetch_assoc()) {
            $sessions[$row['date']] = $row;
        }
        $stmt->close();
        
        // جلب الملفات لكل جلسة
        $days = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        $startDate = new DateTime($currentWeekData['start_date']);
        $dayOfWeek = (int)$startDate->format('w');
        if ($dayOfWeek != 0) {
            $startDate->modify('-' . $dayOfWeek . ' days');
        }
        
        for ($i = 0; $i < 7; $i++) {
            $date = clone $startDate;
            $date->modify("+$i days");
            $dateStr = $date->format('Y-m-d');
            
            $session = isset($sessions[$dateStr]) ? $sessions[$dateStr] : null;
            if ($session) {
                // جلب ملفات الرجال
                $menFilesQuery = "SELECT * FROM session_files WHERE session_id = ? AND file_type = 'men' ORDER BY id";
                $fileStmt = $conn->prepare($menFilesQuery);
                $menFiles = [];
                if ($fileStmt) {
                    $fileStmt->bind_param("i", $session['id']);
                    $fileStmt->execute();
                    $menFilesResult = $fileStmt->get_result();
                    while ($fileRow = $menFilesResult->fetch_assoc()) {
                        $menFiles[] = $fileRow;
                    }
                    $fileStmt->close();
                }
                
                // جلب ملفات النساء
                $womenFilesQuery = "SELECT * FROM session_files WHERE session_id = ? AND file_type = 'women' ORDER BY id";
                $fileStmt = $conn->prepare($womenFilesQuery);
                $womenFiles = [];
                if ($fileStmt) {
                    $fileStmt->bind_param("i", $session['id']);
                    $fileStmt->execute();
                    $womenFilesResult = $fileStmt->get_result();
                    while ($fileRow = $womenFilesResult->fetch_assoc()) {
                        $womenFiles[] = $fileRow;
                    }
                    $fileStmt->close();
                }
                
                // دعم البيانات القديمة
                if (empty($menFiles) && !empty($session['men_image'])) {
                    $menFiles[] = [
                        'id' => 0,
                        'file_name' => $session['men_image'],
                        'file_path' => getImageUrl($session['men_image']),
                        'is_old' => true
                    ];
                }
                
                if (empty($womenFiles) && !empty($session['women_image'])) {
                    $womenFiles[] = [
                        'id' => 0,
                        'file_name' => $session['women_image'],
                        'file_path' => getImageUrl($session['women_image']),
                        'is_old' => true
                    ];
                }
                
                $currentWeekFiles[$dateStr] = [
                    'session_id' => $session['id'],
                    'men_files' => $menFiles,
                    'women_files' => $womenFiles
                ];
            } else {
                $currentWeekFiles[$dateStr] = [
                    'session_id' => null,
                    'men_files' => [],
                    'women_files' => []
                ];
            }
        }
    }
}

// لا نغلق الاتصال هنا - نتركه مفتوحاً للاستخدام في الصفحة
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إضافة بيانات - جدول الروضة</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
        }
        .form-card {
            background: white;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        .day-row {
            background: #f9f9f9;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 5px;
            border: 1px solid #ddd;
        }
        .day-row h4 {
            margin: 0 0 15px 0;
            color: #1a4d7a;
        }
        .session-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }
        .session-box {
            background: white;
            padding: 15px;
            border-radius: 5px;
            border: 2px solid #ddd;
        }
        .session-box.men {
            border-color: #4a9eff;
        }
        .session-box.women {
            border-color: #ff4444;
        }
        .btn-submit {
            background: #4caf50;
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 5px;
            font-size: 18px;
            cursor: pointer;
            width: 100%;
            margin-top: 20px;
        }
        .btn-submit:hover {
            background: #45a049;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            margin-left: 10px;
            padding: 12px 25px;
            background: #e3f2fd;
            color: #1976d2;
            text-decoration: none;
            font-weight: bold;
            font-size: 16px;
            border-radius: 8px;
            border: 2px solid #90caf9;
            transition: all 0.3s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .back-link:hover {
            background: #bbdefb;
            color: #1565c0;
            border-color: #64b5f6;
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            transform: translateY(-2px);
            text-decoration: none;
        }
        .back-link.office-link {
            background: #fff3e0;
            color: #f57c00;
            border-color: #ffb74d;
        }
        .back-link.office-link:hover {
            background: #ffe0b2;
            color: #e65100;
            border-color: #ffa726;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="index.php" class="back-link">← العودة للجدول</a>
            <a href="add_office.php" class="back-link office-link">🏢 إدارة المكاتب</a>
        </div>
        
        <div class="form-card">
            <h1 style="text-align: center; color: #1a4d7a; margin-bottom: 30px;">إضافة أسبوع جديد</h1>
            
            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>" style="padding: 15px; margin-bottom: 20px; border-radius: 5px; text-align: center; <?php echo $messageType === 'success' ? 'background: #4caf50; color: white;' : 'background: #f44336; color: white;'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                    <?php if ($messageType === 'success' && isset($_SESSION['last_added_week_id'])): ?>
                        <div style="margin-top: 15px;">
                            <a href="edit_week.php?week_id=<?php echo isset($_SESSION['last_added_week_id']) && validateWeekId($_SESSION['last_added_week_id']) ? (int)$_SESSION['last_added_week_id'] : 0; ?>" 
                               style="display: inline-block; padding: 10px 20px; background: #2196F3; color: white; text-decoration: none; border-radius: 5px; font-weight: bold; margin-top: 10px;">
                                ✏️ تعديل الأسبوع المضافة (حذف/إضافة ملفات)
                            </a>
                            <?php unset($_SESSION['last_added_week_id']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_week">
                <?php if ($currentWeekId > 0): ?>
                    <input type="hidden" name="edit_week_id" value="<?php echo $currentWeekId; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label>المكتب:</label>
                    <select name="office_id" id="office_select" required onchange="loadWeekFiles()">
                        <option value="">اختر المكتب</option>
                        <?php 
                        $selectedOfficeId = isset($_GET['office_id']) ? (int)$_GET['office_id'] : 0;
                        if ($currentWeekData) {
                            $selectedOfficeId = $currentWeekData['office_id'];
                        }
                        foreach ($offices as $office): ?>
                            <option value="<?php echo $office['id']; ?>" <?php echo ($selectedOfficeId == $office['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($office['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>تاريخ حجز الروضة:</label>
                        <?php
                    $selectedStartDate = '';
                    if (isset($_GET['start_date']) && !empty($_GET['start_date'])) {
                        // التحقق من صحة التاريخ
                        $dateCheck = DateTime::createFromFormat('Y-m-d', $_GET['start_date']);
                        if ($dateCheck) {
                            $selectedStartDate = $_GET['start_date'];
                        }
                    } elseif ($currentWeekData && !empty($currentWeekData['start_date'])) {
                        // التحقق من صحة التاريخ
                        $dateCheck = DateTime::createFromFormat('Y-m-d', $currentWeekData['start_date']);
                        if ($dateCheck) {
                            $selectedStartDate = $currentWeekData['start_date'];
                        }
                    }
                    
                    // إذا لم يكن هناك تاريخ محدد، استخدم تاريخ اليوم
                    if (empty($selectedStartDate)) {
                        $selectedStartDate = date('Y-m-d');
                    }
                    ?>
                    <input type="date" 
                           name="start_date" 
                           id="start_date" 
                           value="<?php echo htmlspecialchars($selectedStartDate); ?>" 
                           required 
                           onchange="loadWeekFiles()"
                           min="<?php echo date('Y-m-d', strtotime('-1 year')); ?>"
                           max="<?php echo date('Y-m-d', strtotime('+2 years')); ?>"
                           style="padding: 10px; font-size: 16px; border: 2px solid #1a4d7a; border-radius: 5px; width: 100%; max-width: 300px;">
                    <small style="color: #666; display: block; margin-top: 5px;">اختر تاريخ حجز الروضة (سيتم حساب بداية الأسبوع تلقائياً)</small>
                </div>
                
                <?php if (isset($_GET['office_id']) && isset($_GET['start_date']) && !empty($_GET['office_id']) && !empty($_GET['start_date'])): ?>
                    <div style="padding: 15px; background: linear-gradient(135deg, #fff3cd 0%, #ffe0b2 100%); border-radius: 8px; margin-bottom: 20px; border: 3px solid #ff9800; box-shadow: 0 3px 6px rgba(255,152,0,0.3);">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                            <span style="font-size: 20px;">⚠️</span>
                            <strong style="color: #e65100; font-size: 16px; font-weight: bold;">تحذير مهم:</strong>
                </div>
                        <p style="color: #bf360c; font-size: 14px; margin-bottom: 12px; line-height: 1.6; font-weight: 500;">
                            <?php if ($currentWeekId > 0 && $currentWeekData): ?>
                                أنت تقوم بتعديل أسبوع موجود. سيتم إضافة الملفات الجديدة إلى الملفات الموجودة.
                            <?php else: ?>
                                سيتم إنشاء أسبوع جديد أو إضافة الملفات إلى الأسبوع الموجود (إن وجد).
                            <?php endif; ?>
                        </p>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px;">
                            <?php if ($currentWeekId > 0 && $currentWeekData): ?>
                                <a href="<?php echo isset($currentWeekId) && validateWeekId($currentWeekId) ? buildUrl('edit_week.php', ['week_id' => (int)$currentWeekId]) : '#'; ?>" 
                                   style="display: inline-block; padding: 10px 20px; background: linear-gradient(135deg, #2196F3 0%, #1976d2 100%); color: white; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 14px; box-shadow: 0 2px 4px rgba(33,150,243,0.3); transition: all 0.3s; border: 2px solid #0d47a1;"
                                   onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 6px rgba(33,150,243,0.4)';"
                                   onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(33,150,243,0.3)';">
                                    ✏️ تعديل الأسبوع (حذف/إضافة ملفات)
                                </a>
                                <form method="POST" style="display: inline-block;" onsubmit="return confirm('⚠️ هل أنت متأكد من حذف هذا الأسبوع بالكامل؟ سيتم حذف جميع الملفات والجلسات المرتبطة به!');">
                                    <input type="hidden" name="action" value="delete_week">
                                    <input type="hidden" name="week_id" value="<?php echo isset($currentWeekId) && validateWeekId($currentWeekId) ? (int)$currentWeekId : 0; ?>">
                                    <button type="submit" 
                                            style="padding: 10px 20px; background: linear-gradient(135deg, #f44336 0%, #d32f2f 100%); color: white; border: 2px solid #b71c1c; border-radius: 6px; font-weight: bold; font-size: 14px; cursor: pointer; box-shadow: 0 2px 4px rgba(244,67,54,0.3); transition: all 0.3s;"
                                            onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 6px rgba(244,67,54,0.4)';"
                                            onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(244,67,54,0.3)';">
                                        🗑️ حذف الأسبوع
                                    </button>
                                </form>
                            <?php else: ?>
                                <div style="padding: 8px 15px; background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%); border-radius: 6px; border: 2px solid #2196F3; color: #1976d2; font-size: 13px; font-weight: 500;">
                                    ℹ️ سيتم إنشاء أسبوع جديد عند إرسال النموذج
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <h2 style="color: #1a4d7a; margin-top: 30px; margin-bottom: 20px;">بيانات الأيام (7 أيام)</h2>
                
                <?php 
                // ترتيب الأيام من الأحد إلى السبت (ثابت)
                $days = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
                
                // حساب تواريخ الأيام بناءً على تاريخ حجز الروضة المحدد - كود محسّن
                $weekStartDate = null;
                $baseDate = null;
                
                // تحديد التاريخ الأساسي للحساب
                if ($currentWeekData && !empty($currentWeekData['start_date'])) {
                    // إذا كان هناك أسبوع محدد، استخدم تاريخ بداية الأسبوع
                    $baseDate = new DateTime($currentWeekData['start_date']);
                } elseif (isset($_GET['start_date']) && !empty($_GET['start_date'])) {
                    // إذا كان هناك تاريخ في GET، استخدمه
                    $dateCheck = DateTime::createFromFormat('Y-m-d', $_GET['start_date']);
                    if ($dateCheck) {
                        $baseDate = $dateCheck;
                    }
                } elseif (!empty($selectedStartDate)) {
                    // استخدم التاريخ المحدد في النموذج
                    $dateCheck = DateTime::createFromFormat('Y-m-d', $selectedStartDate);
                    if ($dateCheck) {
                        $baseDate = $dateCheck;
                    }
                }
                
                // حساب تاريخ بداية الأسبوع (الأحد)
                if ($baseDate) {
                    $weekStartDate = clone $baseDate;
                    $dayOfWeek = (int)$weekStartDate->format('w');
                    // إذا كان اليوم ليس الأحد، نرجع للخلف حتى نصل للأحد
                    if ($dayOfWeek != 0) {
                        $weekStartDate->modify('-' . $dayOfWeek . ' days');
                    }
                }
                
                for ($i = 0; $i < 7; $i++):
                    // حساب تاريخ اليوم - دائماً يتم الحساب إذا كان هناك تاريخ أساسي
                    $dayDate = null;
                    $dayDateStr = '';
                    $dayDateFormatted = '';
                    
                    if ($weekStartDate) {
                        $dayDate = clone $weekStartDate;
                        $dayDate->modify("+$i days");
                        $dayDateStr = $dayDate->format('Y-m-d');
                        $dayDateFormatted = $dayDate->format('d/m/Y');
                    }
                    
                    // جلب ملفات اليوم (إذا كان هناك أسبوع محدد)
                    $dayMenFiles = [];
                    $dayWomenFiles = [];
                    $daySessionId = null;
                    $hasFiles = false;
                    $totalFilesCount = 0;
                    $menFilesCount = 0;
                    $womenFilesCount = 0;
                    
                    if ($dayDateStr && isset($currentWeekFiles[$dayDateStr])) {
                        $dayMenFiles = $currentWeekFiles[$dayDateStr]['men_files'];
                        $dayWomenFiles = $currentWeekFiles[$dayDateStr]['women_files'];
                        $daySessionId = $currentWeekFiles[$dayDateStr]['session_id'];
                        $hasFiles = !empty($dayMenFiles) || !empty($dayWomenFiles);
                    }
                    
                    // حساب عدد الملفات للعرض
                    if (is_array($dayMenFiles)) {
                        $menFilesCount = count($dayMenFiles);
                    }
                    if (is_array($dayWomenFiles)) {
                        $womenFilesCount = count($dayWomenFiles);
                    }
                    $totalFilesCount = $menFilesCount + $womenFilesCount;
                    if ($totalFilesCount > 0) {
                        $hasFiles = true;
                    }
                ?>
                    <div class="day-row" style="position: relative;">
                        <h4>
                            <?php echo $days[$i]; ?>
                            <?php if ($dayDateFormatted): ?>
                                <span style="font-size: 0.9em; color: #1976d2; font-weight: bold; margin-right: 8px; padding: 4px 10px; background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%); border-radius: 5px; border: 1px solid #90caf9; display: inline-block;">
                                    📅 <?php echo htmlspecialchars($dayDateFormatted, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            <?php else: ?>
                                <span style="font-size: 0.85em; color: #999; font-weight: normal; margin-right: 8px; font-style: italic;">
                                    (اختر تاريخ حجز الروضة لعرض التواريخ)
                                </span>
                            <?php endif; ?>
                            <?php if ($hasFiles): ?>
                                <span style="background: linear-gradient(135deg, #4caf50 0%, #388e3c 100%); color: white; padding: 5px 12px; border-radius: 5px; font-size: 0.75em; margin-right: 10px; font-weight: bold; box-shadow: 0 2px 4px rgba(76,175,80,0.3);">
                                    ✓ يوجد ملفات: <strong><?php echo $totalFilesCount; ?></strong> ملف
                                    <?php if ($menFilesCount > 0 && $womenFilesCount > 0): ?>
                                        <span style="font-size: 0.9em; opacity: 0.9;">(رجال: <?php echo $menFilesCount; ?> | نساء: <?php echo $womenFilesCount; ?>)</span>
                                    <?php elseif ($menFilesCount > 0): ?>
                                        <span style="font-size: 0.9em; opacity: 0.9;">(رجال فقط: <?php echo $menFilesCount; ?>)</span>
                                    <?php elseif ($womenFilesCount > 0): ?>
                                        <span style="font-size: 0.9em; opacity: 0.9;">(نساء فقط: <?php echo $womenFilesCount; ?>)</span>
                                    <?php endif; ?>
                                </span>
                            <?php else: ?>
                                <span style="background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%); color: white; padding: 5px 12px; border-radius: 5px; font-size: 0.75em; margin-right: 10px; font-weight: bold; box-shadow: 0 2px 4px rgba(255,152,0,0.3);">
                                    ⚠ لا يوجد ملفات
                                </span>
                            <?php endif; ?>
                            <?php if ($currentWeekId > 0 && $hasFiles): ?>
                                <a href="<?php echo isset($currentWeekId) && validateWeekId($currentWeekId) ? buildUrl('edit_week.php', ['week_id' => (int)$currentWeekId]) : '#'; ?>" 
                                   style="display: inline-block; padding: 5px 12px; background: #2196F3; color: white; text-decoration: none; border-radius: 5px; font-size: 12px; margin-right: 10px; font-weight: bold;">
                                    ✏️ تعديل/حذف ملفات هذا اليوم
                                </a>
                            <?php endif; ?>
                        </h4>
                        
                        <div class="session-group">
                            <div class="session-box men">
                                <h5 style="color: #4a9eff; margin-top: 0;">رجال 👨</h5>
                                
                                
                                <!-- عرض الملفات المضافة بشكل محسّن -->
                                <?php if (!empty($dayMenFiles)): ?>
                                    <div class="files-list" style="margin-bottom: 15px; padding: 12px; background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%); border-radius: 8px; border: 2px solid #90caf9; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                        <strong style="display: block; margin-bottom: 10px; color: #1976d2; font-size: 14px;">📁 الملفات المضافة (<?php echo count($dayMenFiles); ?>):</strong>
                                        <div style="max-height: 150px; overflow-y: auto; padding-right: 5px;">
                                            <?php foreach ($dayMenFiles as $fileIndex => $file): 
                                                // معالجة بيانات الملف - كود محسّن
                                                $fileId = is_array($file) && isset($file['id']) ? (int)$file['id'] : 0;
                                                $fileName = is_array($file) && isset($file['file_name']) ? $file['file_name'] : (is_string($file) ? $file : '');
                                                $filePath = is_array($file) && isset($file['file_path']) ? $file['file_path'] : (is_string($file) ? getImageUrl($file) : '');
                                                $isOld = is_array($file) && isset($file['is_old']) ? $file['is_old'] : false;
                                                $displayName = htmlspecialchars(basename($fileName), ENT_QUOTES, 'UTF-8');
                                                
                                                // التأكد من وجود file_path
                                                if (empty($filePath) && !empty($fileName)) {
                                                    $filePath = getImageUrl($fileName);
                                                }
                                            ?>
                                                <div class="file-item" style="display: flex; justify-content: space-between; align-items: center; padding: 8px 10px; margin: 5px 0; background: white; border-radius: 6px; border: 1px solid #90caf9; box-shadow: 0 1px 2px rgba(0,0,0,0.05); transition: all 0.2s;" onmouseover="this.style.background='#f5f5f5'; this.style.transform='translateX(-2px)';" onmouseout="this.style.background='white'; this.style.transform='translateX(0)';">
                                                    <div style="flex: 1; display: flex; align-items: center; gap: 8px;">
                                                        <span style="color: #1976d2; font-size: 16px;">📄</span>
                                                        <a href="<?php echo htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" style="flex: 1; color: #1a4d7a; text-decoration: none; font-size: 12px; word-break: break-word; font-weight: 500;" onmouseover="this.style.color='#1976d2'; this.style.textDecoration='underline';" onmouseout="this.style.color='#1a4d7a'; this.style.textDecoration='none';" title="<?php echo $displayName; ?>">
                                                            <?php echo $displayName; ?>
                                                        </a>
                                </div>
                                                    <?php if (!$isOld && $fileId > 0): ?>
                                                        <form method="POST" style="display: inline; margin-right: 5px;" onsubmit="return confirm('هل أنت متأكد من حذف الملف: <?php echo htmlspecialchars(addslashes($displayName)); ?>؟');">
                                                            <input type="hidden" name="action" value="delete_file_from_admin">
                                                            <input type="hidden" name="file_id" value="<?php echo $fileId; ?>">
                                                            <button type="submit" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; border: none; padding: 6px 12px; border-radius: 5px; cursor: pointer; font-size: 11px; font-weight: bold; box-shadow: 0 2px 4px rgba(220,53,69,0.3); transition: all 0.3s; white-space: nowrap;" onmouseover="this.style.transform='scale(1.08)'; this.style.boxShadow='0 4px 8px rgba(220,53,69,0.5)';" onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 2px 4px rgba(220,53,69,0.3)';" title="حذف الملف">🗑️ حذف</button>
                                                        </form>
                                                    <?php endif; ?>
                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- إضافة ملفات جديدة - دعم ملفات متعددة -->
                                <div class="form-group" style="margin-bottom: 10px;">
                                    <label style="font-weight: bold; color: #4a9eff; display: block; margin-bottom: 8px; font-size: 14px;">
                                        <?php echo !empty($dayMenFiles) ? '➕ إضافة ملفات أخرى للرجال (يمكن إضافة أكثر من ملف):' : '📤 إضافة ملفات للرجال (يمكن إضافة أكثر من ملف):'; ?>
                                    </label>
                                    <input type="file" 
                                           name="men_files[<?php echo $i; ?>][]" 
                                           accept=".pdf,.jpg,.jpeg,.png,.gif" 
                                           multiple 
                                           style="width: 100%; padding: 10px; border: 2px solid #4a9eff; border-radius: 6px; background: white; font-size: 13px; transition: all 0.3s; cursor: pointer;" 
                                           onchange="handleFileSelection(this, 'men', <?php echo $i; ?>);" 
                                           onfocus="this.style.borderColor='#1976d2'; this.style.boxShadow='0 0 0 3px rgba(74,158,255,0.1)';" 
                                           onblur="this.style.borderColor='#4a9eff'; this.style.boxShadow='none';"
                                           id="men_files_<?php echo $i; ?>">
                                    <small style="color: #1976d2; display: block; margin-top: 5px; font-size: 11px; font-weight: bold; background: #e3f2fd; padding: 6px; border-radius: 4px; border-right: 3px solid #4a9eff;">
                                        💡 <strong>يمكنك اختيار أكثر من ملف للرجال:</strong> اضغط على Ctrl (أو Cmd على Mac) ثم اختر الملفات، أو اسحب الملفات مباشرة. يمكنك إضافة ملفين أو أكثر في نفس الوقت!
                                    </small>
                                    <div id="men_files_preview_<?php echo $i; ?>" style="margin-top: 8px; padding: 8px; background: #f0f7ff; border-radius: 4px; border: 1px dashed #90caf9; display: none;">
                                        <strong style="color: #1976d2; font-size: 11px;">📋 الملفات المحددة:</strong>
                                        <div id="men_files_list_<?php echo $i; ?>" style="margin-top: 5px; font-size: 11px; color: #333;"></div>
                                </div>
                                </div>
                            </div>
                            
                            <div class="session-box women">
                                <h5 style="color: #ff4444; margin-top: 0;">نساء 👩</h5>
                                
                                
                                <!-- عرض الملفات المضافة بشكل محسّن -->
                                <?php if (!empty($dayWomenFiles)): ?>
                                    <div class="files-list" style="margin-bottom: 15px; padding: 12px; background: linear-gradient(135deg, #ffebee 0%, #fce4ec 100%); border-radius: 8px; border: 2px solid #ef9a9a; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                        <strong style="display: block; margin-bottom: 10px; color: #c2185b; font-size: 14px;">📁 الملفات المضافة (<?php echo count($dayWomenFiles); ?>):</strong>
                                        <div style="max-height: 150px; overflow-y: auto; padding-right: 5px;">
                                            <?php foreach ($dayWomenFiles as $fileIndex => $file): 
                                                // معالجة بيانات الملف - كود محسّن
                                                $fileId = is_array($file) && isset($file['id']) ? (int)$file['id'] : 0;
                                                $fileName = is_array($file) && isset($file['file_name']) ? $file['file_name'] : (is_string($file) ? $file : '');
                                                $filePath = is_array($file) && isset($file['file_path']) ? $file['file_path'] : (is_string($file) ? getImageUrl($file) : '');
                                                $isOld = is_array($file) && isset($file['is_old']) ? $file['is_old'] : false;
                                                $displayName = htmlspecialchars(basename($fileName), ENT_QUOTES, 'UTF-8');
                                                
                                                // التأكد من وجود file_path
                                                if (empty($filePath) && !empty($fileName)) {
                                                    $filePath = getImageUrl($fileName);
                                                }
                                            ?>
                                                <div class="file-item" style="display: flex; justify-content: space-between; align-items: center; padding: 8px 10px; margin: 5px 0; background: white; border-radius: 6px; border: 1px solid #ef9a9a; box-shadow: 0 1px 2px rgba(0,0,0,0.05); transition: all 0.2s;" onmouseover="this.style.background='#f5f5f5'; this.style.transform='translateX(-2px)';" onmouseout="this.style.background='white'; this.style.transform='translateX(0)';">
                                                    <div style="flex: 1; display: flex; align-items: center; gap: 8px;">
                                                        <span style="color: #c2185b; font-size: 16px;">📄</span>
                                                        <a href="<?php echo htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" style="flex: 1; color: #1a4d7a; text-decoration: none; font-size: 12px; word-break: break-word; font-weight: 500;" onmouseover="this.style.color='#c2185b'; this.style.textDecoration='underline';" onmouseout="this.style.color='#1a4d7a'; this.style.textDecoration='none';" title="<?php echo $displayName; ?>">
                                                            <?php echo $displayName; ?>
                                                        </a>
                                </div>
                                                    <?php if (!$isOld && $fileId > 0): ?>
                                                        <form method="POST" style="display: inline; margin-right: 5px;" onsubmit="return confirm('هل أنت متأكد من حذف الملف: <?php echo htmlspecialchars(addslashes($displayName)); ?>؟');">
                                                            <input type="hidden" name="action" value="delete_file_from_admin">
                                                            <input type="hidden" name="file_id" value="<?php echo $fileId; ?>">
                                                            <button type="submit" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; border: none; padding: 6px 12px; border-radius: 5px; cursor: pointer; font-size: 11px; font-weight: bold; box-shadow: 0 2px 4px rgba(220,53,69,0.3); transition: all 0.3s; white-space: nowrap;" onmouseover="this.style.transform='scale(1.08)'; this.style.boxShadow='0 4px 8px rgba(220,53,69,0.5)';" onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 2px 4px rgba(220,53,69,0.3)';" title="حذف الملف">🗑️ حذف</button>
                                                        </form>
                                                    <?php endif; ?>
                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- إضافة ملفات جديدة - دعم ملفات متعددة -->
                                <div class="form-group" style="margin-bottom: 10px;">
                                    <label style="font-weight: bold; color: #ff4444; display: block; margin-bottom: 8px; font-size: 14px;">
                                        <?php echo !empty($dayWomenFiles) ? '➕ إضافة ملفات أخرى للنساء (يمكن إضافة أكثر من ملف):' : '📤 إضافة ملفات للنساء (يمكن إضافة أكثر من ملف):'; ?>
                                    </label>
                                    <input type="file" 
                                           name="women_files[<?php echo $i; ?>][]" 
                                           accept=".pdf,.jpg,.jpeg,.png,.gif" 
                                           multiple 
                                           style="width: 100%; padding: 10px; border: 2px solid #ff4444; border-radius: 6px; background: white; font-size: 13px; transition: all 0.3s; cursor: pointer;" 
                                           onchange="handleFileSelection(this, 'women', <?php echo $i; ?>);" 
                                           onfocus="this.style.borderColor='#d32f2f'; this.style.boxShadow='0 0 0 3px rgba(255,68,68,0.1)';" 
                                           onblur="this.style.borderColor='#ff4444'; this.style.boxShadow='none';"
                                           id="women_files_<?php echo $i; ?>">
                                    <small style="color: #c2185b; display: block; margin-top: 5px; font-size: 11px; font-weight: bold; background: #ffebee; padding: 6px; border-radius: 4px; border-right: 3px solid #ff4444;">
                                        💡 <strong>يمكنك اختيار أكثر من ملف للنساء:</strong> اضغط على Ctrl (أو Cmd على Mac) ثم اختر الملفات، أو اسحب الملفات مباشرة. يمكنك إضافة ملفين أو أكثر في نفس الوقت!
                                    </small>
                                    <div id="women_files_preview_<?php echo $i; ?>" style="margin-top: 8px; padding: 8px; background: #fff0f0; border-radius: 4px; border: 1px dashed #ef9a9a; display: none;">
                                        <strong style="color: #c2185b; font-size: 11px;">📋 الملفات المحددة:</strong>
                                        <div id="women_files_list_<?php echo $i; ?>" style="margin-top: 5px; font-size: 11px; color: #333;"></div>
                                </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endfor; ?>
                
                <button type="submit" class="btn-submit">حفظ الأسبوع</button>
            </form>
        </div>
        
        <!-- قائمة الأسابيع للمكتب المحدد -->
        <?php if (!empty($allWeeks) && $selectedOfficeIdForTable > 0): ?>
        <div class="form-card" style="margin-top: 40px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <h2 style="text-align: center; color: #1a4d7a; margin-bottom: 20px; font-size: 1.8em;">
                أسابيع مكتب: <span style="color: #2196F3; font-weight: bold;"><?php echo $officeNameForTable; ?></span>
            </h2>
            
            <div style="overflow-x: auto; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <table style="width: 100%; border-collapse: collapse; background: white;">
                    <thead>
                        <tr style="background: linear-gradient(135deg, #1a4d7a 0%, #2c6da0 100%); color: white;">
                            <th style="padding: 15px; text-align: center; border: 1px solid #1a4d7a; font-weight: bold; font-size: 14px;">رقم الأسبوع</th>
                            <th style="padding: 15px; text-align: center; border: 1px solid #1a4d7a; font-weight: bold; font-size: 14px;">تاريخ بداية الأسبوع</th>
                            <?php 
                            $days = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
                            foreach ($days as $day): 
                            ?>
                                <th style="padding: 12px; text-align: center; border: 1px solid #1a4d7a; font-weight: bold; font-size: 12px; background: rgba(255,255,255,0.1);">
                                    <?php echo $day; ?>
                                </th>
                            <?php endforeach; ?>
                            <th style="padding: 15px; text-align: center; border: 1px solid #1a4d7a; font-weight: bold; font-size: 14px;">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allWeeks as $index => $week): 
                            $weekNumber = isset($week['week_number']) ? (int)$week['week_number'] : 0;
                            $startDate = !empty($week['start_date']) ? date('d/m/Y', strtotime($week['start_date'])) : 'غير محدد';
                            $weekId = isset($week['id']) ? (int)$week['id'] : 0;
                            $rowBg = $index % 2 == 0 ? '#f8f9fa' : '#ffffff';
                        ?>
                            <tr style="background: <?php echo $rowBg; ?>; transition: background 0.2s;" onmouseover="this.style.background='#e3f2fd';" onmouseout="this.style.background='<?php echo $rowBg; ?>';">
                                <td style="padding: 15px; text-align: center; border: 1px solid #dee2e6; color: #1a4d7a; font-weight: bold; font-size: 16px;">
                                    <?php echo $weekNumber > 0 ? $weekNumber : '-'; ?>
                                </td>
                                <td style="padding: 15px; text-align: center; border: 1px solid #dee2e6; color: #333; font-weight: 500; font-size: 14px;">
                                    <?php echo $startDate; ?>
                                </td>
                                <?php 
                                $daysFiles = isset($week['days_files']) ? $week['days_files'] : [];
                                for ($d = 0; $d < 7; $d++):
                                    $dayData = isset($daysFiles[$d]) ? $daysFiles[$d] : ['men_files' => [], 'women_files' => [], 'session_id' => null];
                                    $menFiles = is_array($dayData['men_files'] ?? []) ? $dayData['men_files'] : [];
                                    $womenFiles = is_array($dayData['women_files'] ?? []) ? $dayData['women_files'] : [];
                                    $sessionId = isset($dayData['session_id']) ? (int)$dayData['session_id'] : 0;
                                    $totalFiles = count($menFiles) + count($womenFiles);
                                    $dayName = isset($dayData['day_name']) ? $dayData['day_name'] : '';
                                    $dayDate = isset($dayData['date']) ? $dayData['date'] : '';
                                ?>
                                    <td style="padding: 8px; text-align: center; border: 1px solid #dee2e6; vertical-align: top; min-width: 180px; max-width: 250px;">
                                        <?php if ($totalFiles > 0): ?>
                                            <div style="background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%); padding: 10px; border-radius: 6px; border: 2px solid #81c784; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                                <?php if (!empty($menFiles)): ?>
                                                    <div style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px dashed #90caf9;">
                                                        <strong style="color: #1976d2; font-size: 12px; display: block; margin-bottom: 5px; font-weight: bold;">👨 رجال:</strong>
                                                        <div style="max-height: 120px; overflow-y: auto; padding-right: 3px;">
                                                            <?php foreach ($menFiles as $fileIndex => $file): 
                                                                $fileId = is_array($file) && isset($file['id']) ? (int)$file['id'] : 0;
                                                                $fileName = is_array($file) && isset($file['file_name']) ? $file['file_name'] : (is_string($file) ? $file : '');
                                                                $filePath = is_array($file) && isset($file['file_path']) ? $file['file_path'] : (is_string($file) ? getImageUrl($file) : '');
                                                                $isOld = is_array($file) && isset($file['is_old']) ? $file['is_old'] : false;
                                                                $displayName = htmlspecialchars(basename($fileName), ENT_QUOTES, 'UTF-8');
                                                            ?>
                                                                <div style="font-size: 10px; color: #333; margin: 4px 0; padding: 6px; background: white; border-radius: 4px; border: 1px solid #90caf9; display: flex; align-items: center; justify-content: space-between; gap: 5px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                                                    <a href="<?php echo htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" style="flex: 1; color: #1976d2; text-decoration: none; font-weight: 500; word-break: break-all; font-size: 10px;" onmouseover="this.style.textDecoration='underline';" onmouseout="this.style.textDecoration='none';" title="<?php echo $displayName; ?>">
                                                                        📄 <?php echo strlen($displayName) > 25 ? substr($displayName, 0, 25) . '...' : $displayName; ?>
                                                                    </a>
                                                                    <?php if (!$isOld && $fileId > 0): ?>
                                                                        <form method="POST" style="display: inline; margin: 0;" onsubmit="return confirm('هل أنت متأكد من حذف الملف: <?php echo htmlspecialchars(addslashes($displayName)); ?>؟');">
                                                                            <input type="hidden" name="action" value="delete_file_from_admin">
                                                                            <input type="hidden" name="file_id" value="<?php echo $fileId; ?>">
                                                                            <button type="submit" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; border: none; padding: 3px 8px; border-radius: 4px; cursor: pointer; font-size: 9px; font-weight: bold; box-shadow: 0 1px 2px rgba(220,53,69,0.3); transition: all 0.2s; white-space: nowrap; flex-shrink: 0;" onmouseover="this.style.transform='scale(1.1)'; this.style.boxShadow='0 2px 4px rgba(220,53,69,0.5)';" onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 1px 2px rgba(220,53,69,0.3)';" title="حذف الملف">🗑️</button>
                                                                        </form>
                                                                    <?php endif; ?>
    </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if (!empty($womenFiles)): ?>
                                                    <div style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px dashed #ef9a9a;">
                                                        <strong style="color: #c2185b; font-size: 12px; display: block; margin-bottom: 5px; font-weight: bold;">👩 نساء:</strong>
                                                        <div style="max-height: 120px; overflow-y: auto; padding-right: 3px;">
                                                            <?php foreach ($womenFiles as $fileIndex => $file): 
                                                                $fileId = is_array($file) && isset($file['id']) ? (int)$file['id'] : 0;
                                                                $fileName = is_array($file) && isset($file['file_name']) ? $file['file_name'] : (is_string($file) ? $file : '');
                                                                $filePath = is_array($file) && isset($file['file_path']) ? $file['file_path'] : (is_string($file) ? getImageUrl($file) : '');
                                                                $isOld = is_array($file) && isset($file['is_old']) ? $file['is_old'] : false;
                                                                $displayName = htmlspecialchars(basename($fileName), ENT_QUOTES, 'UTF-8');
                                                            ?>
                                                                <div style="font-size: 10px; color: #333; margin: 4px 0; padding: 6px; background: white; border-radius: 4px; border: 1px solid #ef9a9a; display: flex; align-items: center; justify-content: space-between; gap: 5px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                                                    <a href="<?php echo htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" style="flex: 1; color: #c2185b; text-decoration: none; font-weight: 500; word-break: break-all; font-size: 10px;" onmouseover="this.style.textDecoration='underline';" onmouseout="this.style.textDecoration='none';" title="<?php echo $displayName; ?>">
                                                                        📄 <?php echo strlen($displayName) > 25 ? substr($displayName, 0, 25) . '...' : $displayName; ?>
                                                                    </a>
                                                                    <?php if (!$isOld && $fileId > 0): ?>
                                                                        <form method="POST" style="display: inline; margin: 0;" onsubmit="return confirm('هل أنت متأكد من حذف الملف: <?php echo htmlspecialchars(addslashes($displayName)); ?>؟');">
                                                                            <input type="hidden" name="action" value="delete_file_from_admin">
                                                                            <input type="hidden" name="file_id" value="<?php echo $fileId; ?>">
                                                                            <button type="submit" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; border: none; padding: 3px 8px; border-radius: 4px; cursor: pointer; font-size: 9px; font-weight: bold; box-shadow: 0 1px 2px rgba(220,53,69,0.3); transition: all 0.2s; white-space: nowrap; flex-shrink: 0;" onmouseover="this.style.transform='scale(1.1)'; this.style.boxShadow='0 2px 4px rgba(220,53,69,0.5)';" onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 1px 2px rgba(220,53,69,0.3)';" title="حذف الملف">🗑️</button>
                                                                        </form>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <div style="margin-top: 8px; padding-top: 8px; border-top: 2px solid #81c784;">
                                                    <div style="font-size: 11px; color: #2e7d32; font-weight: bold; margin-bottom: 6px;">
                                                        📊 المجموع: <?php echo $totalFiles; ?> ملف
                                                    </div>
                                                    <?php if ($sessionId > 0): ?>
                                                        <form method="POST" enctype="multipart/form-data" style="margin: 0;" id="add_file_form_<?php echo $weekId; ?>_<?php echo $d; ?>">
                                                            <input type="hidden" name="action" value="add_files_from_admin">
                                                            <input type="hidden" name="session_id" value="<?php echo $sessionId; ?>">
                                                            <input type="hidden" name="day_index" value="<?php echo $d; ?>">
                                                            <div style="display: flex; gap: 4px; margin-bottom: 4px;">
                                                                <select name="file_type" style="flex: 1; padding: 4px; border: 1px solid #81c784; border-radius: 4px; font-size: 10px; background: white;" required>
                                                                    <option value="men">رجال</option>
                                                                    <option value="women">نساء</option>
                                                                </select>
                                                                <input type="file" name="add_files[<?php echo $d; ?>][]" accept=".pdf,.jpg,.jpeg,.png,.gif" multiple style="display: none;" id="file_input_<?php echo $weekId; ?>_<?php echo $d; ?>" onchange="document.getElementById('add_file_form_<?php echo $weekId; ?>_<?php echo $d; ?>').submit();">
                                                                <button type="button" onclick="document.getElementById('file_input_<?php echo $weekId; ?>_<?php echo $d; ?>').click();" style="flex: 1; padding: 6px; background: linear-gradient(135deg, #4caf50 0%, #388e3c 100%); color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 10px; font-weight: bold; box-shadow: 0 1px 2px rgba(76,175,80,0.3); transition: all 0.2s; white-space: nowrap;" onmouseover="this.style.transform='scale(1.05)'; this.style.boxShadow='0 2px 4px rgba(76,175,80,0.4)';" onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 1px 2px rgba(76,175,80,0.3)';" title="إضافة ملفات أخرى">➕ إضافة</button>
                                                            </div>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div style="padding: 10px; text-align: center;">
                                                <span style="color: #999; font-size: 11px; display: block; margin-bottom: 8px;">لا توجد ملفات</span>
                                                <?php if ($sessionId > 0): ?>
                                                    <form method="POST" enctype="multipart/form-data" style="margin: 0;" id="add_file_form_empty_<?php echo $weekId; ?>_<?php echo $d; ?>">
                                                        <input type="hidden" name="action" value="add_files_from_admin">
                                                        <input type="hidden" name="session_id" value="<?php echo $sessionId; ?>">
                                                        <input type="hidden" name="day_index" value="<?php echo $d; ?>">
                                                        <div style="display: flex; gap: 4px;">
                                                            <select name="file_type" style="flex: 1; padding: 4px; border: 1px solid #ccc; border-radius: 4px; font-size: 10px; background: white;" required>
                                                                <option value="men">رجال</option>
                                                                <option value="women">نساء</option>
                                                            </select>
                                                            <input type="file" name="add_files[<?php echo $d; ?>][]" accept=".pdf,.jpg,.jpeg,.png,.gif" multiple style="display: none;" id="file_input_empty_<?php echo $weekId; ?>_<?php echo $d; ?>" onchange="document.getElementById('add_file_form_empty_<?php echo $weekId; ?>_<?php echo $d; ?>').submit();">
                                                            <button type="button" onclick="document.getElementById('file_input_empty_<?php echo $weekId; ?>_<?php echo $d; ?>').click();" style="flex: 1; padding: 6px; background: linear-gradient(135deg, #2196F3 0%, #1976d2 100%); color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 10px; font-weight: bold; box-shadow: 0 1px 2px rgba(33,150,243,0.3); transition: all 0.2s; white-space: nowrap;" onmouseover="this.style.transform='scale(1.05)'; this.style.boxShadow='0 2px 4px rgba(33,150,243,0.4)';" onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 1px 2px rgba(33,150,243,0.3)';" title="إضافة ملفات">➕ إضافة</button>
                                                        </div>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                <?php endfor; ?>
                                <td style="padding: 15px; text-align: center; border: 1px solid #dee2e6;">
                                    <?php if ($weekId > 0): ?>
                                        <a href="<?php echo isset($weekId) && validateWeekId($weekId) ? buildUrl('edit_week.php', ['week_id' => (int)$weekId]) : '#'; ?>" 
                                           style="display: inline-block; padding: 10px 18px; background: linear-gradient(135deg, #2196F3 0%, #1976d2 100%); color: white; text-decoration: none; border-radius: 6px; font-size: 14px; transition: all 0.3s; font-weight: bold; box-shadow: 0 2px 4px rgba(33,150,243,0.3);"
                                           onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(33,150,243,0.4)';"
                                           onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(33,150,243,0.3)';">
                                            ✏️ تعديل
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (empty($allWeeks)): ?>
                <div style="text-align: center; padding: 40px; color: #666;">
                    <p style="font-size: 16px;">لا توجد أسابيع مضافة لهذا المكتب بعد.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php elseif ($selectedOfficeIdForTable > 0 && empty($allWeeks)): ?>
        <div class="form-card" style="margin-top: 40px; text-align: center; padding: 40px;">
            <p style="font-size: 16px; color: #666;">
                لا توجد أسابيع مضافة لمكتب <strong><?php echo $officeNameForTable; ?></strong> بعد.
            </p>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        function loadWeekFiles() {
            var officeId = document.getElementById('office_select').value;
            var startDate = document.getElementById('start_date').value;
            
            // إذا كان المكتب أو التاريخ فارغاً، لا تفعل شيء
            if (!officeId || !startDate) {
                return;
            }
            
            // البحث عن week_id من office_id و start_date
            window.location.href = 'admin.php?office_id=' + officeId + '&start_date=' + startDate;
        }
        
        // دالة لعرض الملفات المحددة قبل الرفع
        function handleFileSelection(input, type, dayIndex) {
            // تحديد معرفات العناصر بناءً على النوع
            var previewId, listId;
            if (type === 'men' || type === 'women') {
                previewId = type + '_files_preview_' + dayIndex;
                listId = type + '_files_list_' + dayIndex;
            } else if (type === 'men_add' || type === 'women_add') {
                previewId = type + '_files_preview_' + dayIndex;
                listId = type + '_files_list_' + dayIndex;
            } else {
                return;
            }
            
            var previewDiv = document.getElementById(previewId);
            var listDiv = document.getElementById(listId);
            
            if (!previewDiv || !listDiv) {
                return;
            }
            
            if (!input.files || input.files.length === 0) {
                previewDiv.style.display = 'none';
                return;
            }
            
            var filesList = '';
            var fileCount = input.files.length;
            var color = (type === 'men' || type === 'men_add') ? '#1976d2' : '#c2185b';
            
            for (var i = 0; i < fileCount; i++) {
                var file = input.files[i];
                var fileSize = (file.size / 1024).toFixed(2); // حجم الملف بالكيلوبايت
                var fileIcon = file.type === 'application/pdf' ? '📄' : '🖼️';
                filesList += '<div style="padding: 4px 8px; margin: 3px 0; background: white; border-radius: 3px; border-left: 3px solid ' + color + ';">' +
                           fileIcon + ' <strong>' + file.name + '</strong> <span style="color: #666; font-size: 10px;">(' + fileSize + ' KB)</span>' +
                           '</div>';
            }
            
            listDiv.innerHTML = '<div style="color: ' + color + '; font-weight: bold; margin-bottom: 5px;">إجمالي: ' + fileCount + ' ملف</div>' + filesList;
            previewDiv.style.display = 'block';
        }
        
        // دالة للتحقق من الملفات قبل الرفع - كود محسّن واحترافي
        function validateFileUpload(form, type, dayIndex) {
            try {
                var fileInput = null;
                if (type === 'men' || type === 'men_add') {
                    fileInput = document.getElementById('men_add_files_' + dayIndex);
                } else if (type === 'women' || type === 'women_add') {
                    fileInput = document.getElementById('women_add_files_' + dayIndex);
                }
                
                if (!fileInput) {
                    return true; // إذا لم يتم العثور على الحقل، اترك التحقق للخادم
                }
                
                if (!fileInput.files || fileInput.files.length === 0) {
                    alert('⚠️ يرجى اختيار ملف واحد على الأقل قبل الإرسال!');
                    return false;
                }
                
                // التحقق من حجم الملفات (حد أقصى 10 ميجابايت لكل ملف)
                var maxFileSize = 10 * 1024 * 1024; // 10 MB
                var invalidFiles = [];
                
                for (var i = 0; i < fileInput.files.length; i++) {
                    var file = fileInput.files[i];
                    if (file.size > maxFileSize) {
                        invalidFiles.push(file.name);
                    }
                }
                
                if (invalidFiles.length > 0) {
                    alert('⚠️ الملفات التالية كبيرة جداً (الحد الأقصى 10 ميجابايت لكل ملف):\n' + invalidFiles.join('\n'));
                    return false;
                }
                
                return true;
            } catch (error) {
                console.error('خطأ في التحقق من الملفات:', error);
                return true; // في حالة الخطأ، اترك التحقق للخادم
            }
        }
        
        // حفظ قيمة المكتب عند تحميل الصفحة
        window.onload = function() {
            try {
                var officeSelect = document.getElementById('office_select');
                var startDateInput = document.getElementById('start_date');
                
                // إذا كان هناك office_id في URL، تأكد من اختياره
                var urlParams = new URLSearchParams(window.location.search);
                var officeIdFromUrl = urlParams.get('office_id');
                var startDateFromUrl = urlParams.get('start_date');
                
                if (officeIdFromUrl && officeSelect) {
                    officeSelect.value = officeIdFromUrl;
                }
                
                if (startDateFromUrl && startDateInput) {
                    startDateInput.value = startDateFromUrl;
                }
            } catch (error) {
                console.error('خطأ في تحميل الصفحة:', error);
            }
        };
    </script>
</body>
</html>


