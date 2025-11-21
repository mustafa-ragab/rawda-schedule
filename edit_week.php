<?php
require_once 'config.php';

$message = '';
$messageType = '';

// جلب المكاتب
$conn = getDBConnection();
$officesQuery = "SELECT * FROM offices ORDER BY name";
$officesResult = $conn->query($officesQuery);
$offices = $officesResult->fetch_all(MYSQLI_ASSOC);

// جلب week_id من URL
$weekId = isset($_GET['week_id']) ? (int)$_GET['week_id'] : 0;

if ($weekId <= 0 || !validateWeekId($weekId)) {
    header('Location: admin.php');
    exit;
}

// جلب بيانات الأسبوع
$weekQuery = "SELECT * FROM weeks WHERE id = ?";
$stmt = $conn->prepare($weekQuery);
$stmt->bind_param("i", $weekId);
$stmt->execute();
$weekResult = $stmt->get_result();
$week = $weekResult->fetch_assoc();
$stmt->close();

if (!$week) {
    header('Location: admin.php');
    exit;
}

// جلب الجلسات للأسبوع
$sessionsQuery = "SELECT * FROM sessions WHERE week_id = ? ORDER BY date ASC";
$stmt = $conn->prepare($sessionsQuery);
$stmt->bind_param("i", $weekId);
$stmt->execute();
$sessionsResult = $stmt->get_result();
$sessions = [];
while ($row = $sessionsResult->fetch_assoc()) {
    $sessions[$row['date']] = $row;
}
$stmt->close();

// جلب الملفات لكل جلسة
$days = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
$startDate = new DateTime($week['start_date']);
$dayOfWeek = (int)$startDate->format('w');
if ($dayOfWeek != 0) {
    $startDate->modify('-' . $dayOfWeek . ' days');
}

$sessionsData = [];
for ($i = 0; $i < 7; $i++) {
    $date = clone $startDate;
    $date->modify("+$i days");
    $dateStr = $date->format('Y-m-d');
    
    $session = isset($sessions[$dateStr]) ? $sessions[$dateStr] : null;
    if ($session) {
        // جلب ملفات الرجال
        $menFilesQuery = "SELECT * FROM session_files WHERE session_id = ? AND file_type = 'men' ORDER BY id";
        $fileStmt = $conn->prepare($menFilesQuery);
        $fileStmt->bind_param("i", $session['id']);
        $fileStmt->execute();
        $menFilesResult = $fileStmt->get_result();
        $menFiles = [];
        while ($fileRow = $menFilesResult->fetch_assoc()) {
            $menFiles[] = $fileRow;
        }
        $fileStmt->close();
        
        // جلب ملفات النساء
        $womenFilesQuery = "SELECT * FROM session_files WHERE session_id = ? AND file_type = 'women' ORDER BY id";
        $fileStmt = $conn->prepare($womenFilesQuery);
        $fileStmt->bind_param("i", $session['id']);
        $fileStmt->execute();
        $womenFilesResult = $fileStmt->get_result();
        $womenFiles = [];
        while ($fileRow = $womenFilesResult->fetch_assoc()) {
            $womenFiles[] = $fileRow;
        }
        $fileStmt->close();
        
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
        
        $sessionsData[$dateStr] = [
            'session' => $session,
            'men_files' => $menFiles,
            'women_files' => $womenFiles
        ];
    } else {
        $sessionsData[$dateStr] = [
            'session' => null,
            'men_files' => [],
            'women_files' => []
        ];
    }
}

// معالجة حذف ملف
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_file') {
    $fileId = (int)$_POST['file_id'];
    
    if ($fileId > 0) {
        // جلب معلومات الملف
        $fileQuery = "SELECT * FROM session_files WHERE id = ?";
        $stmt = $conn->prepare($fileQuery);
        $stmt->bind_param("i", $fileId);
        $stmt->execute();
        $fileResult = $stmt->get_result();
        $file = $fileResult->fetch_assoc();
        $stmt->close();
        
        if ($file) {
            // حذف الملف من السيرفر
            $filePath = IMAGES_DIR . $file['file_name'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
            
            // حذف الملف من قاعدة البيانات
            $deleteQuery = "DELETE FROM session_files WHERE id = ?";
            $stmt = $conn->prepare($deleteQuery);
            $stmt->bind_param("i", $fileId);
            $stmt->execute();
            $stmt->close();
            
            $message = 'تم حذف الملف بنجاح!';
            $messageType = 'success';
            
            // إعادة تحميل الصفحة
            header("Location: edit_week.php?week_id=$weekId");
            exit;
        }
    }
}

