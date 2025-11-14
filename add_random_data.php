<?php
require_once 'config.php';

$message = '';
$messageType = '';

// جلب المكاتب
$conn = getDBConnection();
$officesQuery = "SELECT * FROM offices ORDER BY name";
$officesResult = $conn->query($officesQuery);
$offices = $officesResult->fetch_all(MYSQLI_ASSOC);

// إضافة بيانات عشوائية
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_random') {
    $officeId = (int)$_POST['office_id'];
    $month = (int)$_POST['month'];
    $year = (int)$_POST['year'];
    
    if ($officeId <= 0) {
        $message = 'يجب اختيار مكتب';
        $messageType = 'error';
    } else {
        // حساب تاريخ بداية الشهر
        $firstDay = new DateTime("$year-$month-01");
        $firstDayOfWeek = (int)$firstDay->format('w'); // 0 = الأحد
        
        // إضافة 4 أسابيع
        for ($weekNum = 1; $weekNum <= 4; $weekNum++) {
            // حساب تاريخ بداية كل أسبوع
            $daysToAdd = ($weekNum - 1) * 7 - $firstDayOfWeek;
            $weekStart = clone $firstDay;
            $weekStart->modify("+$daysToAdd days");
            $startDate = $weekStart->format('Y-m-d');
            
            // جلب آخر أسبوع للمكتب
            $lastWeekQuery = "SELECT week_number FROM weeks WHERE office_id = ? ORDER BY week_number DESC LIMIT 1";
            $stmt = $conn->prepare($lastWeekQuery);
            $stmt->bind_param("i", $officeId);
            $stmt->execute();
            $result = $stmt->get_result();
            $lastWeek = $result->fetch_assoc();
            $stmt->close();
            
            $newWeekNumber = $lastWeek ? $lastWeek['week_number'] + 1 : $weekNum;
            
            // إدراج الأسبوع
            $insertWeek = "INSERT INTO weeks (office_id, week_number, start_date) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($insertWeek);
            $stmt->bind_param("iis", $officeId, $newWeekNumber, $startDate);
            $stmt->execute();
            $weekId = $conn->insert_id;
            $stmt->close();
            
            // بيانات عشوائية
            $times = ['8:00', '9:00', '10:00', '11:00', '12:00', '5:00', '6:00', '7:00'];
            $days = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
            
            for ($i = 0; $i < 7; $i++) {
                $date = date('Y-m-d', strtotime($startDate . " +$i days"));
                $dayName = $days[$i];
                
                // اختيار عشوائي: رجال أو نساء
                $isMen = rand(0, 1) == 1;
                
                if ($isMen) {
                    $menTime = $times[array_rand($times)];
                    $menOffice = $offices[array_rand($offices)]['name'];
                    $womenTime = '';
                    $womenOffice = '';
                    $sessionType = 'men_only';
                    $menEnabled = 1;
                    $womenEnabled = 0;
                } else {
                    $menTime = '';
                    $menOffice = '';
                    $womenTime = $times[array_rand($times)];
                    $womenOffice = $offices[array_rand($offices)]['name'];
                    $sessionType = 'women_only';
                    $menEnabled = 0;
                    $womenEnabled = 1;
                }
                
                $insertSession = "INSERT INTO sessions (week_id, day_name, date, session_type, men_time, men_trainer, men_enabled, women_time, women_trainer, women_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($insertSession);
                // 10 معاملات: i, s, s, s, s, s, i, s, s, i
                $stmt->bind_param("isssssissi", 
                    $weekId,           // i (1)
                    $dayName,          // s (2)
                    $date,             // s (3)
                    $sessionType,      // s (4)
                    $menTime,          // s (5)
                    $menOffice,        // s (6)
                    $menEnabled,       // i (7)
                    $womenTime,        // s (8)
                    $womenOffice,      // s (9)
                    $womenEnabled      // i (10)
                );
                $stmt->execute();
                $stmt->close();
            }
        }
        
        $message = 'تم إضافة 4 أسابيع عشوائية بنجاح!';
        $messageType = 'success';
    }
}

// إعادة جلب المكاتب بعد إضافة البيانات
if (!isset($offices) || empty($offices)) {
    $officesQuery = "SELECT * FROM offices ORDER BY name";
    $officesResult = $conn->query($officesQuery);
    $offices = $officesResult->fetch_all(MYSQLI_ASSOC);
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إضافة بيانات عشوائية</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }
        .container {
            max-width: 600px;
            margin: 50px auto;
            background: white;
            border-radius: 15px;
            padding: 40px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .header {
            background: #1a4d7a;
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            text-align: center;
        }
        .header h1 { margin: 0; font-size: 28px; }
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            text-align: center;
        }
        .message.success { background: #4caf50; color: white; }
        .message.error { background: #f44336; color: white; }
        .form-group {
            margin-bottom: 25px;
        }
        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: bold;
            color: #333;
            font-size: 16px;
        }
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        .btn {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 18px;
            font-weight: bold;
            transition: all 0.3s;
        }
        .btn-primary {
            background: #1a4d7a;
            color: white;
        }
        .btn-primary:hover { background: #2a5a8a; }
        .info-box {
            background: #e3f2fd;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-right: 4px solid #1a4d7a;
        }
        .info-box h3 { color: #1a4d7a; margin-bottom: 10px; }
        .links {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #eee;
        }
        .links a {
            display: inline-block;
            margin: 10px;
            padding: 10px 20px;
            background: #4caf50;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }
        .links a:hover { background: #45a049; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎲 إضافة بيانات عشوائية</h1>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="info-box">
            <h3>📋 سيتم إضافة:</h3>
            <p>4 أسابيع عشوائية (الأول، الثاني، الثالث، الرابع) من الشهر المختار</p>
            <p>كل أسبوع يحتوي على 7 أيام مع بيانات عشوائية (رجال أو نساء)</p>
        </div>
        
        <form method="POST">
            <input type="hidden" name="action" value="add_random">
            
            <div class="form-group">
                <label>🏢 اختر المكتب:</label>
                <select name="office_id" required>
                    <option value="">اختر المكتب</option>
                    <?php foreach ($offices as $office): ?>
                        <option value="<?php echo $office['id']; ?>">
                            <?php echo htmlspecialchars($office['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>📅 اختر الشهر:</label>
                <select name="month" required>
                    <?php
                    $months = [
                        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
                        5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
                        9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
                    ];
                    $currentMonth = (int)date('n');
                    foreach ($months as $num => $name):
                    ?>
                        <option value="<?php echo $num; ?>" <?php echo ($num == $currentMonth) ? 'selected' : ''; ?>>
                            <?php echo $name; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>📅 اختر السنة:</label>
                <select name="year" required>
                    <?php
                    $currentYear = (int)date('Y');
                    for ($y = $currentYear; $y <= $currentYear + 1; $y++):
                    ?>
                        <option value="<?php echo $y; ?>" <?php echo ($y == $currentYear) ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <button type="submit" class="btn btn-primary">✅ إضافة البيانات العشوائية</button>
        </form>
        
        <div class="links">
            <a href="index.php">📊 عرض الجدول</a>
        </div>
    </div>
</body>
</html>

