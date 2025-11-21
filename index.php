<?php
require_once 'config.php';

// جلب المكاتب
$conn = getDBConnection();
$officesQuery = "SELECT * FROM offices ORDER BY name";
$officesResult = $conn->query($officesQuery);
$offices = $officesResult->fetch_all(MYSQLI_ASSOC);

// تحديد المكتب المختار
$selectedOfficeId = isset($_GET['office_id']) ? (int)$_GET['office_id'] : 0;
if ($selectedOfficeId <= 0 && !empty($offices)) {
    $selectedOfficeId = $offices[0]['id'];
}

// تحديد الأسبوع المختار أولاً (قبل جلب الأسابيع)
$selectedWeekId = isset($_GET['week_id']) ? (int)$_GET['week_id'] : 0;

// تحديد التاريخ المختار
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selectedDateObj = new DateTime($selectedDate);
$selectedMonth = (int)$selectedDateObj->format('n');
$selectedYear = (int)$selectedDateObj->format('Y');
$selectedWeekInMonth = isset($_GET['week_in_month']) ? (int)$_GET['week_in_month'] : 1; // 1-4

// جلب جميع الأسابيع للمكتب المختار
$allWeeks = [];
$weeksByMonth = []; // تجميع الأسابيع حسب الشهر
if ($selectedOfficeId > 0) {
    $weeksQuery = "SELECT * FROM weeks WHERE office_id = ? ORDER BY start_date DESC";
    $stmt = $conn->prepare($weeksQuery);
    $stmt->bind_param("i", $selectedOfficeId);
    $stmt->execute();
    $weeksResult = $stmt->get_result();
    $allWeeks = $weeksResult->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // تجميع الأسابيع حسب الشهر
    foreach ($allWeeks as $week) {
        $weekDate = new DateTime($week['start_date']);
        $weekMonth = (int)$weekDate->format('n');
        $weekYear = (int)$weekDate->format('Y');
        
        if (!isset($weeksByMonth[$weekYear])) {
            $weeksByMonth[$weekYear] = [];
        }
        if (!isset($weeksByMonth[$weekYear][$weekMonth])) {
            $weeksByMonth[$weekYear][$weekMonth] = [];
        }
        $weeksByMonth[$weekYear][$weekMonth][] = $week;
    }
    
    // البحث عن الأسبوع الذي يحتوي على التاريخ المحدد
    $foundWeek = null;
    foreach ($allWeeks as $week) {
        $weekStart = new DateTime($week['start_date']);
        $weekEnd = clone $weekStart;
        $weekEnd->modify('+6 days'); // الأسبوع 7 أيام
        
        // التحقق إذا كان التاريخ المحدد ضمن هذا الأسبوع
        if ($selectedDateObj >= $weekStart && $selectedDateObj <= $weekEnd) {
            $foundWeek = $week;
            $selectedWeekId = $week['id'];
            break;
        }
    }
    
    // إذا لم نجد أسبوع يحتوي على التاريخ، نبحث عن الأسبوع الأقرب
    if (!$foundWeek && !empty($allWeeks)) {
        // ترتيب الأسابيع حسب التاريخ
        usort($allWeeks, function($a, $b) {
            return strtotime($a['start_date']) - strtotime($b['start_date']);
        });
        
        // البحث عن الأسبوع الأقرب للتاريخ المحدد
        $closestWeek = null;
        $minDiff = PHP_INT_MAX;
        
        foreach ($allWeeks as $week) {
            $weekStart = new DateTime($week['start_date']);
            $diff = abs($selectedDateObj->getTimestamp() - $weekStart->getTimestamp());
            
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $closestWeek = $week;
            }
        }
        
        if ($closestWeek) {
            $selectedWeekId = $closestWeek['id'];
        } else {
            // إذا لم نجد أي أسبوع، نختار أول أسبوع
            $selectedWeekId = $allWeeks[0]['id'];
        }
    }
    
    // حساب رقم الأسبوع في الشهر للعرض
    if ($selectedWeekId > 0 && isset($weeksByMonth[$selectedYear][$selectedMonth])) {
        $monthWeeks = $weeksByMonth[$selectedYear][$selectedMonth];
        usort($monthWeeks, function($a, $b) {
            return strtotime($a['start_date']) - strtotime($b['start_date']);
        });
        
        foreach ($monthWeeks as $index => $w) {
            if ($w['id'] == $selectedWeekId) {
                $selectedWeekInMonth = $index + 1;
                break;
            }
        }
    }
}

