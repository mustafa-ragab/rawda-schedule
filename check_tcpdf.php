<?php
// ملف للتحقق من وجود مكتبات PDF
echo "<h2>التحقق من مكتبات PDF</h2>";

// التحقق من mPDF (الأسهل)
$mpdfExists = false;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once(__DIR__ . '/vendor/autoload.php');
    if (class_exists('\Mpdf\Mpdf')) {
        $mpdfExists = true;
        echo "<p style='color: green;'>✅ <strong>mPDF موجود</strong> - الروابط ستعمل في PDF بعد التنزيل</p>";
        echo "<p>💡 <strong>mPDF هو الحل الموصى به</strong> - أسهل وأفضل من TCPDF</p>";
    }
}

if (!$mpdfExists) {
    echo "<p style='color: red;'>❌ mPDF غير موجود</p>";
}

echo "<hr>";

// التحقق من TCPDF
$tcpdfExists = false;
if (file_exists(__DIR__ . '/tcpdf/tcpdf.php')) {
    $tcpdfExists = true;
    echo "<p style='color: green;'>✅ TCPDF موجود - الروابط ستعمل في PDF بعد التنزيل</p>";
} elseif (class_exists('TCPDF')) {
    $tcpdfExists = true;
    echo "<p style='color: green;'>✅ TCPDF موجود (مثبت عبر Composer) - الروابط ستعمل في PDF بعد التنزيل</p>";
} else {
    echo "<p style='color: orange;'>⚠️ TCPDF غير موجود</p>";
}

echo "<hr>";

if (!$mpdfExists && !$tcpdfExists) {
    echo "<h3>التثبيت:</h3>";
    echo "<h4>الطريقة 1: تثبيت mPDF (موصى به - أسهل)</h4>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border-radius: 5px;'>";
    echo "cd C:\\xampp\\htdocs\\rawda-schedule\n";
    echo "composer require mpdf/mpdf\n";
    echo "</pre>";
    
    echo "<h4>الطريقة 2: تثبيت TCPDF</h4>";
    echo "<ol>";
    echo "<li>اذهب إلى: <a href='https://github.com/tecnickcom/TCPDF/releases' target='_blank'>https://github.com/tecnickcom/TCPDF/releases</a></li>";
    echo "<li>حمّل أحدث إصدار (ZIP)</li>";
    echo "<li>استخرج الملف</li>";
    echo "<li>انسخ مجلد 'tcpdf' إلى: C:\\xampp\\htdocs\\rawda-schedule\\tcpdf</li>";
    echo "</ol>";
} else {
    echo "<p style='color: green; font-size: 18px;'><strong>✅ كل شيء جاهز! الروابط ستعمل في PDF بعد التنزيل.</strong></p>";
}
?>

