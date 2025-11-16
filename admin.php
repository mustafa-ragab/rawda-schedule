<?php
require_once 'config.php';

$message = '';
$messageType = '';

// جلب المكاتب
$conn = getDBConnection();
$officesQuery = "SELECT * FROM offices ORDER BY name";
$officesResult = $conn->query($officesQuery);
$offices = $officesResult->fetch_all(MYSQLI_ASSOC);

// معالجة إضافة أسبوع جديد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_week') {
        $officeId = (int)$_POST['office_id'];
        $startDate = $_POST['start_date'];
        $month = (int)$_POST['month'];
        $year = (int)$_POST['year'];
        
        if ($officeId <= 0) {
            $message = 'يجب اختيار مكتب';
            $messageType = 'error';
        } elseif (empty($startDate)) {
            $message = 'يجب تحديد تاريخ بداية الأسبوع';
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
            
            // إدراج الأسبوع باستخدام تاريخ الأحد
            $insertWeek = "INSERT INTO weeks (office_id, week_number, start_date) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($insertWeek);
            $stmt->bind_param("iis", $officeId, $newWeekNumber, $actualStartDate);
            $stmt->execute();
            $weekId = $conn->insert_id;
            $stmt->close();
            
            for ($i = 0; $i < 7; $i++) {
                $date = clone $startDateObj;
                $date->modify("+$i days");
                $dateStr = $date->format('Y-m-d');
                $dayName = $days[$i];
                
                // بيانات الرجال
                $menTime = !empty($_POST['men_time'][$i]) ? trim($_POST['men_time'][$i]) : '';
                $menTrainer = ''; // تم إزالة حقل المدرب
                $menEnabled = isset($_POST['men_enabled'][$i]) ? 1 : 0;
                
                // بيانات النساء
                $womenTime = !empty($_POST['women_time'][$i]) ? trim($_POST['women_time'][$i]) : '';
                $womenTrainer = ''; // تم إزالة حقل المدرب
                $womenEnabled = isset($_POST['women_enabled'][$i]) ? 1 : 0;
                
                // رفع ملفات الرجال
                $menImage = '';
                if (isset($_FILES['men_image']['name'][$i]) && !empty($_FILES['men_image']['name'][$i])) {
                    if ($_FILES['men_image']['error'][$i] === UPLOAD_ERR_OK) {
                        $fileArray = [
                            'name' => $_FILES['men_image']['name'][$i],
                            'type' => $_FILES['men_image']['type'][$i],
                            'tmp_name' => $_FILES['men_image']['tmp_name'][$i],
                            'error' => $_FILES['men_image']['error'][$i],
                            'size' => $_FILES['men_image']['size'][$i]
                        ];
                        $uploadResult = uploadImage($fileArray, $weekId . '_men_' . $i);
                        if ($uploadResult['success']) {
                            $menImage = $uploadResult['filename'];
                        } else {
                            error_log("Error uploading men image for day $i: " . ($uploadResult['message'] ?? 'Unknown error'));
                        }
                    } else {
                        error_log("Upload error for men_image[$i]: " . $_FILES['men_image']['error'][$i]);
                    }
                }
                
                // رفع ملفات النساء
                $womenImage = '';
                if (isset($_FILES['women_image']['name'][$i]) && !empty($_FILES['women_image']['name'][$i])) {
                    if ($_FILES['women_image']['error'][$i] === UPLOAD_ERR_OK) {
                        $fileArray = [
                            'name' => $_FILES['women_image']['name'][$i],
                            'type' => $_FILES['women_image']['type'][$i],
                            'tmp_name' => $_FILES['women_image']['tmp_name'][$i],
                            'error' => $_FILES['women_image']['error'][$i],
                            'size' => $_FILES['women_image']['size'][$i]
                        ];
                        $uploadResult = uploadImage($fileArray, $weekId . '_women_' . $i);
                        if ($uploadResult['success']) {
                            $womenImage = $uploadResult['filename'];
                        } else {
                            error_log("Error uploading women image for day $i: " . ($uploadResult['message'] ?? 'Unknown error'));
                        }
                    } else {
                        error_log("Upload error for women_image[$i]: " . $_FILES['women_image']['error'][$i]);
                    }
                }
                
                // تحديد نوع الجلسة
                $hasMenData = !empty($menTime) || !empty($menImage);
                $hasWomenData = !empty($womenTime) || !empty($womenImage);
                
                $sessionType = 'both';
                if ($hasMenData && !$hasWomenData) {
                    $sessionType = 'men_only';
                } elseif (!$hasMenData && $hasWomenData) {
                    $sessionType = 'women_only';
                }
                
                // إدراج الجلسة - نحفظ دائماً حتى لو كانت فارغة
                $insertSession = "INSERT INTO sessions (week_id, day_name, date, session_type, men_time, men_trainer, men_image, men_enabled, women_time, women_trainer, women_image, women_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($insertSession);
                $stmt->bind_param("issssssisssi", 
                    $weekId,
                    $dayName,
                    $dateStr,
                    $sessionType,
                    $menTime,
                    $menTrainer,
                    $menImage,
                    $menEnabled,
                    $womenTime,
                    $womenTrainer,
                    $womenImage,
                    $womenEnabled
                );
                
                if (!$stmt->execute()) {
                    error_log("Error inserting session: " . $stmt->error);
                    $message = 'حدث خطأ أثناء حفظ البيانات: ' . $stmt->error;
                    $messageType = 'error';
                } else {
                    // تسجيل نجاح الحفظ
                    error_log("Session saved for date: $dateStr, men_image: $menImage, women_image: $womenImage");
                }
                $stmt->close();
            }
            
            if ($messageType !== 'error') {
                $message = 'تم إضافة الأسبوع بنجاح!';
                $messageType = 'success';
            }
        }
    }
}

