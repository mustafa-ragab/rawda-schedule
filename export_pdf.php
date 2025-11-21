<?php
require_once 'config.php';

// جلب التاريخ المحدد
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
// التحقق من صحة التاريخ
if (!validateDate($selectedDate)) {
    $selectedDate = date('Y-m-d');
}
$selectedDateObj = new DateTime($selectedDate);

// حساب بداية الأسبوع من التاريخ المحدد (الأحد)
$headerStartDate = clone $selectedDateObj;
$dayOfWeek = (int)$headerStartDate->format('w'); // 0 = الأحد
if ($dayOfWeek != 0) {
    $headerStartDate->modify('-' . $dayOfWeek . ' days');
}

$conn = getDBConnection();

// جلب جميع المكاتب
$officesQuery = "SELECT * FROM offices ORDER BY name";
$officesResult = $conn->query($officesQuery);
$offices = $officesResult->fetch_all(MYSQLI_ASSOC);

// جلب بيانات جميع المكاتب
$scheduleGrid = [];
$officesWeeks = [];
$days = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

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
    $foundWeek = null;
    foreach ($officeWeeks as $week) {
        $weekStart = new DateTime($week['start_date']);
        $dayOfWeek = (int)$weekStart->format('w');
        if ($dayOfWeek != 0) {
            $weekStart->modify('-' . $dayOfWeek . ' days');
        }
        $weekEnd = clone $weekStart;
        $weekEnd->modify('+6 days');
        
        if ($selectedDateObj >= $weekStart && $selectedDateObj <= $weekEnd) {
            $foundWeek = $week;
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
            $dayOfWeek = (int)$weekStart->format('w');
            if ($dayOfWeek != 0) {
                $weekStart->modify('-' . $dayOfWeek . ' days');
            }
            $diff = abs($selectedDateObj->getTimestamp() - $weekStart->getTimestamp());
            
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $closestWeek = $week;
                $closestWeek['start_date'] = $weekStart->format('Y-m-d');
            }
        }
        
        if ($closestWeek) {
            $foundWeek = $closestWeek;
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
        
        // إنشاء مصفوفة الأيام
        $startDate = new DateTime($foundWeek['start_date']);
        $dayOfWeek = (int)$startDate->format('w');
        if ($dayOfWeek != 0) {
            $startDate->modify('-' . $dayOfWeek . ' days');
        }
        
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
        
        // ملء الجدول بالجلسات والملفات
        foreach ($sessions as $session) {
            $dateStr = isset($session['date']) ? $session['date'] : null;
            $sessionId = isset($session['id']) ? (int)$session['id'] : 0;
            
            if (empty($dateStr) || $sessionId <= 0 || !isset($scheduleGrid[$officeId][$dateStr])) {
                continue;
            }
            
            // جلب ملفات الرجال من جدول session_files
            $menFiles = [];
            $menFilesQuery = "SELECT file_name, file_path FROM session_files WHERE session_id = ? AND file_type = 'men' ORDER BY id ASC";
            $fileStmt = $conn->prepare($menFilesQuery);
            if ($fileStmt) {
                $fileStmt->bind_param("i", $sessionId);
                if ($fileStmt->execute()) {
                    $menFilesResult = $fileStmt->get_result();
                    if ($menFilesResult) {
                        while ($fileRow = $menFilesResult->fetch_assoc()) {
                            if (!empty($fileRow['file_name'])) {
                                $fileName = trim($fileRow['file_name']);
                                $filePath = !empty($fileRow['file_path']) ? trim($fileRow['file_path']) : getImageUrl($fileName);
                                
                                // إذا كان file_path فارغاً أو null، استخدم getImageUrl
                                if (empty($filePath)) {
                                    $filePath = getImageUrl($fileName);
                                }
                                
                                if (!empty($filePath)) {
                                    $menFiles[] = [
                                        'filename' => $fileName,
                                        'path' => $filePath
                                    ];
                                }
                            }
                        }
                    }
                } else {
                    error_log("Error executing men files query: " . $fileStmt->error);
                }
                $fileStmt->close();
            } else {
                error_log("Error preparing men files query: " . $conn->error);
            }
            
            // جلب ملفات النساء من جدول session_files
            $womenFiles = [];
            $womenFilesQuery = "SELECT file_name, file_path FROM session_files WHERE session_id = ? AND file_type = 'women' ORDER BY id ASC";
            $fileStmt = $conn->prepare($womenFilesQuery);
            if ($fileStmt) {
                $fileStmt->bind_param("i", $sessionId);
                if ($fileStmt->execute()) {
                    $womenFilesResult = $fileStmt->get_result();
                    if ($womenFilesResult) {
                        while ($fileRow = $womenFilesResult->fetch_assoc()) {
                            if (!empty($fileRow['file_name'])) {
                                $fileName = trim($fileRow['file_name']);
                                $filePath = !empty($fileRow['file_path']) ? trim($fileRow['file_path']) : getImageUrl($fileName);
                                
                                // إذا كان file_path فارغاً أو null، استخدم getImageUrl
                                if (empty($filePath)) {
                                    $filePath = getImageUrl($fileName);
                                }
                                
                                if (!empty($filePath)) {
                                    $womenFiles[] = [
                                        'filename' => $fileName,
                                        'path' => $filePath
                                    ];
                                }
                            }
                        }
                    }
                } else {
                    error_log("Error executing women files query: " . $fileStmt->error);
                }
                $fileStmt->close();
            } else {
                error_log("Error preparing women files query: " . $conn->error);
            }
            
            // إذا لم توجد ملفات في الجدول الجديد، نستخدم الملف القديم (للتوافق مع البيانات القديمة)
            if (empty($menFiles) && !empty($session['men_image'])) {
                $menFiles[] = [
                    'filename' => $session['men_image'],
                    'path' => getImageUrl($session['men_image'])
                ];
            }
            
            if (empty($womenFiles) && !empty($session['women_image'])) {
                $womenFiles[] = [
                    'filename' => $session['women_image'],
                    'path' => getImageUrl($session['women_image'])
                ];
            }
            
            $scheduleGrid[$officeId][$dateStr]['men'] = [
                'files' => $menFiles,
                'enabled' => (bool)($session['men_enabled'] ?? true)
            ];
            
            $scheduleGrid[$officeId][$dateStr]['women'] = [
                'files' => $womenFiles,
                'enabled' => (bool)($session['women_enabled'] ?? true)
            ];
        }
    }
}

