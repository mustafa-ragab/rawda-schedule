<?php
require_once 'config.php';

$selectedOfficeId = isset($_GET['office_id']) ? (int)$_GET['office_id'] : 0;
$selectedWeekId = isset($_GET['week_id']) ? (int)$_GET['week_id'] : 0;

if ($selectedOfficeId <= 0 || $selectedWeekId <= 0) {
    die('معاملات غير صحيحة');
}

$conn = getDBConnection();

// جلب بيانات المكتب
$officeQuery = "SELECT * FROM offices WHERE id = ?";
$stmt = $conn->prepare($officeQuery);
$stmt->bind_param("i", $selectedOfficeId);
$stmt->execute();
$officeResult = $stmt->get_result();
$office = $officeResult->fetch_assoc();
$stmt->close();

if (!$office) {
    die('المكتب غير موجود');
}

// جلب بيانات الأسبوع
$weekQuery = "SELECT * FROM weeks WHERE id = ? AND office_id = ?";
$stmt = $conn->prepare($weekQuery);
$stmt->bind_param("ii", $selectedWeekId, $selectedOfficeId);
$stmt->execute();
$weekResult = $stmt->get_result();
$week = $weekResult->fetch_assoc();
$stmt->close();

if (!$week) {
    die('الأسبوع غير موجود');
}

// جلب الجلسات
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

// تجميع الأسابيع حسب الشهر
$weeksByMonth = [];
$allWeeksQuery = "SELECT * FROM weeks WHERE office_id = ? ORDER BY start_date DESC";
$stmt = $conn->prepare($allWeeksQuery);
$stmt->bind_param("i", $selectedOfficeId);
$stmt->execute();
$allWeeksResult = $stmt->get_result();
$allWeeks = $allWeeksResult->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($allWeeks as $w) {
    $wDate = new DateTime($w['start_date']);
    $wMonth = (int)$wDate->format('n');
    $wYear = (int)$wDate->format('Y');
    
    if (!isset($weeksByMonth[$wYear])) {
        $weeksByMonth[$wYear] = [];
    }
    if (!isset($weeksByMonth[$wYear][$wMonth])) {
        $weeksByMonth[$wYear][$wMonth] = [];
    }
    $weeksByMonth[$wYear][$wMonth][] = $w;
}

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

// إنشاء مصفوفة الجدول
$scheduleGrid = [];
$days = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];
$startDate = new DateTime($week['start_date']);

// حساب يوم الأسبوع والبدء من السبت
$dayOfWeek = (int)$startDate->format('w'); // 0 = الأحد
$dayOfWeek = ($dayOfWeek == 0) ? 1 : ($dayOfWeek == 6 ? 0 : $dayOfWeek + 1);
$startDate->modify('-' . $dayOfWeek . ' days');

for ($i = 0; $i < 7; $i++) {
    $date = clone $startDate;
    $date->modify("+$i days");
    $dateStr = $date->format('Y-m-d');
    $scheduleGrid[$dateStr] = [
        'day_name' => $days[$i],
        'date' => $dateStr,
        'men' => null,
        'women' => null
    ];
}

// ملء الجدول بالجلسات
foreach ($sessions as $session) {
    $dateStr = $session['date'];
    if (isset($scheduleGrid[$dateStr])) {
        $hasMenData = !empty($session['men_time']) || !empty($session['men_trainer']) || !empty($session['men_image']);
        $hasWomenData = !empty($session['women_time']) || !empty($session['women_trainer']) || !empty($session['women_image']);
        
        if ($hasMenData) {
            $scheduleGrid[$dateStr]['men'] = [
                'time' => $session['men_time'] ?? '',
                'trainer' => $session['men_trainer'] ?? '',
                'image' => $session['men_image'] ?? '',
                'enabled' => (bool)($session['men_enabled'] ?? true)
            ];
        }
        
        if ($hasWomenData) {
            $scheduleGrid[$dateStr]['women'] = [
                'time' => $session['women_time'] ?? '',
                'trainer' => $session['women_trainer'] ?? '',
                'image' => $session['women_image'] ?? '',
                'enabled' => (bool)($session['women_enabled'] ?? true)
            ];
        }
    }
}