$conn->close();
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
                </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_week">
                
                <div class="form-group">
                    <label>المكتب:</label>
                    <select name="office_id" required>
                        <option value="">اختر المكتب</option>
                        <?php foreach ($offices as $office): ?>
                            <option value="<?php echo $office['id']; ?>"><?php echo htmlspecialchars($office['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>تاريخ بداية الأسبوع:</label>
                    <input type="date" name="start_date" required>
                </div>
                
                <div class="form-group">
                    <label>الشهر:</label>
                    <select name="month" required>
                        <?php
                        $months = [
                            1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
                            5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
                            9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
                        ];
                        foreach ($months as $num => $name):
                        ?>
                            <option value="<?php echo $num; ?>" <?php echo ($num == (int)date('n')) ? 'selected' : ''; ?>>
                                <?php echo $name; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>السنة:</label>
                    <input type="number" name="year" value="<?php echo date('Y'); ?>" required>
                </div>
                
                <h2 style="color: #1a4d7a; margin-top: 30px; margin-bottom: 20px;">بيانات الأيام (7 أيام)</h2>
                
                <?php 
                // ترتيب الأيام من الأحد إلى السبت (ثابت)
                $days = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
                for ($i = 0; $i < 7; $i++):
                ?>
                    <div class="day-row">
                        <h4><?php echo $days[$i]; ?></h4>
                        
                        <div class="session-group">
                            <div class="session-box men">
                                <h5 style="color: #4a9eff; margin-top: 0;">رجال 👨</h5>
                                <div class="form-group">
                                    <label>الوقت:</label>
                                    <input type="time" name="men_time[<?php echo $i; ?>]">
                                </div>
                                <div class="form-group">
                                    <label>ملف PDF أو صورة:</label>
                                    <input type="file" name="men_image[<?php echo $i; ?>]" accept=".pdf,.jpg,.jpeg,.png,.gif">
                                </div>
                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" name="men_enabled[<?php echo $i; ?>]" checked>
                                        مفعّل
                                    </label>
                                </div>
                            </div>
                            
                            <div class="session-box women">
                                <h5 style="color: #ff4444; margin-top: 0;">نساء 👩</h5>
                                <div class="form-group">
                                    <label>الوقت:</label>
                                    <input type="time" name="women_time[<?php echo $i; ?>]">
                                </div>
                                <div class="form-group">
                                    <label>ملف PDF أو صورة:</label>
                                    <input type="file" name="women_image[<?php echo $i; ?>]" accept=".pdf,.jpg,.jpeg,.png,.gif">
                                </div>
                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" name="women_enabled[<?php echo $i; ?>]" checked>
                                        مفعّل
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endfor; ?>
                
                <button type="submit" class="btn-submit">حفظ الأسبوع</button>
            </form>
        </div>
    </div>
</body>
</html>