$conn->close();

// حساب المسار الكامل للروابط - استخدام مسار نسبي أو مطلق
// للحصول على أفضل توافق، نستخدم مسار نسبي من المجلد الحالي
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$scriptPath = dirname($_SERVER['PHP_SELF']);
// إزالة الشرطة المائلة الأخيرة إذا كانت موجودة
$scriptPath = rtrim($scriptPath, '/');
$baseUrl = $protocol . '://' . $host . $scriptPath;

// إنشاء HTML للـ PDF
$html = '<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Arial, sans-serif;
            direction: rtl;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }
        .header h1 {
            color: #1a4d7a;
            margin: 0;
            font-size: 28px;
            font-weight: bold;
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
        .header h2 {
            color: #1565c0;
            margin: 8px 0 0 0;
            font-size: 18px;
            font-weight: 600;
        }
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 20px;
            background: white;
            font-size: 11px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden;
        }
        th {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%) !important;
            padding: 12px 8px;
            border: 1px solid #1a4d7a;
            text-align: center;
            font-weight: bold;
            color: #1a4d7a !important;
            font-size: 13px;
            border-bottom: 2px solid #1a4d7a;
        }
        td {
            padding: 12px 8px;
            border: 1px solid #ddd;
            text-align: center;
            vertical-align: middle;
            background: white;
            min-height: 60px;
        }
        .office-cell {
            background: #f9f9f9 !important;
            font-weight: bold;
            color: #000 !important;
            font-size: 13px;
        }
        .office-cell-no-week {
            background: #fff3cd !important;
            font-weight: bold;
            color: #856404 !important;
            font-size: 13px;
        }
        .button {
            display: inline-block !important;
            padding: 10px 18px !important;
            border-radius: 6px !important;
            color: #FFFFFF !important;
            font-weight: bold !important;
            text-decoration: none !important;
            margin: 4px 10px !important;
            font-size: 16px !important;
            cursor: pointer;
            min-width: 45px !important;
            text-align: center !important;
            line-height: 1.2 !important;
            border: 2px solid !important;
        }
        .button:hover {
            opacity: 0.92;
            transform: translateY(-1px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.35), 0 3px 6px rgba(0,0,0,0.25), inset 0 1px 0 rgba(255,255,255,0.25);
        }
        .button-men {
            background: #1976D2 !important;
            border-color: #0D47A1 !important;
            color: #FFFFFF !important;
            font-weight: bold !important;
            font-size: 16px !important;
        }
        .button-women {
            background: #E91E63 !important;
            border-color: #880E4F !important;
            color: #FFFFFF !important;
            font-weight: bold !important;
            font-size: 16px !important;
        }
        .cell-no-week {
            background: #fff3cd !important;
            opacity: 0.7;
        }
        @media print {
            body {
                padding: 10px;
                background: white;
            }
            table {
                background: white !important;
            }
            th {
                background: #e3f2fd !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color: #1a4d7a !important;
            }
            .button {
                page-break-inside: avoid;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .button-container {
                text-align: center !important;
                padding: 10px 5px !important;
                min-height: 40px !important;
            }
            .button {
                margin: 4px 10px !important;
                padding: 10px 18px !important;
                border-radius: 6px !important;
                display: inline-block !important;
            }
            .button-men {
                background: #1976D2 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color: #FFFFFF !important;
                font-size: 16px !important;
                font-weight: bold !important;
                border: 2px solid #0D47A1 !important;
                padding: 10px 18px !important;
            }
            .button-women {
                background: #E91E63 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color: #FFFFFF !important;
                font-size: 16px !important;
                font-weight: bold !important;
                border: 2px solid #880E4F !important;
                padding: 10px 18px !important;
            }
            a.button {
                text-decoration: none !important;
                color: #FFFFFF !important;
            }
        }
        a.button {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>جدول الروضة</h1>
        <h2>من ' . date('d/m/Y', strtotime($headerStartDate->format('Y-m-d'))) . '</h2>
    </div>
    
    <table>
        <thead>
            <tr>
                <th style="background: #e8e8e8 !important; color: #1a4d7a !important; font-weight: bold;">المكتب</th>';

// إضافة رؤوس الأيام
for ($i = 0; $i < 7; $i++) {
    $date = clone $headerStartDate;
    $date->modify("+$i days");
    
    // الحصول على اسم اليوم الفعلي من التاريخ (رزنامة واقعية)
    $actualDayOfWeek = (int)$date->format('w'); // 0 = الأحد, 6 = السبت
    $dayName = $days[$actualDayOfWeek]; // استخدام اليوم الفعلي من التاريخ
    
    $dayNum = $date->format('d');
    $monthNum = (int)$date->format('n');
    $yearNum = $date->format('Y');
    $html .= '<th style="background: #e3f2fd !important; color: #1a4d7a !important; font-weight: bold;">' . htmlspecialchars($dayName) . '<br><span style="font-size: 0.9em; color: #666;">' . $dayNum . '/' . $monthNum . '/' . $yearNum . '</span></th>';
}

$html .= '</tr>
        </thead>
        <tbody>';

// إضافة صف لكل مكتب
foreach ($offices as $office) {
    $officeId = $office['id'];
    $officeName = htmlspecialchars($office['name']);
    $hasWeek = isset($scheduleGrid[$officeId]);
    
    $html .= '<tr>
                <td class="' . ($hasWeek ? 'office-cell' : 'office-cell-no-week') . '">' . $officeName;
    if (!$hasWeek) {
        $html .= '<br><span style="font-size: 10px; color: #856404;">(لا يوجد أسبوع)</span>';
    }
    $html .= '</td>';
    
    // إضافة خلايا الأيام
    for ($i = 0; $i < 7; $i++) {
        $date = clone $headerStartDate;
        $date->modify("+$i days");
        $dateStr = $date->format('Y-m-d');
        
        $html .= '<td class="' . (!$hasWeek ? 'cell-no-week' : '') . '">';
        
        if ($hasWeek) {
            $cellData = isset($scheduleGrid[$officeId][$dateStr]) ? $scheduleGrid[$officeId][$dateStr] : null;
            
            $menFiles = [];
            $womenFiles = [];
            
            if ($cellData) {
                if (isset($cellData['men']['files']) && is_array($cellData['men']['files'])) {
                    $menFiles = $cellData['men']['files'];
                }
                if (isset($cellData['women']['files']) && is_array($cellData['women']['files'])) {
                    $womenFiles = $cellData['women']['files'];
                }
            }
            
            $hasAnyFile = !empty($menFiles) || !empty($womenFiles);
            
            // عرض الأزرار مباشرة بدون حاوية معقدة
            if ($hasAnyFile) {
                // عرض ملفات الرجال - عرض جميع الملفات
                if (!empty($menFiles)) {
                    $menFileCount = count($menFiles);
                    foreach ($menFiles as $fileIndex => $file) {
                        $menFileUrl = isset($file['path']) ? trim($file['path']) : '';
                        $fileName = isset($file['filename']) ? trim($file['filename']) : '';
                        
                        // إذا كان file_path فارغاً، استخدم filename لبناء URL
                        if (empty($menFileUrl) && !empty($fileName)) {
                            $menFileUrl = getImageUrl($fileName);
                        }
                        
                        // بناء URL كامل مع التحقق من الأمان
                        if (strpos($menFileUrl, 'http://') === 0 || strpos($menFileUrl, 'https://') === 0) {
                            // URL مطلق موجود بالفعل - التحقق من أنه آمن
                            $fullMenUrl = filter_var($menFileUrl, FILTER_VALIDATE_URL);
                            if ($fullMenUrl === false) {
                                continue; // تخطي الملف إذا كان URL غير صالح
                            }
                        } else {
                            // URL نسبي - تنظيف المسار (إزالة أي محاولات directory traversal)
                            $menFileUrl = str_replace('..', '', $menFileUrl); // منع directory traversal
                            $menFileUrl = ltrim($menFileUrl, '/');
                            // URL نسبي - بناء URL كامل
                            $fullMenUrl = $baseUrl . '/' . $menFileUrl;
                            // إزالة أي مسارات مكررة
                            $fullMenUrl = preg_replace('#([^:])//+#', '$1/', $fullMenUrl);
                        }
                        
                        // تنظيف URL النهائي
                        $fullMenUrl = str_replace('http:/', 'http://', $fullMenUrl);
                        $fullMenUrl = str_replace('https:/', 'https://', $fullMenUrl);
                        
                        // التحقق النهائي من URL
                        if (empty($fullMenUrl)) {
                            continue; // تخطي الملف إذا كان URL فارغ
                        }
                        
                        $displayFileName = htmlspecialchars(basename($fileName ?: $menFileUrl));
                        $html .= '<a href="' . htmlspecialchars($fullMenUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" class="button button-men">ر</a>';
                    }
                }
                
                // عرض ملفات النساء - عرض جميع الملفات
                if (!empty($womenFiles)) {
                    $womenFileCount = count($womenFiles);
                    foreach ($womenFiles as $fileIndex => $file) {
                        $womenFileUrl = isset($file['path']) ? trim($file['path']) : '';
                        $fileName = isset($file['filename']) ? trim($file['filename']) : '';
                        
                        // إذا كان file_path فارغاً، استخدم filename لبناء URL
                        if (empty($womenFileUrl) && !empty($fileName)) {
                            $womenFileUrl = getImageUrl($fileName);
                        }
                        
                        // بناء URL كامل مع التحقق من الأمان
                        if (strpos($womenFileUrl, 'http://') === 0 || strpos($womenFileUrl, 'https://') === 0) {
                            // URL مطلق موجود بالفعل - التحقق من أنه آمن
                            $fullWomenUrl = filter_var($womenFileUrl, FILTER_VALIDATE_URL);
                            if ($fullWomenUrl === false) {
                                continue; // تخطي الملف إذا كان URL غير صالح
                            }
                        } else {
                            // URL نسبي - تنظيف المسار (إزالة أي محاولات directory traversal)
                            $womenFileUrl = str_replace('..', '', $womenFileUrl); // منع directory traversal
                            $womenFileUrl = ltrim($womenFileUrl, '/');
                            // URL نسبي - بناء URL كامل
                            $fullWomenUrl = $baseUrl . '/' . $womenFileUrl;
                            // إزالة أي مسارات مكررة
                            $fullWomenUrl = preg_replace('#([^:])//+#', '$1/', $fullWomenUrl);
                        }
                        
                        // تنظيف URL النهائي
                        $fullWomenUrl = str_replace('http:/', 'http://', $fullWomenUrl);
                        $fullWomenUrl = str_replace('https:/', 'https://', $fullWomenUrl);
                        
                        // التحقق النهائي من URL
                        if (empty($fullWomenUrl)) {
                            continue; // تخطي الملف إذا كان URL فارغ
                        }
                        
                        $displayFileName = htmlspecialchars(basename($fileName ?: $womenFileUrl));
                        $html .= '<a href="' . htmlspecialchars($fullWomenUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" class="button button-women">ن</a>';
                    }
                }
            } else {
                $html .= '<span style="color: #ccc; font-size: 13px;">-</span>';
            }
        } else {
            $html .= '<span style="color: #856404; font-size: 12px; display: block; padding: 10px;">-</span>';
        }
        
        $html .= '</td>';
    }
    
    $html .= '</tr>';
}

$html .= '</tbody>
    </table>
</body>
</html>';

// محاولة استخدام mPDF أولاً
$useMPDF = false;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once(__DIR__ . '/vendor/autoload.php');
    if (class_exists('\Mpdf\Mpdf')) {
        $useMPDF = true;
    }
}

if ($useMPDF) {
    try {
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L', // Landscape
            'orientation' => 'L',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 15,
            'margin_bottom' => 15,
            'allow_charset_conversion' => true,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'useSubstitutions' => false,
            'showImageErrors' => true,
        ]);
        
        $mpdf->SetTitle('جدول الروضة - ' . date('d/m/Y', strtotime($headerStartDate->format('Y-m-d'))));
        $mpdf->SetAuthor('Rawda Schedule');
        
        // تفعيل الروابط الخارجية
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        
        // إعدادات إضافية لضمان ظهور الألوان
        $mpdf->useSubstitutions = false;
        
        // إعدادات إضافية للروابط - استخدام WriteHTML العادي
        // mPDF يدعم الروابط تلقائياً في HTML
        $mpdf->WriteHTML($html);
        
        $mpdf->Output('schedule_' . date('Y-m-d', strtotime($headerStartDate->format('Y-m-d'))) . '.pdf', 'D');
        exit;
    } catch (Exception $e) {
        error_log("mPDF Error: " . $e->getMessage());
        $useMPDF = false;
    }
}

// إذا لم تكن mPDF متاحة، عرض HTML مع JavaScript للروابط
header('Content-Type: text/html; charset=utf-8');
echo $html;
?>
<script>
    // جعل الروابط تعمل بشكل صحيح
    document.addEventListener('DOMContentLoaded', function() {
        var buttons = document.querySelectorAll('a.button');
        buttons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var url = this.getAttribute('href');
                if (url) {
                    window.open(url, '_blank');
                }
                return false;
            });
        });
    });
    
    // طباعة مباشرة عند تحميل الصفحة (إذا لم تكن المكتبة متاحة)
    window.onload = function() {
        setTimeout(function() {
            window.print();
        }, 500);
    };
</script>
