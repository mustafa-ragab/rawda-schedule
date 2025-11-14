<?php
require_once 'config.php';

$conn = getDBConnection();

$message = '';
$messageType = '';

// معالجة إصلاح البيانات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'fix_weeks') {
        // جلب جميع الأسابيع
        $weeksQuery = "SELECT * FROM weeks ORDER BY id";
        $weeksResult = $conn->query($weeksQuery);
        $allWeeks = $weeksResult->fetch_all(MYSQLI_ASSOC);
        
        $fixed = 0;
        $errors = 0;
        
        foreach ($allWeeks as $week) {
            $startDate = new DateTime($week['start_date']);
            
            // حساب يوم الأسبوع والبدء من السبت
            $dayOfWeek = (int)$startDate->format('w'); // 0 = الأحد
            $dayOfWeek = ($dayOfWeek == 0) ? 1 : ($dayOfWeek == 6 ? 0 : $dayOfWeek + 1);
            
            // حساب تاريخ السبت (بداية الأسبوع الفعلية)
            $saturdayDate = clone $startDate;
            $saturdayDate->modify('-' . $dayOfWeek . ' days');
            $actualStartDate = $saturdayDate->format('Y-m-d');
            
            // التحقق من أن التاريخ المحفوظ يختلف عن تاريخ السبت
            if ($week['start_date'] !== $actualStartDate) {
                // تحديث تاريخ البداية إلى تاريخ السبت
                $updateQuery = "UPDATE weeks SET start_date = ? WHERE id = ?";
                $stmt = $conn->prepare($updateQuery);
                $stmt->bind_param("si", $actualStartDate, $week['id']);
                
                if ($stmt->execute()) {
                    $fixed++;
                    error_log("Fixed week {$week['id']}: {$week['start_date']} -> $actualStartDate");
                } else {
                    $errors++;
                    error_log("Error fixing week {$week['id']}: " . $stmt->error);
                }
                $stmt->close();
            }
        }
        
        $message = "تم إصلاح $fixed أسبوع. ";
        if ($errors > 0) {
            $message .= "حدثت $errors أخطاء.";
            $messageType = 'warning';
        } else {
            $messageType = 'success';
        }
    }
    
    if ($_POST['action'] === 'fix_sessions') {
        // جلب جميع الأسابيع التي لا تحتوي على 7 جلسات
        $weeksQuery = "SELECT w.id, w.start_date, COUNT(s.id) as session_count 
                       FROM weeks w 
                       LEFT JOIN sessions s ON s.week_id = w.id 
                       GROUP BY w.id 
                       HAVING session_count < 7";
        $weeksResult = $conn->query($weeksQuery);
        $weeksToFix = $weeksResult->fetch_all(MYSQLI_ASSOC);
        
        $fixed = 0;
        $errors = 0;
        
        foreach ($weeksToFix as $weekData) {
            $weekId = $weekData['id'];
            $startDate = new DateTime($weekData['start_date']);
            
            // حساب يوم الأسبوع والبدء من السبت
            $dayOfWeek = (int)$startDate->format('w');
            $dayOfWeek = ($dayOfWeek == 0) ? 1 : ($dayOfWeek == 6 ? 0 : $dayOfWeek + 1);
            $startDate->modify('-' . $dayOfWeek . ' days');
            
            $days = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];
            
            // جلب الجلسات الموجودة
            $existingSessionsQuery = "SELECT date FROM sessions WHERE week_id = ?";
            $stmt = $conn->prepare($existingSessionsQuery);
            $stmt->bind_param("i", $weekId);
            $stmt->execute();
            $existingResult = $stmt->get_result();
            $existingDates = [];
            while ($row = $existingResult->fetch_assoc()) {
                $existingDates[] = $row['date'];
            }
            $stmt->close();
            
            // إضافة الجلسات المفقودة
            for ($i = 0; $i < 7; $i++) {
                $date = clone $startDate;
                $date->modify("+$i days");
                $dateStr = $date->format('Y-m-d');
                $dayName = $days[$i];
                
                // إذا لم تكن الجلسة موجودة، أضفها
                if (!in_array($dateStr, $existingDates)) {
                    $insertSession = "INSERT INTO sessions (week_id, day_name, date, session_type, men_time, men_trainer, men_image, men_enabled, women_time, women_trainer, women_image, women_enabled) VALUES (?, ?, ?, 'both', '', '', '', 1, '', '', '', 1)";
                    $stmt = $conn->prepare($insertSession);
                    $stmt->bind_param("iss", $weekId, $dayName, $dateStr);
                    
                    if ($stmt->execute()) {
                        $fixed++;
                    } else {
                        $errors++;
                        error_log("Error adding session for week $weekId, date $dateStr: " . $stmt->error);
                    }
                    $stmt->close();
                }
            }
        }
        
        $message = "تم إضافة $fixed جلسة مفقودة. ";
        if ($errors > 0) {
            $message .= "حدثت $errors أخطاء.";
            $messageType = 'warning';
        } else {
            $messageType = 'success';
        }
    }
}