$conn->close();

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
            font-size: 20px;
        }
        .header h2 {
            color: #666;
            margin: 5px 0;
            font-size: 14px;
        }
        table {
            width: 95%;
            border-collapse: collapse;
            margin-top: 15px;
            background: white;
            font-size: 12px;
        }
        th {
            background: #e3f2fd !important;
            padding: 8px 5px;
            border: 1px solid #1a4d7a;
            text-align: center;
            font-weight: bold;
            color: #1a4d7a !important;
            font-size: 12px;
        }
        td {
            padding: 8px 5px;
            border: 1px solid #ddd;
            text-align: center;
            vertical-align: middle;
            background: white;
        }
        .office-cell {
            background: #f9f9f9 !important;
            font-weight: bold;
            color: #000 !important;
            font-size: 14px;
        }
        .button {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 6px;
            color: #FFFFFF !important;
            font-weight: 900 !important;
            text-decoration: none !important;
            margin: 2px;
            font-size: 18px !important;
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
            font-size: 18px !important;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.8);
            letter-spacing: 1px;
        }
        .button-women {
            background: #C2185B !important;
            border: 2px solid #880E4F !important;
            color: #FFFFFF !important;
            font-weight: 900 !important;
            font-size: 18px !important;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.8);
            letter-spacing: 1px;
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
                font-size: 18px !important;
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
                font-size: 18px !important;
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
        /* تحسين الروابط للطباعة */
        a.button {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>جدول الروضة</h1>
        <h2>أسبوع ' . $weekInMonth . ' - من ' . date('d/m/Y', strtotime($week['start_date'])) . '</h2>
        <h2>' . htmlspecialchars($office['name']) . '</h2>
    </div>
    
    <table>
        <thead>
            <tr>
                <th style="background: #e8e8e8 !important; color: #1a4d7a !important; font-weight: bold;">المكتب</th>';

$headerStartDate = new DateTime($week['start_date']);

// حساب يوم الأسبوع والبدء من السبت
$dayOfWeek = (int)$headerStartDate->format('w'); // 0 = الأحد
$dayOfWeek = ($dayOfWeek == 0) ? 1 : ($dayOfWeek == 6 ? 0 : $dayOfWeek + 1);
$headerStartDate->modify('-' . $dayOfWeek . ' days');

for ($i = 0; $i < 7; $i++) {
    $date = clone $headerStartDate;
    $date->modify("+$i days");
    $dayName = $days[$i];
    $dayNum = $date->format('d');
    $monthNum = (int)$date->format('n');
    $html .= '<th style="background: #e3f2fd !important; color: #1a4d7a !important; font-weight: bold;">' . htmlspecialchars($dayName) . '<br><span style="font-size: 0.9em; color: #666;">' . $dayNum . '-' . $monthNum . '</span></th>';
}

$html .= '</tr>
        </thead>
        <tbody>
            <tr>
                <td class="office-cell">' . htmlspecialchars($office['name']) . '</td>';

// حساب المسار الكامل مرة واحدة
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . '://' . $host;

// إعادة حساب startDate للعرض (من السبت)
$displayStartDate = new DateTime($week['start_date']);
$dayOfWeek = (int)$displayStartDate->format('w'); // 0 = الأحد
$dayOfWeek = ($dayOfWeek == 0) ? 1 : ($dayOfWeek == 6 ? 0 : $dayOfWeek + 1);
$displayStartDate->modify('-' . $dayOfWeek . ' days');

for ($i = 0; $i < 7; $i++) {
    $date = clone $displayStartDate;
    $date->modify("+$i days");
    $dateStr = $date->format('Y-m-d');
    $cellData = isset($scheduleGrid[$dateStr]) ? $scheduleGrid[$dateStr] : null;
    
    $html .= '<td>';
    
    // زر الرجال
    $hasMenData = $cellData && $cellData['men'];
    $hasMenFile = $hasMenData && !empty($cellData['men']['image']);
    if ($hasMenFile) {
        $menFileUrl = getImageUrl($cellData['men']['image']);
        $fullMenUrl = $baseUrl . '/' . ltrim($menFileUrl, '/');
        // رابط قابل للنقر في PDF
        $html .= '<a href="' . htmlspecialchars($fullMenUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" class="button button-men" style="text-decoration: none !important; color: #FFFFFF !important; border: 2px solid #0D47A1 !important; background: #1976D2 !important; font-size: 18px !important; font-weight: 900 !important; text-shadow: 1px 1px 3px rgba(0,0,0,0.8); letter-spacing: 1px; display: inline-block; padding: 8px 12px; margin: 2px;">ر</a>';
    } else {
        $html .= '<span class="button" style="opacity: 0.5; cursor: not-allowed; background: #ccc !important; border: 2px solid #999 !important; color: #666 !important; font-size: 18px !important; padding: 8px 12px; margin: 2px; display: inline-block;" title="مش موجود ملف">ر</span>';
    }
    
    // زر النساء
    $hasWomenData = $cellData && $cellData['women'];
    $hasWomenFile = $hasWomenData && !empty($cellData['women']['image']);
    if ($hasWomenFile) {
        $womenFileUrl = getImageUrl($cellData['women']['image']);
        $fullWomenUrl = $baseUrl . '/' . ltrim($womenFileUrl, '/');
        // رابط قابل للنقر في PDF
        $html .= '<a href="' . htmlspecialchars($fullWomenUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" class="button button-women" style="text-decoration: none !important; color: #FFFFFF !important; border: 2px solid #880E4F !important; background: #C2185B !important; font-size: 18px !important; font-weight: 900 !important; text-shadow: 1px 1px 3px rgba(0,0,0,0.8); letter-spacing: 1px; display: inline-block; padding: 8px 12px; margin: 2px;">ن</a>';
    } else {
        $html .= '<span class="button" style="opacity: 0.5; cursor: not-allowed; background: #ccc !important; border: 2px solid #999 !important; color: #666 !important; font-size: 18px !important; padding: 8px 12px; margin: 2px; display: inline-block;" title="مش موجود ملف">ن</span>';
    }
    
    $html .= '</td>';
}

$html .= '</tr>
        </tbody>
    </table>
</body>
</html>';

// محاولة استخدام TCPDF أولاً (الأفضل للروابط)
$useTCPDF = false;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once(__DIR__ . '/vendor/autoload.php');
    if (class_exists('TCPDF')) {
        $useTCPDF = true;
    }
} elseif (file_exists(__DIR__ . '/tcpdf/tcpdf.php')) {
    require_once(__DIR__ . '/tcpdf/tcpdf.php');
    if (class_exists('TCPDF')) {
        $useTCPDF = true;
    }
}

if ($useTCPDF) {
    // إعادة توجيه إلى export_pdf_tcpdf.php
    header('Location: export_pdf_tcpdf.php?office_id=' . $selectedOfficeId . '&week_id=' . $selectedWeekId);
    exit;
}

// إذا لم يكن TCPDF متاحاً، حاول mPDF
$useMPDF = false;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once(__DIR__ . '/vendor/autoload.php');
    if (class_exists('\Mpdf\Mpdf')) {
        $useMPDF = true;
    }
}

if ($useMPDF) {
    // استخدام mPDF لإنشاء PDF حقيقي
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
        
        $mpdf->SetTitle('جدول الروضة - ' . htmlspecialchars($office['name']));
        $mpdf->SetAuthor('Rawda Schedule');
        
        // إعدادات للروابط الخارجية
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        
        // استخدام نفس HTML
        $mpdf->WriteHTML($html);
        
        // تحميل PDF مباشرة
        $mpdf->Output('schedule_' . $selectedOfficeId . '_' . $selectedWeekId . '.pdf', 'D');
        exit;
    } catch (Exception $e) {
        // إذا فشل mPDF، استخدم HTML
        $useMPDF = false;
    }
}

// إذا لم تكن أي مكتبة متاحة، عرض HTML فقط
header('Content-Type: text/html; charset=utf-8');
echo $html;
?>
<script>
    // طباعة مباشرة عند تحميل الصفحة (إذا لم تكن المكتبة متاحة)
    window.onload = function() {
        setTimeout(function() {
            window.print();
        }, 500);
    };
    
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
</script>