// معالجة إضافة ملفات جديدة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_files') {
    $sessionId = (int)$_POST['session_id'];
    $fileType = $_POST['file_type']; // 'men' or 'women'
    $dayIndex = (int)$_POST['day_index'];
    
    // التحقق من وجود الجلسة
    $sessionQuery = "SELECT * FROM sessions WHERE id = ?";
    $stmt = $conn->prepare($sessionQuery);
    $stmt->bind_param("i", $sessionId);
    $stmt->execute();
    $sessionResult = $stmt->get_result();
    $session = $sessionResult->fetch_assoc();
    $stmt->close();
    
    if (!$session) {
        $message = 'الجلسة غير موجودة!';
        $messageType = 'error';
    } else {
        // التحقق من وجود الملفات
        $hasFiles = false;
        $uploaded = 0;
        $errors = [];
        
        // التحقق من وجود الملفات في $_FILES
        if (isset($_FILES['new_files']) && !empty($_FILES['new_files']['name'][$dayIndex])) {
            // إذا كان ملف واحد فقط (ليس array)
            if (!is_array($_FILES['new_files']['name'][$dayIndex])) {
                // تحويله إلى array
                $filesArray = [
                    'name' => [$_FILES['new_files']['name'][$dayIndex]],
                    'type' => [$_FILES['new_files']['type'][$dayIndex]],
                    'tmp_name' => [$_FILES['new_files']['tmp_name'][$dayIndex]],
                    'error' => [$_FILES['new_files']['error'][$dayIndex]],
                    'size' => [$_FILES['new_files']['size'][$dayIndex]]
                ];
            } else {
                $filesArray = [
                    'name' => $_FILES['new_files']['name'][$dayIndex],
                    'type' => $_FILES['new_files']['type'][$dayIndex],
                    'tmp_name' => $_FILES['new_files']['tmp_name'][$dayIndex],
                    'error' => $_FILES['new_files']['error'][$dayIndex],
                    'size' => $_FILES['new_files']['size'][$dayIndex]
                ];
            }
            
            $filesCount = count($filesArray['name']);
            
            for ($f = 0; $f < $filesCount; $f++) {
                if (!empty($filesArray['name'][$f]) && $filesArray['error'][$f] === UPLOAD_ERR_OK) {
                    $fileArray = [
                        'name' => $filesArray['name'][$f],
                        'type' => $filesArray['type'][$f],
                        'tmp_name' => $filesArray['tmp_name'][$f],
                        'error' => $filesArray['error'][$f],
                        'size' => $filesArray['size'][$f]
                    ];
                    
                    $uploadResult = uploadImage($fileArray, $sessionId . '_' . $fileType . '_' . $dayIndex . '_' . time() . '_' . $f);
                    if ($uploadResult['success']) {
                        // حفظ الملف في جدول session_files
                        $insertFile = "INSERT INTO session_files (session_id, file_type, file_name, file_path) VALUES (?, ?, ?, ?)";
                        $fileStmt = $conn->prepare($insertFile);
                        $filePath = getImageUrl($uploadResult['filename']);
                        $fileStmt->bind_param("isss", $sessionId, $fileType, $uploadResult['filename'], $filePath);
                        if ($fileStmt->execute()) {
                            $uploaded++;
                        } else {
                            $errors[] = "خطأ في حفظ الملف: " . $fileStmt->error;
                        }
                        $fileStmt->close();
                    } else {
                        $errors[] = "خطأ في رفع الملف: " . ($uploadResult['message'] ?? 'خطأ غير معروف');
                    }
                } elseif ($filesArray['error'][$f] !== UPLOAD_ERR_OK) {
                    $errors[] = "خطأ في رفع الملف: " . $filesArray['error'][$f];
                }
            }
        }
        
        if ($uploaded > 0) {
            $message = "تم إضافة $uploaded ملف بنجاح!";
            $messageType = 'success';
            if (!empty($errors)) {
                $message .= " (بعض الأخطاء: " . implode(', ', $errors) . ")";
            }
        } else {
            if (empty($_FILES['new_files']['name'][$dayIndex])) {
                $message = 'لم يتم اختيار أي ملفات!';
            } else {
                $message = 'لم يتم رفع أي ملفات. ' . (!empty($errors) ? implode(', ', $errors) : '');
            }
            $messageType = 'error';
        }
        
        // إعادة تحميل الصفحة
        header("Location: edit_week.php?week_id=$weekId");
        exit;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تعديل أسبوع - جدول الروضة</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        
        h1 {
            color: #1a4d7a;
            text-align: center;
            margin-bottom: 30px;
            font-size: 2em;
        }
        
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            padding: 12px 25px;
            background: #e3f2fd;
            color: #1976d2;
            text-decoration: none;
            font-weight: bold;
            border-radius: 8px;
            border: 2px solid #90caf9;
            transition: all 0.3s;
        }
        
        .back-link:hover {
            background: #bbdefb;
            transform: translateY(-2px);
        }
        
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            text-align: center;
            font-weight: bold;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 2px solid #c3e6cb;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 2px solid #f5c6cb;
        }
        
        .week-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            border: 2px solid #dee2e6;
        }
        
        .week-info p {
            margin: 10px 0;
            font-size: 1.1em;
            color: #495057;
        }
        
        .day-row {
            margin-bottom: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 2px solid #dee2e6;
        }
        
        .day-row h4 {
            color: #1a4d7a;
            margin-bottom: 20px;
            font-size: 1.3em;
            text-align: center;
        }
        
        .session-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .session-box {
            padding: 20px;
            border-radius: 10px;
            border: 3px solid;
            position: relative;
            min-height: 200px;
        }
        
        .session-box.men {
            border-color: #4a9eff;
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
        }
        
        .session-box.men.has-files {
            border-color: #1976d2;
            background: linear-gradient(135deg, #e3f2fd 0%, #90caf9 100%);
            box-shadow: 0 4px 12px rgba(25, 118, 210, 0.3);
        }
        
        .session-box.women {
            border-color: #ff4444;
            background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
        }
        
        .session-box.women.has-files {
            border-color: #c2185b;
            background: linear-gradient(135deg, #ffebee 0%, #f8bbd0 100%);
            box-shadow: 0 4px 12px rgba(194, 24, 91, 0.3);
        }
        
        .session-box h5 {
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 1.3em;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .files-count-badge {
            background: rgba(255, 255, 255, 0.9);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: bold;
            color: #1a4d7a;
            border: 2px solid currentColor;
        }
        
        .session-box.men .files-count-badge {
            color: #1976d2;
        }
        
        .session-box.women .files-count-badge {
            color: #c2185b;
        }
        
        .files-status {
            text-align: center;
            padding: 15px;
            margin: 10px 0;
            border-radius: 8px;
            font-weight: bold;
            font-size: 1.1em;
        }
        
        .files-status.no-files {
            background: #fff3cd;
            color: #856404;
            border: 2px dashed #ffc107;
        }
        
        .files-status.has-files {
            background: #d4edda;
            color: #155724;
            border: 2px solid #28a745;
        }
        
        .files-list {
            margin: 15px 0;
            padding: 15px;
            background: white;
            border-radius: 8px;
            border: 2px solid #ddd;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .files-list-header {
            font-weight: bold;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 2px solid #dee2e6;
            color: #495057;
            font-size: 1.05em;
        }
        
        .file-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            margin: 8px 0;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 8px;
            border: 2px solid #dee2e6;
            transition: all 0.3s;
        }
        
        .file-item:hover {
            background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
            transform: translateX(-3px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .file-item a {
            color: #1a4d7a;
            text-decoration: none;
            flex: 1;
            margin-right: 10px;
            font-weight: 600;
            font-size: 0.95em;
            word-break: break-word;
            display: flex;
            align-items: center;
        }
        
        .file-item a::before {
            content: "📄";
            margin-left: 8px;
            font-size: 1.2em;
        }
        
        .file-item a:hover {
            text-decoration: underline;
            color: #1976d2;
        }
        
        .delete-btn {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            white-space: nowrap;
            transition: all 0.3s;
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);
        }
        
        .delete-btn:hover {
            background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.4);
        }
        
        .add-files-form {
            margin-top: 15px;
            padding: 15px;
            background: white;
            border-radius: 8px;
            border: 2px dashed #ccc;
            transition: all 0.3s;
        }
        
        .session-box.has-files .add-files-form {
            border-color: #28a745;
            background: #f0fff4;
        }
        
        .session-box:not(.has-files) .add-files-form {
            border-color: #ffc107;
            background: #fffbf0;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #495057;
        }
        
        .form-group input[type="file"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        
        .btn-submit {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
        }
        
        .btn-submit:hover {
            background: #218838;
        }
        
        .no-session {
            text-align: center;
            color: #856404;
            padding: 20px;
            background: #fff3cd;
            border-radius: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="admin.php" class="back-link">← العودة لإضافة أسبوع جديد</a>
        <a href="index.php" class="back-link" style="margin-right: 10px;">← العودة للجدول</a>
        
        <h1>تعديل أسبوع</h1>
        
        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="week-info">
            <p><strong>المكتب:</strong> <?php 
                $officeName = '';
                foreach ($offices as $office) {
                    if ($office['id'] == $week['office_id']) {
                        $officeName = htmlspecialchars($office['name']);
                        break;
                    }
                }
                echo $officeName;
            ?></p>
            <p><strong>رقم الأسبوع:</strong> <?php echo $week['week_number']; ?></p>
            <p><strong>تاريخ بداية الأسبوع:</strong> <?php echo date('d/m/Y', strtotime($week['start_date'])); ?></p>
        </div>
        
        <h2 style="color: #1a4d7a; margin-top: 30px; margin-bottom: 20px;">بيانات الأيام (7 أيام)</h2>
        
        <?php for ($i = 0; $i < 7; $i++): 
            $date = clone $startDate;
            $date->modify("+$i days");
            $dateStr = $date->format('Y-m-d');
            $dayName = $days[$i];
            $dayData = isset($sessionsData[$dateStr]) ? $sessionsData[$dateStr] : null;
        ?>
            <div class="day-row">
                <h4><?php echo $dayName; ?> - <?php echo date('d/m/Y', strtotime($dateStr)); ?></h4>
                
                <?php if ($dayData && $dayData['session']): 
                    $session = $dayData['session'];
                    $menFiles = $dayData['men_files'];
                    $womenFiles = $dayData['women_files'];
                ?>
                    <div class="session-group">
                        <!-- ملفات الرجال -->
                        <div class="session-box men <?php echo !empty($menFiles) ? 'has-files' : ''; ?>">
                            <h5 style="color: #1976d2;">
                                <span>رجال 👨</span>
                                <?php if (!empty($menFiles)): ?>
                                    <span class="files-count-badge"><?php echo count($menFiles); ?> ملف</span>
                                <?php endif; ?>
                            </h5>
                            
                            <?php if (empty($menFiles)): ?>
                                <div class="files-status no-files">
                                    ⚠️ لا توجد ملفات مضافة
                                </div>
                            <?php else: ?>
                                <div class="files-status has-files">
                                    ✓ يوجد <?php echo count($menFiles); ?> ملف مضاف
                                </div>
                                <div class="files-list">
                                    <div class="files-list-header">📁 قائمة الملفات المضافة:</div>
                                    <?php foreach ($menFiles as $index => $file): ?>
                                        <div class="file-item">
                                            <a href="<?php echo htmlspecialchars($file['file_path']); ?>" target="_blank" title="افتح الملف: <?php echo htmlspecialchars($file['file_name']); ?>">
                                                <?php echo htmlspecialchars($file['file_name']); ?>
                                            </a>
                                            <?php if (!isset($file['is_old']) || !$file['is_old']): ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('هل أنت متأكد من حذف هذا الملف؟');">
                                                    <input type="hidden" name="action" value="delete_file">
                                                    <input type="hidden" name="file_id" value="<?php echo $file['id']; ?>">
                                                    <button type="submit" class="delete-btn">🗑️ حذف</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="add-files-form">
                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="add_files">
                                    <input type="hidden" name="session_id" value="<?php echo $session['id']; ?>">
                                    <input type="hidden" name="file_type" value="men">
                                    <input type="hidden" name="day_index" value="<?php echo $i; ?>">
                                    <div class="form-group">
                                        <label style="display: flex; align-items: center; gap: 8px;">
                                            <span>➕ إضافة ملفات جديدة:</span>
                                            <?php if (!empty($menFiles)): ?>
                                                <span style="font-size: 0.85em; color: #28a745; font-weight: normal;">(يمكن إضافة المزيد)</span>
                                            <?php endif; ?>
                                        </label>
                                        <input type="file" name="new_files[<?php echo $i; ?>][]" accept=".pdf,.jpg,.jpeg,.png,.gif" multiple>
                                        <small style="color: #666; display: block; margin-top: 5px;">📎 يمكنك اختيار أكثر من ملف (PDF, JPG, PNG, GIF)</small>
                                    </div>
                                    <button type="submit" class="btn-submit">💾 حفظ الملفات</button>
                                </form>
                            </div>
                        </div>
                        
                        <!-- ملفات النساء -->
                        <div class="session-box women <?php echo !empty($womenFiles) ? 'has-files' : ''; ?>">
                            <h5 style="color: #c2185b;">
                                <span>نساء 👩</span>
                                <?php if (!empty($womenFiles)): ?>
                                    <span class="files-count-badge"><?php echo count($womenFiles); ?> ملف</span>
                                <?php endif; ?>
                            </h5>
                            
                            <?php if (empty($womenFiles)): ?>
                                <div class="files-status no-files">
                                    ⚠️ لا توجد ملفات مضافة
                                </div>
                            <?php else: ?>
                                <div class="files-status has-files">
                                    ✓ يوجد <?php echo count($womenFiles); ?> ملف مضاف
                                </div>
                                <div class="files-list">
                                    <div class="files-list-header">📁 قائمة الملفات المضافة:</div>
                                    <?php foreach ($womenFiles as $index => $file): ?>
                                        <div class="file-item">
                                            <a href="<?php echo htmlspecialchars($file['file_path']); ?>" target="_blank" title="افتح الملف: <?php echo htmlspecialchars($file['file_name']); ?>">
                                                <?php echo htmlspecialchars($file['file_name']); ?>
                                            </a>
                                            <?php if (!isset($file['is_old']) || !$file['is_old']): ?>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('هل أنت متأكد من حذف هذا الملف؟');">
                                                    <input type="hidden" name="action" value="delete_file">
                                                    <input type="hidden" name="file_id" value="<?php echo $file['id']; ?>">
                                                    <button type="submit" class="delete-btn">🗑️ حذف</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="add-files-form">
                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="add_files">
                                    <input type="hidden" name="session_id" value="<?php echo $session['id']; ?>">
                                    <input type="hidden" name="file_type" value="women">
                                    <input type="hidden" name="day_index" value="<?php echo $i; ?>">
                                    <div class="form-group">
                                        <label style="display: flex; align-items: center; gap: 8px;">
                                            <span>➕ إضافة ملفات جديدة:</span>
                                            <?php if (!empty($womenFiles)): ?>
                                                <span style="font-size: 0.85em; color: #28a745; font-weight: normal;">(يمكن إضافة المزيد)</span>
                                            <?php endif; ?>
                                        </label>
                                        <input type="file" name="new_files[<?php echo $i; ?>][]" accept=".pdf,.jpg,.jpeg,.png,.gif" multiple>
                                        <small style="color: #666; display: block; margin-top: 5px;">📎 يمكنك اختيار أكثر من ملف (PDF, JPG, PNG, GIF)</small>
                                    </div>
                                    <button type="submit" class="btn-submit">💾 حفظ الملفات</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="no-session">
                        لا توجد جلسة مسجلة لهذا اليوم
                    </div>
                <?php endif; ?>
            </div>
        <?php endfor; ?>
    </div>
</body>
</html>