// جلب البيانات للعرض
$weeksQuery = "SELECT * FROM weeks ORDER BY start_date DESC";
$weeksResult = $conn->query($weeksQuery);
$allWeeks = $weeksResult->fetch_all(MYSQLI_ASSOC);

$sessionsQuery = "SELECT week_id, COUNT(*) as count FROM sessions GROUP BY week_id";
$sessionsResult = $conn->query($sessionsQuery);
$sessionCounts = [];
while ($row = $sessionsResult->fetch_assoc()) {
    $sessionCounts[$row['week_id']] = $row['count'];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إصلاح البيانات - جدول الروضة</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            direction: rtl;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1a4d7a;
            text-align: center;
        }
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            text-align: center;
        }
        .message.success {
            background: #4caf50;
            color: white;
        }
        .message.warning {
            background: #ff9800;
            color: white;
        }
        .message.error {
            background: #f44336;
            color: white;
        }
        .fix-section {
            margin-bottom: 30px;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 5px;
            border: 2px solid #ddd;
        }
        .fix-section h2 {
            color: #1a4d7a;
            margin-top: 0;
        }
        .btn-fix {
            background: #4caf50;
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 10px;
        }
        .btn-fix:hover {
            background: #45a049;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            padding: 10px 20px;
            background: #1a4d7a;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }
        .back-link:hover {
            background: #0d3a5f;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: right;
        }
        th {
            background: #1a4d7a;
            color: white;
        }
        .error {
            color: red;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link">← العودة للجدول</a>
        <a href="check_data.php" class="back-link" style="margin-right: 10px;">🔍 التحقق من البيانات</a>
        
        <h1>إصلاح البيانات</h1>
        
        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- إصلاح تواريخ الأسابيع -->
        <div class="fix-section">
            <h2>1. إصلاح تواريخ الأسابيع</h2>
            <p>هذا الإصلاح سيحدث جميع تواريخ بداية الأسابيع لتكون تاريخ السبت (بداية الأسبوع الفعلية).</p>
            <p><strong>ملاحظة:</strong> سيتم تحديث جميع الأسابيع التي لا تبدأ بيوم السبت.</p>
            <form method="POST" onsubmit="return confirm('هل أنت متأكد من إصلاح جميع تواريخ الأسابيع؟');">
                <input type="hidden" name="action" value="fix_weeks">
                <button type="submit" class="btn-fix">إصلاح تواريخ الأسابيع</button>
            </form>
        </div>
        
        <!-- إصلاح الجلسات المفقودة -->
        <div class="fix-section">
            <h2>2. إصلاح الجلسات المفقودة</h2>
            <p>هذا الإصلاح سيضيف الجلسات المفقودة للأسابيع التي لا تحتوي على 7 جلسات.</p>
            <p><strong>ملاحظة:</strong> سيتم إضافة جلسات فارغة للأيام المفقودة.</p>
            <form method="POST" onsubmit="return confirm('هل أنت متأكد من إضافة الجلسات المفقودة؟');">
                <input type="hidden" name="action" value="fix_sessions">
                <button type="submit" class="btn-fix">إصلاح الجلسات المفقودة</button>
            </form>
        </div>
        
        <!-- قائمة المشاكل -->
        <div class="fix-section">
            <h2>المشاكل الموجودة</h2>
            <table>
                <thead>
                    <tr>
                        <th>نوع المشكلة</th>
                        <th>العدد</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $weeksWithWrongDate = 0;
                    $weeksWithMissingSessions = 0;
                    
                    foreach ($allWeeks as $week) {
                        $startDate = new DateTime($week['start_date']);
                        $dayOfWeek = (int)$startDate->format('w');
                        $dayOfWeek = ($dayOfWeek == 0) ? 1 : ($dayOfWeek == 6 ? 0 : $dayOfWeek + 1);
                        $saturdayDate = clone $startDate;
                        $saturdayDate->modify('-' . $dayOfWeek . ' days');
                        
                        if ($week['start_date'] !== $saturdayDate->format('Y-m-d')) {
                            $weeksWithWrongDate++;
                        }
                        
                        $sessionCount = isset($sessionCounts[$week['id']]) ? $sessionCounts[$week['id']] : 0;
                        if ($sessionCount < 7) {
                            $weeksWithMissingSessions++;
                        }
                    }
                    ?>
                    <tr>
                        <td>أسابيع بتواريخ خاطئة (لا تبدأ بالسبت)</td>
                        <td class="<?php echo $weeksWithWrongDate > 0 ? 'error' : ''; ?>">
                            <?php echo $weeksWithWrongDate; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>أسابيع بجلسات مفقودة (أقل من 7)</td>
                        <td class="<?php echo $weeksWithMissingSessions > 0 ? 'error' : ''; ?>">
                            <?php echo $weeksWithMissingSessions; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>

