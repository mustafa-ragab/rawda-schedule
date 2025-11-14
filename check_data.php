<?php
require_once 'config.php';

$conn = getDBConnection();

// جلب جميع الأسابيع
$weeksQuery = "SELECT * FROM weeks ORDER BY start_date DESC";
$weeksResult = $conn->query($weeksQuery);
$allWeeks = $weeksResult->fetch_all(MYSQLI_ASSOC);

// جلب جميع الجلسات
$sessionsQuery = "SELECT * FROM sessions ORDER BY week_id, date ASC";
$sessionsResult = $conn->query($sessionsQuery);
$allSessions = $sessionsResult->fetch_all(MYSQLI_ASSOC);

// جلب المكاتب
$officesQuery = "SELECT * FROM offices ORDER BY name";
$officesResult = $conn->query($officesQuery);
$offices = $officesResult->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التحقق من البيانات - جدول الروضة</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            direction: rtl;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 1400px;
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
        .section {
            margin-bottom: 30px;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 5px;
        }
        .section h2 {
            color: #1a4d7a;
            margin-top: 0;
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
            font-weight: bold;
        }
        tr:nth-child(even) {
            background: #f9f9f9;
        }
        .error {
            color: red;
            font-weight: bold;
        }
        .success {
            color: green;
            font-weight: bold;
        }
        .warning {
            color: orange;
            font-weight: bold;
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
    </style>
</head>
<body>
    <div class="container">
        <h1>التحقق من البيانات المحفوظة</h1>
        
        <!-- المكاتب -->
        <div class="section">
            <h2>المكاتب (<?php echo count($offices); ?>)</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>اسم المكتب</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($offices)): ?>
                        <tr>
                            <td colspan="2" style="text-align: center; color: red;">لا توجد مكاتب</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($offices as $office): ?>
                            <tr>
                                <td><?php echo $office['id']; ?></td>
                                <td><?php echo htmlspecialchars($office['name']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- الأسابيع -->
        <div class="section">
            <h2>الأسابيع (<?php echo count($allWeeks); ?>)</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>المكتب ID</th>
                        <th>اسم المكتب</th>
                        <th>رقم الأسبوع</th>
                        <th>تاريخ البداية</th>
                        <th>الشهر</th>
                        <th>السنة</th>
                        <th>يوم الأسبوع</th>
                        <th>تاريخ السبت</th>
                        <th>عدد الجلسات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($allWeeks)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; color: red;">لا توجد أسابيع</td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        foreach ($allWeeks as $week): 
                            $weekDate = new DateTime($week['start_date']);
                            $weekMonth = (int)$weekDate->format('n');
                            $weekYear = (int)$weekDate->format('Y');
                            $dayOfWeek = (int)$weekDate->format('w'); // 0 = الأحد
                            $dayName = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'][$dayOfWeek];
                            
                            // حساب تاريخ السبت
                            $saturdayDate = clone $weekDate;
                            $dayOfWeekCalc = ($dayOfWeek == 0) ? 1 : ($dayOfWeek == 6 ? 0 : $dayOfWeek + 1);
                            $saturdayDate->modify('-' . $dayOfWeekCalc . ' days');
                            $saturdayMonth = (int)$saturdayDate->format('n');
                            
                            // عدد الجلسات
                            $sessionCount = 0;
                            foreach ($allSessions as $session) {
                                if ($session['week_id'] == $week['id']) {
                                    $sessionCount++;
                                }
                            }
                            
                            // اسم المكتب
                            $officeName = 'غير معروف';
                            foreach ($offices as $office) {
                                if ($office['id'] == $week['office_id']) {
                                    $officeName = $office['name'];
                                    break;
                                }
                            }
                            
                            // التحقق من صحة البيانات
                            $isValid = true;
                            $errors = [];
                            if ($sessionCount != 7) {
                                $isValid = false;
                                $errors[] = "عدد الجلسات: $sessionCount (المفروض 7)";
                            }
                            if ($weekMonth != $saturdayMonth) {
                                $isValid = false;
                                $errors[] = "الشهر المحفوظ ($weekMonth) يختلف عن شهر السبت ($saturdayMonth)";
                            }
                        ?>
                            <tr>
                                <td><?php echo $week['id']; ?></td>
                                <td><?php echo $week['office_id']; ?></td>
                                <td><?php echo htmlspecialchars($officeName); ?></td>
                                <td><?php echo $week['week_number']; ?></td>
                                <td><?php echo $week['start_date']; ?></td>
                                <td class="<?php echo ($weekMonth != $saturdayMonth) ? 'error' : ''; ?>">
                                    <?php echo $weekMonth; ?>
                                </td>
                                <td><?php echo $weekYear; ?></td>
                                <td><?php echo $dayName; ?></td>
                                <td class="<?php echo ($weekMonth != $saturdayMonth) ? 'error' : ''; ?>">
                                    <?php echo $saturdayDate->format('Y-m-d'); ?> (شهر <?php echo $saturdayMonth; ?>)
                                </td>
                                <td class="<?php echo ($sessionCount != 7) ? 'error' : 'success'; ?>">
                                    <?php echo $sessionCount; ?>
                                    <?php if (!empty($errors)): ?>
                                        <br><small class="error"><?php echo implode(', ', $errors); ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- الجلسات -->
        <div class="section">
            <h2>الجلسات (<?php echo count($allSessions); ?>)</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>الأسبوع ID</th>
                        <th>اليوم</th>
                        <th>التاريخ</th>
                        <th>نوع الجلسة</th>
                        <th>رجال - وقت</th>
                        <th>رجال - ملف</th>
                        <th>نساء - وقت</th>
                        <th>نساء - ملف</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($allSessions)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; color: red;">لا توجد جلسات</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($allSessions as $session): ?>
                            <tr>
                                <td><?php echo $session['id']; ?></td>
                                <td><?php echo $session['week_id']; ?></td>
                                <td><?php echo htmlspecialchars($session['day_name']); ?></td>
                                <td><?php echo $session['date']; ?></td>
                                <td><?php echo htmlspecialchars($session['session_type']); ?></td>
                                <td><?php echo htmlspecialchars($session['men_time'] ?: '-'); ?></td>
                                <td class="<?php echo !empty($session['men_image']) ? 'success' : ''; ?>">
                                    <?php 
                                    if (!empty($session['men_image'])) {
                                        $filePath = IMAGES_DIR . $session['men_image'];
                                        $exists = file_exists($filePath);
                                        echo htmlspecialchars($session['men_image']);
                                        echo $exists ? ' <span class="success">✓</span>' : ' <span class="error">✗ ملف غير موجود</span>';
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($session['women_time'] ?: '-'); ?></td>
                                <td class="<?php echo !empty($session['women_image']) ? 'success' : ''; ?>">
                                    <?php 
                                    if (!empty($session['women_image'])) {
                                        $filePath = IMAGES_DIR . $session['women_image'];
                                        $exists = file_exists($filePath);
                                        echo htmlspecialchars($session['women_image']);
                                        echo $exists ? ' <span class="success">✓</span>' : ' <span class="error">✗ ملف غير موجود</span>';
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- ملخص -->
        <div class="section">
            <h2>ملخص البيانات</h2>
            <table>
                <tr>
                    <th>عدد المكاتب</th>
                    <td><?php echo count($offices); ?></td>
                </tr>
                <tr>
                    <th>عدد الأسابيع</th>
                    <td><?php echo count($allWeeks); ?></td>
                </tr>
                <tr>
                    <th>عدد الجلسات</th>
                    <td><?php echo count($allSessions); ?></td>
                </tr>
                <tr>
                    <th>عدد الجلسات المتوقعة (7 لكل أسبوع)</th>
                    <td><?php echo count($allWeeks) * 7; ?></td>
                </tr>
                <tr>
                    <th>الفرق</th>
                    <td class="<?php echo (count($allSessions) != count($allWeeks) * 7) ? 'error' : 'success'; ?>">
                        <?php echo count($allSessions) - (count($allWeeks) * 7); ?>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>

