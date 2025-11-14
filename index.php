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

// تحديد الشهر والسنة المختارين
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
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
    
    // تحديد الأسبوع المختار بناءً على الشهر والأسبوع في الشهر
    if ($selectedMonth > 0 && $selectedYear > 0 && $selectedWeekInMonth > 0) {
        if (isset($weeksByMonth[$selectedYear][$selectedMonth]) && !empty($weeksByMonth[$selectedYear][$selectedMonth])) {
            $monthWeeks = $weeksByMonth[$selectedYear][$selectedMonth];
            // ترتيب الأسابيع حسب التاريخ
            usort($monthWeeks, function($a, $b) {
                return strtotime($a['start_date']) - strtotime($b['start_date']);
            });
            
            if (isset($monthWeeks[$selectedWeekInMonth - 1])) {
                $selectedWeekId = $monthWeeks[$selectedWeekInMonth - 1]['id'];
            } elseif (!empty($monthWeeks)) {
                $selectedWeekId = $monthWeeks[0]['id'];
                $selectedWeekInMonth = 1;
            }
        } else {
            // لا توجد أسابيع في الشهر المختار - لا نختار أي أسبوع
            $selectedWeekId = 0;
        }
    }
    
    // إذا لم يكن هناك أسبوع محدد وكان الشهر والسنة محددين، لا نختار أي أسبوع
    // فقط إذا لم يكن هناك شهر محدد، نختار أول أسبوع
    if ($selectedWeekId <= 0 && ($selectedMonth <= 0 || $selectedYear <= 0) && !empty($allWeeks)) {
        $selectedWeekId = $allWeeks[0]['id'];
    }
}

// جلب بيانات الجدول من قاعدة البيانات للمكتب والأسبوع المختار
$currentWeek = null;
$scheduleGrid = []; // مصفوفة للجدول: [office_id][date] = ['men' => [...], 'women' => [...]]