// جلب بيانات الجدول لجميع المكاتب
$scheduleGrid = []; // مصفوفة للجدول: [office_id][date] = ['men' => [...], 'women' => [...]]
$officesWeeks = []; // مصفوفة لتخزين بيانات الأسبوع لكل مكتب: [office_id] = ['week' => ..., 'startDate' => ...]

// جلب بيانات جميع المكاتب
foreach ($offices as $office) {
    $officeId = $office['id'];
    
    // جلب جميع الأسابيع لهذا المكتب
    $weeksQuery = "SELECT * FROM weeks WHERE office_id = ? ORDER BY start_date DESC";
    $stmt = $conn->prepare($weeksQuery);
    $stmt->bind_param("i", $officeId);
    $stmt->execute();
    $weeksResult = $stmt->get_result();
    $officeWeeks = $weeksResult->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // البحث عن الأسبوع الذي يحتوي على التاريخ المحدد
    // الأسبوع يبدأ من الأحد
    $foundWeek = null;
    foreach ($officeWeeks as $week) {
        $weekStart = new DateTime($week['start_date']);
        // التأكد من أن الأسبوع يبدأ من الأحد
        $dayOfWeek = (int)$weekStart->format('w');
        if ($dayOfWeek != 0) {
            $weekStart->modify('-' . $dayOfWeek . ' days');
        }
        $weekEnd = clone $weekStart;
        $weekEnd->modify('+6 days'); // الأسبوع 7 أيام (الأحد إلى السبت)
        
        if ($selectedDateObj >= $weekStart && $selectedDateObj <= $weekEnd) {
            $foundWeek = $week;
            // تحديث start_date ليكون الأحد
            $foundWeek['start_date'] = $weekStart->format('Y-m-d');
            break;
        }
    }
    
    // إذا لم نجد أسبوع، نبحث عن الأقرب
    if (!$foundWeek && !empty($officeWeeks)) {
        $closestWeek = null;
        $minDiff = PHP_INT_MAX;
        
        foreach ($officeWeeks as $week) {
            $weekStart = new DateTime($week['start_date']);
            // التأكد من أن الأسبوع يبدأ من الأحد
            $dayOfWeek = (int)$weekStart->format('w');
            if ($dayOfWeek != 0) {
                $weekStart->modify('-' . $dayOfWeek . ' days');
            }
            
            // حساب الفرق بين التاريخ المحدد وبداية الأسبوع
            $diff = abs($selectedDateObj->getTimestamp() - $weekStart->getTimestamp());
            
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $closestWeek = $week;
                $closestWeek['start_date'] = $weekStart->format('Y-m-d');
            }
        }
        
        if ($closestWeek) {
            $foundWeek = $closestWeek;
        } else {
            $foundWeek = $officeWeeks[0];
            // التأكد من أن الأسبوع يبدأ من الأحد
            $weekStart = new DateTime($foundWeek['start_date']);
            $dayOfWeek = (int)$weekStart->format('w');
            if ($dayOfWeek != 0) {
                $weekStart->modify('-' . $dayOfWeek . ' days');
            }
            $foundWeek['start_date'] = $weekStart->format('Y-m-d');
        }
    }
    
    // إذا وجدنا أسبوع، نجلب بياناته
    if ($foundWeek) {
        $officesWeeks[$officeId] = [
            'week' => $foundWeek,
            'startDate' => $foundWeek['start_date']
        ];
        
        // جلب الجلسات للأسبوع
        $sessionsQuery = "SELECT * FROM sessions WHERE week_id = ? ORDER BY date ASC";
        $stmt = $conn->prepare($sessionsQuery);
        $stmt->bind_param("i", $foundWeek['id']);
        $stmt->execute();
        $sessionsResult = $stmt->get_result();
        $sessions = $sessionsResult->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // إنشاء مصفوفة الأيام (7 أيام) - من الأحد إلى السبت
        $days = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        $startDate = new DateTime($foundWeek['start_date']);
        
        // التأكد من أن الأسبوع يبدأ من الأحد
        $dayOfWeek = (int)$startDate->format('w'); // 0 = الأحد
        if ($dayOfWeek != 0) {
            $startDate->modify('-' . $dayOfWeek . ' days');
        }
        
        // تهيئة الجدول للمكتب
        $scheduleGrid[$officeId] = [];
        for ($i = 0; $i < 7; $i++) {
            $date = clone $startDate;
            $date->modify("+$i days");
            $dateStr = $date->format('Y-m-d');
            $scheduleGrid[$officeId][$dateStr] = [
                'day_name' => $days[$i],
                'date' => $dateStr,
                'men' => null,
                'women' => null
            ];
        }
        
        // ملء الجدول بالجلسات والملفات - كود محسّن واحترافي
        foreach ($sessions as $session) {
            $dateStr = isset($session['date']) ? $session['date'] : null;
            
            // التحقق من صحة البيانات
            if (empty($dateStr) || !isset($scheduleGrid[$officeId][$dateStr])) {
                continue;
            }
            
            $sessionId = isset($session['id']) ? (int)$session['id'] : 0;
            if ($sessionId <= 0) {
                continue;
            }
            
            // جلب جميع ملفات الرجال من جدول session_files لهذا التاريخ
            $menFiles = [];
            $menFilesQuery = "SELECT file_name, file_path, id FROM session_files WHERE session_id = ? AND file_type = 'men' ORDER BY id ASC";
            $fileStmt = $conn->prepare($menFilesQuery);
            if ($fileStmt) {
                $fileStmt->bind_param("i", $sessionId);
                if ($fileStmt->execute()) {
                    $menFilesResult = $fileStmt->get_result();
                    if ($menFilesResult) {
                        while ($fileRow = $menFilesResult->fetch_assoc()) {
                            if (!empty($fileRow['file_name']) && !empty($fileRow['file_path'])) {
                                $menFiles[] = [
                                    'filename' => trim($fileRow['file_name']),
                                    'path' => trim($fileRow['file_path']),
                                    'id' => isset($fileRow['id']) ? (int)$fileRow['id'] : 0
                                ];
                            }
                        }
                    }
                }
                $fileStmt->close();
            }
            
            // جلب جميع ملفات النساء من جدول session_files لهذا التاريخ
            $womenFiles = [];
            $womenFilesQuery = "SELECT file_name, file_path, id FROM session_files WHERE session_id = ? AND file_type = 'women' ORDER BY id ASC";
            $fileStmt = $conn->prepare($womenFilesQuery);
            if ($fileStmt) {
                $fileStmt->bind_param("i", $sessionId);
                if ($fileStmt->execute()) {
                    $womenFilesResult = $fileStmt->get_result();
                    if ($womenFilesResult) {
                        while ($fileRow = $womenFilesResult->fetch_assoc()) {
                            if (!empty($fileRow['file_name']) && !empty($fileRow['file_path'])) {
                                $womenFiles[] = [
                                    'filename' => trim($fileRow['file_name']),
                                    'path' => trim($fileRow['file_path']),
                                    'id' => isset($fileRow['id']) ? (int)$fileRow['id'] : 0
                                ];
                            }
                        }
                    }
                }
                $fileStmt->close();
            }
            
            // دعم البيانات القديمة - إذا لم توجد ملفات في الجدول الجديد، نستخدم الملف القديم
            if (empty($menFiles) && !empty($session['men_image'])) {
                $menImage = trim($session['men_image']);
                if (!empty($menImage)) {
                    $menFiles[] = [
                        'filename' => $menImage,
                        'path' => getImageUrl($menImage),
                        'id' => 0
                    ];
                }
            }
            
            if (empty($womenFiles) && !empty($session['women_image'])) {
                $womenImage = trim($session['women_image']);
                if (!empty($womenImage)) {
                    $womenFiles[] = [
                        'filename' => $womenImage,
                        'path' => getImageUrl($womenImage),
                        'id' => 0
                    ];
                }
            }
            
            // حفظ الملفات في الجدول مع التحقق من البيانات
            $scheduleGrid[$officeId][$dateStr]['men'] = [
                'files' => $menFiles,
                'enabled' => isset($session['men_enabled']) ? (bool)$session['men_enabled'] : true
            ];
            
            $scheduleGrid[$officeId][$dateStr]['women'] = [
                'files' => $womenFiles,
                'enabled' => isset($session['women_enabled']) ? (bool)$session['women_enabled'] : true
            ];
        }
    }
}

