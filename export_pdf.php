<?php
require_once 'config.php';

// جلب التاريخ المحدد
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
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
        
        // ملء الجدول بالجلسات
        foreach ($sessions as $session) {
            $dateStr = $session['date'];
            if (isset($scheduleGrid[$officeId][$dateStr])) {
                $scheduleGrid[$officeId][$dateStr]['men'] = [
                    'time' => $session['men_time'] ?? '',
                    'trainer' => $session['men_trainer'] ?? '',
                    'image' => $session['men_image'] ?? '',
                    'enabled' => (bool)($session['men_enabled'] ?? true)
                ];
                
                $scheduleGrid[$officeId][$dateStr]['women'] = [
                    'time' => $session['women_time'] ?? '',
                    'trainer' => $session['women_trainer'] ?? '',
                    'image' => $session['women_image'] ?? '',
                    'enabled' => (bool)($session['women_enabled'] ?? true)
                ];
            }
        }
    }
}

$conn->close();

// حساب المسار الكامل للروابط
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . '://' . $host . dirname($_SERVER['PHP_SELF']);

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
        }
        .header h1 {
            color: #1a4d7a;
            margin: 0;
            font-size: 24px;
        }
        .header h2 {
            color: #666;
            margin: 5px 0;
            font-size: 16px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            background: white;
            font-size: 11px;
        }
        th {
            background: #e3f2fd !important;
            padding: 10px 5px;
            border: 1px solid #1a4d7a;
            text-align: center;
            font-weight: bold;
            color: #1a4d7a !important;
            font-size: 12px;
        }
        td {
            padding: 10px 5px;
            border: 1px solid #ddd;
            text-align: center;
            vertical-align: middle;
            background: white;
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
            display: inline-block;
            padding: 8px 12px;
            border-radius: 6px;
            color: #FFFFFF !important;
            font-weight: 900 !important;
            text-decoration: none !important;
            margin: 2px;
            font-size: 16px !important;
            cursor: pointer;
            min-width: 40px;
            text-align: center;
            line-height: 1.2;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        .button:hover {
            opacity: 0.9;
        }
        .button-men {
            background: #1976D2 !important;
            border: 2px solid #0D47A1 !important;
            color: #FFFFFF !important;
            font-weight: 900 !important;
            font-size: 16px !important;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.8);
            letter-spacing: 1px;
        }
        .button-women {
            background: #C2185B !important;
            border: 2px solid #880E4F !important;
            color: #FFFFFF !important;
            font-weight: 900 !important;
            font-size: 16px !important;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.8);
            letter-spacing: 1px;
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
            .button-men {
                background: #1976D2 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color: #FFFFFF !important;
                font-size: 16px !important;
                font-weight: 900 !important;
                text-shadow: 1px 1px 3px rgba(0,0,0,0.8) !important;
                border: 2px solid #0D47A1 !important;
                padding: 8px 12px !important;
            }
            .button-women {
                background: #C2185B !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color: #FFFFFF !important;
                font-size: 16px !important;
                font-weight: 900 !important;
                text-shadow: 1px 1px 3px rgba(0,0,0,0.8) !important;
                border: 2px solid #880E4F !important;
                padding: 8px 12px !important;
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
            
            $hasMenData = $cellData && $cellData['men'];
            $hasMenFile = $hasMenData && !empty($cellData['men']['image']);
            $hasWomenData = $cellData && $cellData['women'];
            $hasWomenFile = $hasWomenData && !empty($cellData['women']['image']);
            $hasAnyFile = $hasMenFile || $hasWomenFile;
            
            if ($hasMenFile) {
                $menFileUrl = getImageUrl($cellData['men']['image']);
                $fullMenUrl = $baseUrl . '/' . ltrim($menFileUrl, '/');
                $html .= '<a href="' . htmlspecialchars($fullMenUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" class="button button-men">ر</a>';
            }
            
            if ($hasWomenFile) {
                $womenFileUrl = getImageUrl($cellData['women']['image']);
                $fullWomenUrl = $baseUrl . '/' . ltrim($womenFileUrl, '/');
                $html .= '<a href="' . htmlspecialchars($fullWomenUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" class="button button-women">ن</a>';
            }
            
            if (!$hasAnyFile) {
                $html .= '<span style="color: #ccc; font-size: 12px;">-</span>';
            }
        } else {
            $html .= '<span style="color: #856404; font-size: 11px;">-</span>';
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
        ]);
        
        $mpdf->SetTitle('جدول الروضة - ' . date('d/m/Y', strtotime($headerStartDate->format('Y-m-d'))));
        $mpdf->SetAuthor('Rawda Schedule');
        
        // تفعيل الروابط الخارجية
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        
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
