<?php
require_once 'config.php';

$message = '';
$messageType = '';

// معالجة إضافة مكتب جديد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_office') {
    $officeName = trim($_POST['office_name']);
    
    if (empty($officeName)) {
        $message = 'يجب إدخال اسم المكتب';
        $messageType = 'error';
    } else {
        $conn = getDBConnection();
        
        // التحقق من وجود مكتب بنفس الاسم
        $checkQuery = "SELECT id FROM offices WHERE name = ?";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("s", $officeName);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $message = 'المكتب موجود بالفعل!';
            $messageType = 'error';
            $stmt->close();
        } else {
            // إضافة المكتب الجديد
            $insertQuery = "INSERT INTO offices (name) VALUES (?)";
            $stmt = $conn->prepare($insertQuery);
            $stmt->bind_param("s", $officeName);
            
            if ($stmt->execute()) {
                $message = 'تم إضافة المكتب بنجاح!';
                $messageType = 'success';
            } else {
                $message = 'حدث خطأ أثناء إضافة المكتب';
                $messageType = 'error';
            }
            $stmt->close();
        }
    }
}

// جلب قائمة المكاتب
$conn = getDBConnection();
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
    <title>إضافة مكتب جديد - جدول الروضة</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-container {
            max-width: 800px;
            margin: 20px auto;
            padding: 20px;
        }
        .form-card {
            background: white;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #333;
            font-size: 16px;
        }
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #1a4d7a;
        }
        .btn-submit {
            background: #4caf50;
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 5px;
            font-size: 18px;
            cursor: pointer;
            width: 100%;
            margin-top: 10px;
            transition: background 0.3s;
        }
        .btn-submit:hover {
            background: #45a049;
        }
        .back-link {
            display: inline-block;
            color: #FFFFFF !important;
            background: #1a4d7a;
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            font-size: 16px;
            transition: background 0.3s;
            border: 2px solid rgba(255, 255, 255, 0.2);
        }
        .back-link:hover {
            background: #0d3a5f;
            text-decoration: none;
            border-color: rgba(255, 255, 255, 0.4);
        }
        .offices-list {
            background: #f9f9f9;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }
        .offices-list h3 {
            color: #1a4d7a;
            margin-top: 0;
        }
        .office-item {
            background: white;
            padding: 12px 15px;
            margin-bottom: 10px;
            border-radius: 5px;
            border: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .office-item:last-child {
            margin-bottom: 0;
        }
        .office-name {
            font-weight: bold;
            color: #333;
        }
        .delete-btn {
            background: #f44336;
            color: white;
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }
        .delete-btn:hover {
            background: #da190b;
        }
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            text-align: center;
            font-weight: bold;
        }
        .message.success {
            background: #4caf50;
            color: white;
        }
        .message.error {
            background: #f44336;
            color: white;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="index.php" class="back-link">← العودة للجدول</a>
            <a href="admin.php" class="back-link">➕ إضافة بيانات</a>
        </div>
        
        <div class="form-card">
            <h1 style="text-align: center; color: #1a4d7a; margin-bottom: 30px;">إضافة مكتب جديد</h1>
            
            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="action" value="add_office">
                
                <div class="form-group">
                    <label for="office_name">اسم المكتب:</label>
                    <input type="text" id="office_name" name="office_name" placeholder="أدخل اسم المكتب" required autofocus>
                </div>
                
                <button type="submit" class="btn-submit">إضافة المكتب</button>
            </form>
        </div>
        
        <div class="form-card offices-list">
            <h3>قائمة المكاتب (<?php echo count($offices); ?>)</h3>
            
            <?php if (empty($offices)): ?>
                <p style="text-align: center; color: #666; padding: 20px;">لا توجد مكاتب مضافة بعد</p>
            <?php else: ?>
                <?php foreach ($offices as $office): ?>
                    <div class="office-item">
                        <span class="office-name"><?php echo htmlspecialchars($office['name']); ?></span>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('هل أنت متأكد من حذف المكتب: <?php echo htmlspecialchars($office['name']); ?>؟');">
                            <input type="hidden" name="action" value="delete_office">
                            <input type="hidden" name="office_id" value="<?php echo $office['id']; ?>">
                            <button type="submit" class="delete-btn">حذف</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

<?php
// معالجة حذف مكتب
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_office') {
    $officeId = (int)$_POST['office_id'];
    
    if ($officeId > 0) {
        $conn = getDBConnection();
        
        // التحقق من وجود أسابيع مرتبطة بهذا المكتب
        $checkWeeksQuery = "SELECT COUNT(*) as count FROM weeks WHERE office_id = ?";
        $stmt = $conn->prepare($checkWeeksQuery);
        $stmt->bind_param("i", $officeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $weekCount = $result->fetch_assoc()['count'];
        $stmt->close();
        
        if ($weekCount > 0) {
            echo '<script>alert("لا يمكن حذف المكتب لأنه يحتوي على أسابيع مرتبطة به!"); window.location.href = "add_office.php";</script>';
        } else {
            $deleteQuery = "DELETE FROM offices WHERE id = ?";
            $stmt = $conn->prepare($deleteQuery);
            $stmt->bind_param("i", $officeId);
            
            if ($stmt->execute()) {
                echo '<script>alert("تم حذف المكتب بنجاح!"); window.location.href = "add_office.php";</script>';
            } else {
                echo '<script>alert("حدث خطأ أثناء حذف المكتب!"); window.location.href = "add_office.php";</script>';
            }
            $stmt->close();
        }
        
        $conn->close();
        exit;
    }
}
?>