// تحديد الأسبوع الحالي للعرض (من أول مكتب)
$currentWeek = null;
if (!empty($officesWeeks)) {
    $firstOfficeWeek = reset($officesWeeks);
    $currentWeek = [
        'startDate' => $firstOfficeWeek['startDate']
    ];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>جدول الروضة - من 20 أبريل</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div style="text-align: center; margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; align-items: center;">
            <form method="GET" style="display: inline-block;">
                <input type="date" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>" 
                       onchange="this.form.submit()" 
                       style="padding: 10px 20px; font-size: 16px; border: 2px solid #1a4d7a; border-radius: 5px; background: white; cursor: pointer; font-family: Arial, sans-serif;">
            </form>
            
            <input type="text" id="officeSearch" placeholder="🔍 ابحث عن مكتب..." 
                   style="padding: 10px 20px; font-size: 16px; border: 2px solid #1a4d7a; border-radius: 5px; background: white; min-width: 250px;"
                   onkeyup="filterOffices()">
            
            <a href="admin.php" style="padding: 10px 20px; background: #4caf50; color: white; text-decoration: none; border-radius: 5px; font-size: 16px; margin-left: 10px;">➕ إضافة بيانات</a>
            <a href="add_office.php" style="padding: 10px 20px; background: #2196F3; color: white; text-decoration: none; border-radius: 5px; font-size: 16px;">🏢 إدارة المكاتب</a>
            <?php if (!empty($offices) && $currentWeek): ?>
            <a href="<?php echo buildUrl('export_pdf.php', ['date' => $selectedDate]); ?>" 
               style="padding: 10px 20px; background: #f44336; color: white; text-decoration: none; border-radius: 5px; font-size: 16px; margin-right: 10px;">📄 تحميل PDF</a>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($offices)): 
            // الأيام ثابتة تبدأ من الأحد
            $days = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
            
            // حساب بداية الأسبوع من التاريخ المحدد (الأحد)
            $headerStartDate = clone $selectedDateObj;
            $dayOfWeek = (int)$headerStartDate->format('w'); // 0 = الأحد, 6 = السبت
            // إذا كان اليوم ليس الأحد، نرجع للخلف حتى نصل للأحد
            if ($dayOfWeek != 0) {
                $headerStartDate->modify('-' . $dayOfWeek . ' days');
            }
            
            // حساب اسم اليوم للتاريخ المحدد
            $selectedDayName = $days[(int)$selectedDateObj->format('w')];
            $selectedDayNum = $selectedDateObj->format('d');
            $selectedMonthNum = (int)$selectedDateObj->format('n');
            $selectedYearNum = $selectedDateObj->format('Y');
        ?>
        <header class="header">
            <h1>جدول الروضة</h1>
            <h2><?php echo $selectedDayName . ' ' . $selectedDayNum . '/' . $selectedMonthNum . '/' . $selectedYearNum; ?> - الأسبوع من <?php echo date('d/m/Y', strtotime($headerStartDate->format('Y-m-d'))); ?></h2>
        </header>
        
            <div class="schedule-container" style="overflow-x: auto; background: white; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                <table style="width: 100%; border-collapse: collapse;" id="officesTable">
                    <thead>
                        <tr style="background: #f5f5f5;">
                            <th style="padding: 15px; text-align: right; border: 1px solid #ddd; background: #e8e8e8; font-weight: bold; min-width: 150px;">المكتب</th>
                            <?php 
                            for ($i = 0; $i < 7; $i++):
                                $date = clone $headerStartDate;
                                $date->modify("+$i days");
                                $dateStr = $date->format('Y-m-d');
                                
                                // الحصول على اسم اليوم الفعلي من التاريخ (رزنامة واقعية)
                                $actualDayOfWeek = (int)$date->format('w'); // 0 = الأحد, 6 = السبت
                                $dayName = $days[$actualDayOfWeek]; // استخدام اليوم الفعلي من التاريخ
                                
                                $dayNum = $date->format('d');
                                $monthNum = (int)$date->format('n');
                                $yearNum = $date->format('Y');
                                
                                // تحديد إذا كان هذا اليوم هو اليوم المحدد
                                $isSelectedDay = ($dateStr === $selectedDate);
                            ?>
                                <th style="padding: 15px; text-align: center; border: 1px solid #ddd; background: <?php echo $isSelectedDay ? '#c8e6c9' : '#e3f2fd'; ?>; font-weight: bold; min-width: 100px; color: #1a4d7a; <?php echo $isSelectedDay ? 'border: 3px solid #4caf50;' : ''; ?>">
                                    <?php echo htmlspecialchars($dayName); ?><br>
                                    <span style="font-size: 0.9em; color: #666; font-weight: normal;"><?php echo $dayNum . '/' . $monthNum . '/' . $yearNum; ?></span>
                                    <?php if ($isSelectedDay): ?>
                                        <br><span style="font-size: 0.8em; color: #2e7d32; font-weight: bold;">✓ محدد</span>
                                    <?php endif; ?>
                                </th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($offices as $office): 
                            $officeId = $office['id'];
                            $officeName = htmlspecialchars($office['name']);
                            $hasWeek = isset($scheduleGrid[$officeId]);
                        ?>
                            <tr class="office-row" data-office-name="<?php echo strtolower($officeName); ?>">
                                <td style="padding: 15px; text-align: right; border: 1px solid #ddd; background: <?php echo $hasWeek ? '#f9f9f9' : '#fff3cd'; ?>; font-weight: bold; color: #000; font-size: 18px;">
                                    <?php echo $officeName; ?>
                                    <?php if (!$hasWeek): ?>
                                        <br><span style="font-size: 12px; color: #856404; font-weight: normal;">(لا يوجد أسبوع)</span>
                                    <?php endif; ?>
                                </td>
                                <?php 
                                    // حساب بداية الأسبوع من التاريخ المحدد (الأحد) - نفس الحساب للجميع
                                    $officeWeekStartDate = clone $headerStartDate;
                                    
                                    for ($i = 0; $i < 7; $i++):
                                        $date = clone $officeWeekStartDate;
                                        $date->modify("+$i days");
                                        $dateStr = $date->format('Y-m-d');
                                        
                                        // جلب بيانات الخلية فقط إذا كان للمكتب أسبوع
                                        $cellData = ($hasWeek && isset($scheduleGrid[$officeId][$dateStr])) ? $scheduleGrid[$officeId][$dateStr] : null;
                                ?>
                                    <td style="padding: 15px; text-align: center; border: 1px solid #ddd; vertical-align: middle; min-height: 80px; <?php 
                                        if (!$hasWeek) {
                                            // تمييز المكاتب التي ليس لها أسابيع
                                            echo 'background: #fff3cd; opacity: 0.7;';
                                        } else {
                                            // تحديد إذا كان اليوم يحتوي على ملفات
                                            $menFiles = $cellData && isset($cellData['men']['files']) ? $cellData['men']['files'] : [];
                                            $womenFiles = $cellData && isset($cellData['women']['files']) ? $cellData['women']['files'] : [];
                                            $hasAnyFile = !empty($menFiles) || !empty($womenFiles);
                                            
                                            // إذا لم يكن هناك ملفات، جعل الخلفية أفتح قليلاً للتمييز
                                            if (!$hasAnyFile) {
                                                echo 'background: #fafafa;';
                                            }
                                        }
                                    ?>">
                                        <?php if (!$hasWeek): ?>
                                            <span style="color: #856404; font-size: 11px;">-</span>
                                        <?php else: 
                                            // جلب الملفات من البيانات
                                            $menFiles = $cellData && isset($cellData['men']['files']) ? $cellData['men']['files'] : [];
                                            $womenFiles = $cellData && isset($cellData['women']['files']) ? $cellData['women']['files'] : [];
                                            $hasAnyFile = !empty($menFiles) || !empty($womenFiles);
                                        ?>
                                            <?php if ($hasAnyFile): ?>
                                            <div style="display: flex; gap: 8px; justify-content: center; align-items: center; flex-wrap: wrap; padding: 12px; min-height: 60px;">
                                                <?php 
                                                // عرض جميع ملفات الرجال كأزرار منفصلة - تحسين الكود
                                                if (!empty($menFiles) && is_array($menFiles)): 
                                                    $menFileCount = count($menFiles);
                                                    foreach ($menFiles as $fileIndex => $file):
                                                        // التحقق من صحة بيانات الملف
                                                        if (empty($file) || !is_array($file) || empty($file['path']) || empty($file['filename'])) {
                                                            continue;
                                                        }
                                                        
                                                        $menFileUrl = trim($file['path']);
                                                        $fileExtension = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
                                                        $isMenPdf = ($fileExtension === 'pdf');
                                                        $fileName = htmlspecialchars(basename($file['filename']), ENT_QUOTES, 'UTF-8');
                                                        
                                                        // التأكد من أن URL صحيح
                                                        if (empty($menFileUrl)) {
                                                            continue;
                                                        }
                                                ?>
                                                    <button onclick="openPdf('<?php echo htmlspecialchars($menFileUrl, ENT_QUOTES, 'UTF-8'); ?>', <?php echo $isMenPdf ? 'true' : 'false'; ?>);" 
                                                            style="background: linear-gradient(135deg, #42A5F5 0%, #2196F3 50%, #1976D2 100%); color: white; border: 3px solid #0D47A1; padding: 10px 16px; border-radius: 8px; cursor: pointer; font-weight: 900; font-size: 16px; min-width: 50px; box-shadow: 0 4px 8px rgba(25,118,210,0.4); transition: all 0.3s; position: relative; display: inline-flex; align-items: center; justify-content: center; margin: 4px;"
                                                            onmouseover="this.style.transform='scale(1.08)'; this.style.boxShadow='0 6px 12px rgba(25,118,210,0.5)';"
                                                            onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 4px 8px rgba(25,118,210,0.4)';"
                                                            title="رجال - ملف <?php echo ($fileIndex + 1); ?>/<?php echo $menFileCount; ?>: <?php echo $fileName; ?>">
                                                        <span style="font-size: 18px; font-weight: 900; text-shadow: 0 2px 4px rgba(0,0,0,0.5);">ر</span>
                                                    </button>
                                                <?php 
                                                    endforeach; 
                                                endif; 
                                                ?>
                                                
                                                <?php 
                                                // عرض جميع ملفات النساء كأزرار منفصلة - تحسين الكود
                                                if (!empty($womenFiles) && is_array($womenFiles)): 
                                                    $womenFileCount = count($womenFiles);
                                                    foreach ($womenFiles as $fileIndex => $file):
                                                        // التحقق من صحة بيانات الملف
                                                        if (empty($file) || !is_array($file) || empty($file['path']) || empty($file['filename'])) {
                                                            continue;
                                                        }
                                                        
                                                        $womenFileUrl = trim($file['path']);
                                                        $fileExtension = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
                                                        $isWomenPdf = ($fileExtension === 'pdf');
                                                        $fileName = htmlspecialchars(basename($file['filename']), ENT_QUOTES, 'UTF-8');
                                                        
                                                        // التأكد من أن URL صحيح
                                                        if (empty($womenFileUrl)) {
                                                            continue;
                                                        }
                                                ?>
                                                    <button onclick="openPdf('<?php echo htmlspecialchars($womenFileUrl, ENT_QUOTES, 'UTF-8'); ?>', <?php echo $isWomenPdf ? 'true' : 'false'; ?>);" 
                                                            style="background: linear-gradient(135deg, #EC407A 0%, #E91E63 50%, #C2185B 100%); color: white; border: 3px solid #880E4F; padding: 10px 16px; border-radius: 8px; cursor: pointer; font-weight: 900; font-size: 16px; min-width: 50px; box-shadow: 0 4px 8px rgba(194,24,91,0.4); transition: all 0.3s; position: relative; display: inline-flex; align-items: center; justify-content: center; margin: 4px;"
                                                            onmouseover="this.style.transform='scale(1.08)'; this.style.boxShadow='0 6px 12px rgba(194,24,91,0.5)';"
                                                            onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 4px 8px rgba(194,24,91,0.4)';"
                                                            title="نساء - ملف <?php echo ($fileIndex + 1); ?>/<?php echo $womenFileCount; ?>: <?php echo $fileName; ?>">
                                                        <span style="font-size: 18px; font-weight: 900; text-shadow: 0 2px 4px rgba(0,0,0,0.5);">ن</span>
                                                    </button>
                                                <?php 
                                                    endforeach; 
                                                endif; 
                                                ?>
                                            </div>
                                            <?php else: ?>
                                            <span style="color: #ccc; font-size: 12px;">-</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endfor; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-data" style="text-align: center; padding: 40px; background: white; border-radius: 10px; margin-top: 20px;">
                <p style="font-size: 18px; color: #666; margin-bottom: 20px;">
                    لا توجد بيانات متاحة للعرض
                </p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal للصور -->
    <div id="imageModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <div class="modal-header">
                <h3 id="modalTitle"></h3>
            </div>
            <div class="modal-body">
                <img id="modalImage" src="" alt="صورة الجلسة">
            </div>
        </div>
    </div>

    <script>
        function openPdf(fileUrl, isPdf) {
            if (isPdf) {
                // PDF: فتح كملف (تحميل مباشر أو فتح في نافذة جديدة)
                const link = document.createElement('a');
                link.href = fileUrl;
                link.target = '_blank';
                link.download = '';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            } else {
                // صورة: عرض في modal
                const modal = document.getElementById('imageModal');
                const modalImage = document.getElementById('modalImage');
                const modalTitle = document.getElementById('modalTitle');
                
                modalTitle.textContent = 'عرض الصورة';
                modalImage.src = fileUrl;
                modalImage.style.display = 'block';
                modal.style.display = 'block';
            }
        }
        
        function filterOffices() {
            const searchInput = document.getElementById('officeSearch');
            const searchTerm = searchInput.value.toLowerCase();
            const rows = document.querySelectorAll('.office-row');
            
            rows.forEach(function(row) {
                const officeName = row.getAttribute('data-office-name');
                if (officeName.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        function openModal(imageUrl, gender, trainer, time, date) {
            const modal = document.getElementById('imageModal');
            const modalImage = document.getElementById('modalImage');
            const modalTitle = document.getElementById('modalTitle');
            
            modalTitle.textContent = gender + ' - ' + trainer + ' - ' + time + ' - ' + date;
            modalImage.src = imageUrl;
            modal.style.display = 'block';
        }
        
        const closeBtn = document.querySelector('.close');
        if (closeBtn) {
            closeBtn.onclick = function() {
                document.getElementById('imageModal').style.display = 'none';
            }
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('imageModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>