if ($selectedOfficeId > 0 && $selectedWeekId > 0) {
    $weekQuery = "SELECT * FROM weeks WHERE id = ? AND office_id = ?";
    $stmt = $conn->prepare($weekQuery);
    $stmt->bind_param("ii", $selectedWeekId, $selectedOfficeId);
    $stmt->execute();
    $weekResult = $stmt->get_result();
    $week = $weekResult->fetch_assoc();
    $stmt->close();
    
    if ($week) {
        $sessionsQuery = "SELECT * FROM sessions WHERE week_id = ? ORDER BY date ASC";
        $stmt = $conn->prepare($sessionsQuery);
        $stmt->bind_param("i", $week['id']);
        $stmt->execute();
        $sessionsResult = $stmt->get_result();
        $sessions = $sessionsResult->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // حساب رقم الأسبوع في الشهر
        $weekDate = new DateTime($week['start_date']);
        $weekMonth = (int)$weekDate->format('n');
        $weekYear = (int)$weekDate->format('Y');
        $weekInMonth = 1;
        if (isset($weeksByMonth[$weekYear][$weekMonth])) {
            $monthWeeks = $weeksByMonth[$weekYear][$weekMonth];
            usort($monthWeeks, function($a, $b) {
                return strtotime($a['start_date']) - strtotime($b['start_date']);
            });
            foreach ($monthWeeks as $index => $w) {
                if ($w['id'] == $week['id']) {
                    $weekInMonth = $index + 1;
                    break;
                }
            }
        }
        
        $currentWeek = [
            'weekNumber' => $weekInMonth, // رقم الأسبوع في الشهر
            'weekNumberGlobal' => $week['week_number'], // رقم الأسبوع العام (للرجوع إليه إذا لزم)
            'startDate' => $week['start_date'],
            'sessions' => []
        ];
        
        // إنشاء مصفوفة الأيام (7 أيام) - من السبت إلى الجمعة
        $days = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];
        $startDate = new DateTime($week['start_date']);
        
        // حساب يوم الأسبوع (0 = الأحد، 6 = السبت)
        $dayOfWeek = (int)$startDate->format('w'); // 0 = الأحد
        // تحويل إلى: 0 = السبت، 1 = الأحد، ... 6 = الجمعة
        $dayOfWeek = ($dayOfWeek == 0) ? 1 : ($dayOfWeek == 6 ? 0 : $dayOfWeek + 1);
        
        // البدء من السبت (نرجع للخلف إذا لزم)
        $startDate->modify('-' . $dayOfWeek . ' days');
        
        // تهيئة الجدول للمكتب المختار
        $scheduleGrid[$selectedOfficeId] = [];
        for ($i = 0; $i < 7; $i++) {
            $date = clone $startDate;
            $date->modify("+$i days");
            $dateStr = $date->format('Y-m-d');
            $scheduleGrid[$selectedOfficeId][$dateStr] = [
                'day_name' => $days[$i],
                'date' => $dateStr,
                'men' => null,
                'women' => null
            ];
        }
        
        // ملء الجدول بالجلسات
        foreach ($sessions as $session) {
            $dateStr = $session['date'];
            if (isset($scheduleGrid[$selectedOfficeId][$dateStr])) {
                // إضافة بيانات الرجال دائماً (حتى لو كانت فارغة)
                $scheduleGrid[$selectedOfficeId][$dateStr]['men'] = [
                    'time' => $session['men_time'] ?? '',
                    'trainer' => $session['men_trainer'] ?? '',
                    'image' => $session['men_image'] ?? '',
                    'enabled' => (bool)($session['men_enabled'] ?? true)
                ];
                
                // إضافة بيانات النساء دائماً (حتى لو كانت فارغة)
                $scheduleGrid[$selectedOfficeId][$dateStr]['women'] = [
                    'time' => $session['women_time'] ?? '',
                    'trainer' => $session['women_trainer'] ?? '',
                    'image' => $session['women_image'] ?? '',
                    'enabled' => (bool)($session['women_enabled'] ?? true)
                ];
            }
        }
        
        // Debug: طباعة البيانات للتحقق (يمكن إزالتها لاحقاً)
        if (empty($sessions)) {
            error_log("No sessions found for week_id: " . $week['id']);
        } else {
            error_log("Found " . count($sessions) . " sessions");
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
    <title>جدول الروضة - من 20 أبريل</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div style="text-align: center; margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; align-items: center;">
            <?php if (!empty($offices)): ?>
            <form method="GET" style="display: inline-block;">
                <input type="hidden" name="month" value="<?php echo $selectedMonth; ?>">
                <input type="hidden" name="year" value="<?php echo $selectedYear; ?>">
                <input type="hidden" name="week_in_month" value="<?php echo $selectedWeekInMonth; ?>">
                <select name="office_id" onchange="this.form.submit()" style="padding: 10px 20px; font-size: 16px; border: 2px solid #1a4d7a; border-radius: 5px; background: white; cursor: pointer;">
                    <?php foreach ($offices as $office): ?>
                        <option value="<?php echo $office['id']; ?>" <?php echo ($selectedOfficeId == $office['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($office['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
            
            <form method="GET" style="display: inline-block;">
                <input type="hidden" name="office_id" value="<?php echo $selectedOfficeId; ?>">
                <input type="hidden" name="week_in_month" value="<?php echo $selectedWeekInMonth; ?>">
                <select name="month" onchange="this.form.submit()" style="padding: 10px 20px; font-size: 16px; border: 2px solid #1a4d7a; border-radius: 5px; background: white; cursor: pointer;">
                    <?php
                    $months = [
                        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
                        5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
                        9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
                    ];
                    foreach ($months as $num => $name):
                    ?>
                        <option value="<?php echo $num; ?>" <?php echo ($num == $selectedMonth) ? 'selected' : ''; ?>>
                            <?php echo $name; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            
            <form method="GET" style="display: inline-block;">
                <input type="hidden" name="office_id" value="<?php echo $selectedOfficeId; ?>">
                <input type="hidden" name="month" value="<?php echo $selectedMonth; ?>">
                <select name="year" onchange="this.form.submit()" style="padding: 10px 20px; font-size: 16px; border: 2px solid #1a4d7a; border-radius: 5px; background: white; cursor: pointer;">
                    <?php
                    $currentYear = (int)date('Y');
                    for ($y = $currentYear - 1; $y <= $currentYear + 1; $y++):
                    ?>
                        <option value="<?php echo $y; ?>" <?php echo ($y == $selectedYear) ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </form>
            
            <?php if (isset($weeksByMonth[$selectedYear][$selectedMonth]) && !empty($weeksByMonth[$selectedYear][$selectedMonth])): 
                $monthWeeks = $weeksByMonth[$selectedYear][$selectedMonth];
                usort($monthWeeks, function($a, $b) {
                    return strtotime($a['start_date']) - strtotime($b['start_date']);
                });
            ?>
            <form method="GET" style="display: inline-block;">
                <input type="hidden" name="office_id" value="<?php echo $selectedOfficeId; ?>">
                <input type="hidden" name="month" value="<?php echo $selectedMonth; ?>">
                <input type="hidden" name="year" value="<?php echo $selectedYear; ?>">
                <select name="week_in_month" onchange="this.form.submit()" style="padding: 10px 20px; font-size: 16px; border: 2px solid #1a4d7a; border-radius: 5px; background: white; cursor: pointer;">
                    <?php for ($w = 1; $w <= min(4, count($monthWeeks)); $w++): ?>
                        <option value="<?php echo $w; ?>" <?php echo ($w == $selectedWeekInMonth) ? 'selected' : ''; ?>>
                            الأسبوع <?php echo $w; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </form>
            <?php endif; ?>
            
            <a href="admin.php" style="padding: 10px 20px; background: #4caf50; color: white; text-decoration: none; border-radius: 5px; font-size: 16px; margin-left: 10px;">➕ إضافة بيانات</a>
            <a href="add_office.php" style="padding: 10px 20px; background: #2196F3; color: white; text-decoration: none; border-radius: 5px; font-size: 16px;">🏢 إدارة المكاتب</a>
            <?php if ($currentWeek && $selectedOfficeId > 0): ?>
            <a href="export_pdf.php?office_id=<?php echo $selectedOfficeId; ?>&week_id=<?php echo $selectedWeekId; ?>" 
               style="padding: 10px 20px; background: #f44336; color: white; text-decoration: none; border-radius: 5px; font-size: 16px; margin-right: 10px;">📄 تحميل PDF</a>
            <?php endif; ?>
        </div>
        
        <header class="header">
            <h1>جدول الروضة</h1>
            <?php if ($currentWeek): ?>
                <h2>أسبوع <?php echo $currentWeek['weekNumber']; ?> - من <?php echo date('d/m/Y', strtotime($currentWeek['startDate'])); ?></h2>
            <?php endif; ?>
        </header>

        <?php if ($currentWeek && isset($scheduleGrid[$selectedOfficeId])): 
            $officeName = '';
            foreach ($offices as $office) {
                if ($office['id'] == $selectedOfficeId) {
                    $officeName = $office['name'];
                    break;
                }
            }
            $monthNames = ['', 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
        ?>
            <div class="schedule-container" style="overflow-x: auto; background: white; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f5f5f5;">
                            <th style="padding: 15px; text-align: right; border: 1px solid #ddd; background: #e8e8e8; font-weight: bold; min-width: 120px;">المكتب</th>
                            <?php 
                            $days = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];
                            $headerStartDate = new DateTime($currentWeek['startDate']);
                            
                            // حساب يوم الأسبوع والبدء من السبت
                            $dayOfWeek = (int)$headerStartDate->format('w'); // 0 = الأحد
                            $dayOfWeek = ($dayOfWeek == 0) ? 1 : ($dayOfWeek == 6 ? 0 : $dayOfWeek + 1);
                            $headerStartDate->modify('-' . $dayOfWeek . ' days');
                            
                            for ($i = 0; $i < 7; $i++):
                                $date = clone $headerStartDate;
                                $date->modify("+$i days");
                                $dateStr = $date->format('Y-m-d');
                                $dayName = $days[$i];
                                $dayNum = $date->format('d');
                                $monthNum = (int)$date->format('n');
                            ?>
                                <th style="padding: 15px; text-align: center; border: 1px solid #ddd; background: #e3f2fd; font-weight: bold; min-width: 100px; color: #1a4d7a;">
                                    <?php echo htmlspecialchars($dayName); ?><br>
                                    <span style="font-size: 0.9em; color: #666; font-weight: normal;"><?php echo $dayNum . '-' . $monthNum; ?></span>
                                </th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="padding: 15px; text-align: right; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold; color: #000; font-size: 18px;">
                                <?php echo htmlspecialchars($officeName); ?>
                            </td>
                            <?php 
                            // حساب يوم الأسبوع والبدء من السبت
                            $displayStartDate = new DateTime($currentWeek['startDate']);
                            $dayOfWeek = (int)$displayStartDate->format('w'); // 0 = الأحد
                            $dayOfWeek = ($dayOfWeek == 0) ? 1 : ($dayOfWeek == 6 ? 0 : $dayOfWeek + 1);
                            $displayStartDate->modify('-' . $dayOfWeek . ' days');
                            
                            for ($i = 0; $i < 7; $i++):
                                $date = clone $displayStartDate;
                                $date->modify("+$i days");
                                $dateStr = $date->format('Y-m-d');
                                $cellData = isset($scheduleGrid[$selectedOfficeId][$dateStr]) ? $scheduleGrid[$selectedOfficeId][$dateStr] : null;
                            ?>
                                <td style="padding: 15px; text-align: center; border: 1px solid #ddd; vertical-align: middle; min-height: 80px;">
                                    <div style="display: flex; gap: 8px; justify-content: center; align-items: center; flex-wrap: wrap;">
                                        <?php 
                                        // زر الرجال - يظهر دائماً
                                        $hasMenData = $cellData && $cellData['men'];
                                        $hasMenFile = $hasMenData && !empty($cellData['men']['image']);
                                        if ($hasMenFile) {
                                            $menFileUrl = getImageUrl($cellData['men']['image']);
                                            $isMenPdf = pathinfo($cellData['men']['image'], PATHINFO_EXTENSION) === 'pdf';
                                        }
                                        ?>
                                        <button onclick="<?php 
                                            if ($hasMenFile) {
                                                echo "openPdf('" . htmlspecialchars($menFileUrl) . "', " . ($isMenPdf ? 'true' : 'false') . ");";
                                            } else {
                                                echo "alert('مفيش ملف للرجال');";
                                            }
                                        ?>" 
                                                style="background: #4a9eff; color: white; border: none; padding: 10px 15px; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 16px; min-width: 45px; box-shadow: 0 2px 4px rgba(0,0,0,0.2); transition: all 0.3s;"
                                                onmouseover="this.style.transform='scale(1.1)'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.3)';"
                                                onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.2)';"
                                                title="<?php echo $hasMenData ? (htmlspecialchars($cellData['men']['time'] ?? '') . ' - ' . htmlspecialchars($cellData['men']['trainer'] ?? '')) : 'رجال'; ?>">
                                            ر
                                        </button>
                                        
                                        <?php 
                                        // زر النساء - يظهر دائماً
                                        $hasWomenData = $cellData && $cellData['women'];
                                        $hasWomenFile = $hasWomenData && !empty($cellData['women']['image']);
                                        if ($hasWomenFile) {
                                            $womenFileUrl = getImageUrl($cellData['women']['image']);
                                            $isWomenPdf = pathinfo($cellData['women']['image'], PATHINFO_EXTENSION) === 'pdf';
                                        }
                                        ?>
                                        <button onclick="<?php 
                                            if ($hasWomenFile) {
                                                echo "openPdf('" . htmlspecialchars($womenFileUrl) . "', " . ($isWomenPdf ? 'true' : 'false') . ");";
                                            } else {
                                                echo "alert('مفيش ملف للنساء');";
                                            }
                                        ?>" 
                                                style="background: #ff4444; color: white; border: none; padding: 10px 15px; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 16px; min-width: 45px; box-shadow: 0 2px 4px rgba(0,0,0,0.2); transition: all 0.3s;"
                                                onmouseover="this.style.transform='scale(1.1)'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.3)';"
                                                onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.2)';"
                                                title="<?php echo $hasWomenData ? (htmlspecialchars($cellData['women']['time'] ?? '') . ' - ' . htmlspecialchars($cellData['women']['trainer'] ?? '')) : 'نساء'; ?>">
                                            ن
                                        </button>
                                    </div>
                                </td>
                            <?php endfor; ?>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-data" style="text-align: center; padding: 40px; background: white; border-radius: 10px; margin-top: 20px;">
                <p style="font-size: 18px; color: #666; margin-bottom: 20px;">
                    <?php if (empty($allWeeks)): ?>
                        لا توجد أسابيع متاحة لهذا المكتب
                    <?php elseif ($selectedWeekId <= 0): ?>
                        يرجى اختيار أسبوع من القائمة
                    <?php else: ?>
                        لا توجد حجوزات في هذا الأسبوع
                    <?php endif; ?>
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
                // إنشاء رابط تحميل
                const link = document.createElement('a');
                link.href = fileUrl;
                link.target = '_blank';
                link.download = ''; // السماح بالتحميل
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
        
        function openModal(imageUrl, gender, trainer, time, date) {
            const modal = document.getElementById('imageModal');
            const modalImage = document.getElementById('modalImage');
            const modalTitle = document.getElementById('modalTitle');
            
            modalTitle.textContent = gender + ' - ' + trainer + ' - ' + time + ' - ' + date;
            modalImage.src = imageUrl;
            modal.style.display = 'block';
        }
        
        document.querySelector('.close').onclick = function() {
            document.getElementById('imageModal').style.display = 'none';
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

