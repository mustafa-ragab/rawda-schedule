<?php
// بدء output buffering لمنع أي output قبل PDF
ob_start();

require_once 'config.php';

// محاولة استخدام TCPDF إذا كان متاحاً
$useTCPDF = false;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once(__DIR__ . '/vendor/autoload.php');
    if (class_exists('TCPDF')) {
        $useTCPDF = true;
    }
} elseif (file_exists(__DIR__ . '/tcpdf/tcpdf.php')) {
    require_once(__DIR__ . '/tcpdf/tcpdf.php');
    $useTCPDF = true;
} elseif (class_exists('TCPDF')) {
    $useTCPDF = true;
}

$selectedOfficeId = isset($_GET['office_id']) ? (int)$_GET['office_id'] : 0;
$selectedWeekId = isset($_GET['week_id']) ? (int)$_GET['week_id'] : 0;

if ($selectedOfficeId <= 0 || $selectedWeekId <= 0) {
    ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
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
    ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
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
    ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
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

// حساب المسار الكامل
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . '://' . $host;

if ($useTCPDF) {
    // تنظيف أي output قبل إنشاء PDF
    ob_end_clean();
    
    // استخدام TCPDF لإنشاء PDF حقيقي
    $pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    $pdf->SetCreator('Rawda Schedule');
    $pdf->SetAuthor('Rawda Schedule');
    $pdf->SetTitle('جدول الروضة - ' . htmlspecialchars($office['name']));
    $pdf->SetSubject('جدول الروضة');
    
    // تفعيل الروابط الخارجية
    $pdf->setHtmlLinksStyle(array('color' => array(0, 0, 255)));
    
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // إعدادات للروابط
    $pdf->SetAutoPageBreak(false);
    
    $pdf->AddPage();
    
    // العنوان
    $pdf->SetFont('dejavusans', 'B', 16);
    $pdf->Cell(0, 8, 'جدول الروضة', 0, 1, 'C');
    $pdf->SetFont('dejavusans', '', 12);
    $pdf->Cell(0, 8, 'أسبوع ' . $weekInMonth . ' - من ' . date('d/m/Y', strtotime($week['start_date'])), 0, 1, 'C');
    $pdf->Cell(0, 8, htmlspecialchars($office['name']), 0, 1, 'C');
    $pdf->Ln(8);
    
    // إنشاء الجدول
    $pdf->SetFont('dejavusans', 'B', 10);
    $cellWidth = 35; // زيادة العرض قليلاً
    $cellHeight = 15; // زيادة الارتفاع للأزرار
    
    // رأس الجدول
    $pdf->SetFillColor(227, 242, 253); // لون أزرق فاتح
    $pdf->SetTextColor(26, 77, 122); // لون أزرق داكن
    $pdf->Cell(40, $cellHeight, 'المكتب', 1, 0, 'C', true);
    
    // حساب يوم الأسبوع والبدء من السبت
    $headerStartDate = new DateTime($week['start_date']);
    $dayOfWeek = (int)$headerStartDate->format('w'); // 0 = الأحد
    $dayOfWeek = ($dayOfWeek == 0) ? 1 : ($dayOfWeek == 6 ? 0 : $dayOfWeek + 1);
    $headerStartDate->modify('-' . $dayOfWeek . ' days');
    
    for ($i = 0; $i < 7; $i++) {
        $date = clone $headerStartDate;
        $date->modify("+$i days");
        $dayName = $days[$i];
        $dayNum = $date->format('d');
        $monthNum = (int)$date->format('n');
        $pdf->Cell($cellWidth, $cellHeight, $dayName . "\n" . $dayNum . '-' . $monthNum, 1, 0, 'C', true);
    }
    $pdf->SetTextColor(0, 0, 0); // إعادة اللون للأسود
    $pdf->Ln();
    
    // صف البيانات
    $pdf->SetFillColor(249, 249, 249);
    $pdf->Cell(40, $cellHeight, htmlspecialchars($office['name']), 1, 0, 'C', true);
    
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
        
        // التحقق من وجود بيانات الرجال والنساء
        $hasMenData = $cellData && $cellData['men'];
        $hasWomenData = $cellData && $cellData['women'];
        $hasMenFile = $hasMenData && !empty($cellData['men']['image']);
        $hasWomenFile = $hasWomenData && !empty($cellData['women']['image']);
        
        $menUrl = '';
        $womenUrl = '';
        
        if ($hasMenFile) {
            $menFileUrl = getImageUrl($cellData['men']['image']);
            $menUrl = $baseUrl . '/' . ltrim($menFileUrl, '/');
        }
        
        if ($hasWomenFile) {
            $womenFileUrl = getImageUrl($cellData['women']['image']);
            $womenUrl = $baseUrl . '/' . ltrim($womenFileUrl, '/');
        }
        
        // إضافة روابط قابلة للنقر
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        
        $pdf->SetFont('dejavusans', 'B', 20); // حجم خط أكبر وأوضح
        
        // زر الرجال - دائماً يظهر بلون أزرق (في كل خلية)
        $menButtonX = $x;
        $menButtonY = $y;
        $menButtonWidth = $cellWidth / 2;
        $menButtonHeight = $cellHeight;
        
        if ($hasMenFile) {
            // زر الرجال مع رابط - لون أزرق احترافي
            $pdf->SetFillColor(25, 118, 210); // #1976D2 - أزرق احترافي
            $pdf->SetTextColor(255, 255, 255); // أبيض
            $pdf->SetXY($menButtonX, $menButtonY);
            $pdf->Cell($menButtonWidth, $menButtonHeight, 'ر', 1, 0, 'C', true);
            // إضافة رابط قابل للنقر - يعمل حتى بعد التنزيل
            $pdf->Link($menButtonX, $menButtonY, $menButtonWidth, $menButtonHeight, $menUrl);
        } else {
            // زر بدون ملف - أزرق فاتح (غير قابل للنقر)
            $pdf->SetFillColor(187, 222, 251); // أزرق فاتح
            $pdf->SetTextColor(100, 100, 100); // رمادي
            $pdf->SetXY($menButtonX, $menButtonY);
            $pdf->Cell($menButtonWidth, $menButtonHeight, 'ر', 1, 0, 'C', true);
        }
        
        // زر النساء - دائماً يظهر بلون وردي (في كل خلية)
        $womenButtonX = $x + $cellWidth / 2; // دائماً بجانب زر الرجال
        $womenButtonY = $y;
        $womenButtonWidth = $cellWidth / 2;
        $womenButtonHeight = $cellHeight;
        
        if ($hasWomenFile) {
            // زر النساء مع رابط - لون وردي احترافي
            $pdf->SetFillColor(194, 24, 91); // #C2185B - وردي احترافي
            $pdf->SetTextColor(255, 255, 255); // أبيض
            $pdf->SetXY($womenButtonX, $womenButtonY);
            $pdf->Cell($womenButtonWidth, $womenButtonHeight, 'ن', 1, 0, 'C', true);
            // إضافة رابط قابل للنقر - يعمل حتى بعد التنزيل
            $pdf->Link($womenButtonX, $womenButtonY, $womenButtonWidth, $womenButtonHeight, $womenUrl);
        } else {
            // زر بدون ملف - وردي فاتح (غير قابل للنقر)
            $pdf->SetFillColor(248, 187, 208); // وردي فاتح
            $pdf->SetTextColor(100, 100, 100); // رمادي
            $pdf->SetXY($womenButtonX, $womenButtonY);
            $pdf->Cell($womenButtonWidth, $womenButtonHeight, 'ن', 1, 0, 'C', true);
        }
        
        // الانتقال للخلية التالية
        $pdf->SetXY($x + $cellWidth, $y);
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFillColor(255, 255, 255);
    }
    
    // إعداد PDF للروابط الخارجية - تعمل حتى بعد التنزيل
    // TCPDF يدعم الروابط الخارجية تلقائياً
    
    // حفظ PDF مع روابط قابلة للنقر
    $pdf->Output('schedule_' . $selectedOfficeId . '_' . $selectedWeekId . '.pdf', 'D');
    exit;
} else {
    // استخدام HTML محسّن مع JavaScript لتحويله إلى PDF
    // إعادة توجيه إلى export_pdf.php العادي
    header('Location: export_pdf.php?office_id=' . $selectedOfficeId . '&week_id=' . $selectedWeekId);
    exit;
}
?>

