<?php
session_start();
include '../includes/config.php';
include '../includes/auth.php';
include '../includes/database.php';

redirectIfNotLoggedIn();
checkPermission('staff');

$database = new Database();
$db = $database->getConnection();

$message = '';

// التحقق من وجود رسالة نجاح في الجلسة
if (isset($_SESSION['success_message'])) {
    $message = '<div class="alert alert-success">' . $_SESSION['success_message'] . '</div>';
    unset($_SESSION['success_message']);
}

// تطبيق خصم
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'apply_discount') {
    $student_id = intval($_POST['student_id']);
    $fee_type_id = intval($_POST['fee_type_id']);
    $discount_type = $_POST['discount_type'];
    $discount_value = floatval($_POST['discount_value']);
    $reason = trim($_POST['reason']);
    
    // التحقق من البيانات
    if ($student_id > 0 && $fee_type_id > 0 && $discount_value > 0) {
        // جلب الرسوم الأصلية
        $query = "SELECT original_amount FROM student_fees WHERE student_id = :student_id AND fee_type_id = :fee_type_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':fee_type_id', $fee_type_id);
        $stmt->execute();
        $fee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($fee) {
            $original_amount = $fee['original_amount'];
            
            // حساب الخصم
            if ($discount_type == 'percentage') {
                $discount_amount = ($original_amount * $discount_value) / 100;
            } else {
                $discount_amount = $discount_value;
            }
            
            $final_amount = $original_amount - $discount_amount;
            
            // تسجيل الخصم
            $query = "INSERT INTO discounts (student_id, fee_type_id, discount_type, discount_value, reason, applied_by) 
                      VALUES (:student_id, :fee_type_id, :discount_type, :discount_value, :reason, :applied_by)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':student_id', $student_id);
            $stmt->bindParam(':fee_type_id', $fee_type_id);
            $stmt->bindParam(':discount_type', $discount_type);
            $stmt->bindParam(':discount_value', $discount_amount);
            $stmt->bindParam(':reason', $reason);
            $stmt->bindParam(':applied_by', $_SESSION['user_id']);
            
            if ($stmt->execute()) {
                // تحديث الرسوم النهائية
                $query = "UPDATE student_fees SET discount_amount = :discount_amount, final_amount = :final_amount 
                          WHERE student_id = :student_id AND fee_type_id = :fee_type_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':discount_amount', $discount_amount);
                $stmt->bindParam(':final_amount', $final_amount);
                $stmt->bindParam(':student_id', $student_id);
                $stmt->bindParam(':fee_type_id', $fee_type_id);
                $stmt->execute();
                
                // تخزين رسالة النجاح في الجلسة
                $_SESSION['success_message'] = 'تم تطبيق الخصم بنجاح';
                
                // إعادة توجيه لمنع إعادة إرسال النموذج
                header('Location: discounts.php');
                exit;
            } else {
                $message = '<div class="alert alert-danger">حدث خطأ في تطبيق الخصم</div>';
            }
        } else {
            $message = '<div class="alert alert-danger">لم يتم العثور على الرسوم المحددة</div>';
        }
    } else {
        $message = '<div class="alert alert-danger">الرجاء إدخال بيانات صحيحة</div>';
    }
}

// البحث عن الطلاب
$search = $_GET['search'] ?? '';
$stage_filter = $_GET['stage_filter'] ?? '';

// جلب المراحل التعليمية
$stages = $db->query("SELECT * FROM educational_stages WHERE is_active = 1 ORDER BY stage_name")->fetchAll(PDO::FETCH_ASSOC);

// بناء استعلام البحث للطلاب مع جلب الرسوم المتبقية
$query = "SELECT s.*, es.stage_name, g.grade_name,
          (SELECT COALESCE(SUM(sf.final_amount), 0) FROM student_fees sf WHERE sf.student_id = s.id) as total_fees,
          (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.student_id = s.id) as total_paid,
          ((SELECT COALESCE(SUM(sf.final_amount), 0) FROM student_fees sf WHERE sf.student_id = s.id) - 
           (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.student_id = s.id)) as remaining
          FROM students s 
          LEFT JOIN educational_stages es ON s.stage_id = es.id 
          LEFT JOIN grades g ON s.grade_id = g.id 
          WHERE s.is_active = 1";

$params = [];

if (!empty($search)) {
    $query .= " AND (s.full_name LIKE :search OR s.student_code LIKE :search OR s.parent_name LIKE :search OR s.parent_phone LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($stage_filter)) {
    $query .= " AND s.stage_id = :stage_id";
    $params[':stage_id'] = $stage_filter;
}

$query .= " ORDER BY s.full_name ASC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب أنواع الرسوم بناءً على المرحلة المحددة
$fee_types_query = "SELECT ft.*, es.stage_name 
                    FROM fee_types ft 
                    LEFT JOIN educational_stages es ON ft.stage_id = es.id 
                    WHERE ft.academic_year = '2024-2025' AND ft.is_active = 1";
if (!empty($stage_filter)) {
    $fee_types_query .= " AND ft.stage_id = :stage_id";
}

$fee_stmt = $db->prepare($fee_types_query);
if (!empty($stage_filter)) {
    $fee_stmt->bindParam(':stage_id', $stage_filter);
}
$fee_stmt->execute();
$fee_types = $fee_stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب آخر الخصومات مع إمكانية التصفية
$discounts_query = "SELECT d.*, s.full_name as student_name, f.fee_name, es.stage_name
          FROM discounts d 
          JOIN students s ON d.student_id = s.id 
          JOIN fee_types f ON d.fee_type_id = f.id 
          JOIN educational_stages es ON s.stage_id = es.id 
          WHERE 1=1";

$discount_params = [];

if (!empty($search)) {
    $discounts_query .= " AND (s.full_name LIKE :search OR s.student_code LIKE :search)";
    $discount_params[':search'] = "%$search%";
}

if (!empty($stage_filter)) {
    $discounts_query .= " AND s.stage_id = :stage_id";
    $discount_params[':stage_id'] = $stage_filter;
}

$discounts_query .= " ORDER BY d.created_at DESC LIMIT 10";

$discount_stmt = $db->prepare($discounts_query);
foreach ($discount_params as $key => $value) {
    $discount_stmt->bindValue($key, $value);
}
$discount_stmt->execute();
$recent_discounts = $discount_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تطبيق الخصومات - نظام المحاسبة المدرسية</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2c5aa0;
            --primary-dark: #1e3d72;
            --primary-light: #e8eff7;
            --secondary: #5a7fbd;
            --success: #2e7d32;
            --success-light: #e8f5e9;
            --danger: #c62828;
            --danger-light: #ffebee;
            --warning: #f57c00;
            --warning-light: #fff3e0;
            --info: #0277bd;
            --info-light: #e1f5fe;
            --light: #fafafa;
            --dark: #263238;
            --gray: #546e7a;
            --gray-light: #eceff1;
            --border-radius: 8px;
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 4px 16px rgba(0, 0, 0, 0.15);
            --transition: all 0.3s ease;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Tajawal', sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
        }
        
        .app-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* تصميم الشريط الجانبي الكلاسيكي */
        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            transition: var(--transition);
            box-shadow: var(--shadow-lg);
            z-index: 100;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 24px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(0, 0, 0, 0.1);
        }
        
        .logo {
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .logo i {
            font-size: 24px;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 20px 0;
        }
        
        .sidebar-menu li {
            margin-bottom: 4px;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            transition: var(--transition);
            font-weight: 500;
            border-left: 3px solid transparent;
        }
        
        .sidebar-menu a:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: rgba(255, 255, 255, 0.5);
        }
        
        .sidebar-menu a.active {
            background-color: rgba(255, 255, 255, 0.15);
            color: white;
        }
        
        /* تصميم المحتوى الرئيسي */
        .main-content {
            flex: 1;
            margin-right: 260px;
            transition: var(--transition);
        }
        
        .header {
            background-color: white;
            box-shadow: var(--shadow);
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 99;
        }
        
        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .user-details {
            text-align: left;
        }
        
        .user-name {
            font-weight: 600;
            color: var(--dark);
        }
        
        .user-role {
            font-size: 14px;
            color: var(--gray);
        }
        
        .logout-btn {
            background: var(--danger);
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .logout-btn:hover {
            background: #dc2626;
            transform: translateY(-2px);
        }
        
        /* تصميم المحتوى */
        .content {
            padding: 24px;
        }
        
        .card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 24px;
        }
        
        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-body {
            padding: 24px;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        
        @media (max-width: 1200px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* تصميم شريط البحث */
        .search-filters {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 20px;
            margin-bottom: 24px;
        }
        
        .search-form {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .search-input {
            flex: 1;
            min-width: 300px;
            padding: 12px 16px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 16px;
            transition: var(--transition);
            background: white;
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .filter-select {
            padding: 12px 16px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 16px;
            min-width: 200px;
            background: white;
            transition: var(--transition);
        }
        
        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .search-btn {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        
        /* تصميم النماذج */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            color: var(--dark);
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 16px;
            transition: var(--transition);
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .submit-btn {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            width: 100%;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 10px;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }
        
        /* معاينة الخصم */
        .discount-preview {
            background: var(--success-light);
            padding: 20px;
            border-radius: var(--border-radius);
            margin-top: 15px;
            display: none;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .preview-title {
            color: var(--success);
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .preview-item {
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
        }
        
        .preview-label {
            color: var(--gray);
            font-weight: 500;
        }
        
        .preview-value {
            font-weight: bold;
            color: var(--dark);
            font-size: 16px;
        }
        
        /* البحث عن الطلاب */
        .student-search {
            position: relative;
            margin-bottom: 10px;
        }
        
        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: var(--shadow);
        }
        
        .search-result-item {
            padding: 12px 15px;
            cursor: pointer;
            border-bottom: 1px solid var(--gray-light);
            transition: var(--transition);
        }
        
        .search-result-item:hover {
            background: var(--primary-light);
        }
        
        .student-info {
            display: flex;
            flex-direction: column;
        }
        
        .student-name {
            font-weight: 600;
            color: var(--dark);
        }
        
        .student-code {
            font-size: 12px;
            color: var(--gray);
        }
        
        .student-balance-display {
            color: var(--warning);
            font-size: 11px;
            margin-right: 5px;
        }
        
        /* جدول الخصومات */
        .discount-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .discount-table th {
            background: var(--primary);
            color: white;
            padding: 15px;
            text-align: right;
            font-weight: 600;
            font-size: 14px;
        }
        
        .discount-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--gray-light);
            text-align: right;
            font-size: 14px;
        }
        
        .discount-table tr:hover {
            background: var(--primary-light);
        }
        
        .discount-amount {
            color: var(--success);
            font-weight: bold;
        }
        
        /* التنبيهات */
        .alert {
            padding: 16px 20px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            font-weight: 500;
            border: 1px solid transparent;
        }
        
        .alert-success {
            background: var(--success-light);
            color: var(--success);
            border-color: rgba(16, 185, 129, 0.2);
        }
        
        .alert-danger {
            background: var(--danger-light);
            color: var(--danger);
            border-color: rgba(239, 68, 68, 0.2);
        }
        
        /* حالة فارغة */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .empty-state p {
            font-size: 16px;
        }
        
        /* زر القائمة للهواتف */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--primary);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            align-items: center;
            justify-content: center;
            z-index: 1001;
            box-shadow: var(--shadow);
            cursor: pointer;
        }
        
        /* تحسينات للهواتف المحمولة */
        @media (max-width: 768px) {
            .sidebar {
                width: 0;
                transform: translateX(100%);
                transition: transform 0.3s ease;
            }
            
            .sidebar.active {
                width: 260px;
                transform: translateX(0);
            }
            
            .main-content {
                margin-right: 0;
                width: 100%;
            }
            
            .mobile-menu-toggle {
                display: flex !important;
            }
            
            .header {
                padding: 12px 16px;
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .page-title {
                font-size: 20px;
            }
            
            .content {
                padding: 16px;
            }
            
            .search-form {
                flex-direction: column;
            }
            
            .search-input, .filter-select {
                width: 100%;
                min-width: auto;
            }
            
            .dashboard-grid {
                gap: 16px;
            }
            
            .user-info {
                flex-direction: column;
                gap: 10px;
            }
            
		@media (max-width: 768px) {
			.table-responsive {
				overflow-x: auto;
				-webkit-overflow-scrolling: touch;
				margin-bottom: 15px;
				border: 1px solid var(--gray-light);
				border-radius: var(--border-radius);
			}
			
			.discount-table {
				min-width: 600px;
				font-size: 12px;
				margin-bottom: 0;
			}
			
			.discount-table th,
			.discount-table td {
				padding: 8px 10px;
				white-space: nowrap;
			}
			
			.discount-table th:nth-child(5),
			.discount-table td:nth-child(5) {
				max-width: 120px;
				overflow: hidden;
				text-overflow: ellipsis;
			}
		}
        
        /* تحسينات للشاشات المتوسطة */
        @media (min-width: 769px) and (max-width: 1024px) {
            .sidebar {
                width: 80px;
            }
            
            .sidebar-header .logo-text,
            .sidebar-menu a span {
                display: none;
            }
            
            .sidebar-menu a {
                justify-content: center;
                padding: 16px;
            }
            
            .sidebar-menu a i {
                font-size: 20px;
            }
            
            .main-content {
                margin-right: 80px;
            }
            
            .sidebar:hover {
                width: 260px;
            }
            
            .sidebar:hover .logo-text,
            .sidebar:hover .sidebar-menu a span {
                display: inline;
            }
            
            .sidebar:hover .sidebar-menu a {
                justify-content: flex-start;
                padding: 14px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- زر القائمة للهواتف -->
        <div class="mobile-menu-toggle" id="mobileMenuToggle">
            <i class="fas fa-bars"></i>
        </div>
        
        <!-- الشريط الجانبي -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <i class="fas fa-graduation-cap"></i>
                    <span class="logo-text">النظام المدرسي</span>
                </div>
            </div>
            <ul class="sidebar-menu">
                <li><a href="dashboard.php"><i class="fas fa-tachometer-alt"></i><span>لوحة التحكم</span></a></li>
                <li><a href="students_management.php"><i class="fas fa-users"></i><span>إدارة الطلاب</span></a></li>
                <li><a href="payments.php"><i class="fas fa-credit-card"></i><span>تسجيل المدفوعات</span></a></li>
                <li><a href="discounts.php" class="active"><i class="fas fa-percentage"></i><span>تطبيق الخصومات</span></a></li>
                <li><a href="cash_box_management.php"><i class="fas fa-exchange-alt"></i><span>الإيرادات والمصروفات</span></a></li>
                <li><a href="../reports/index.php"><i class="fas fa-chart-bar"></i><span>التقارير</span></a></li>
                <li><a href="account_settings.php"><i class="fas fa-cog"></i><span>إعدادات الحساب</span></a></li>
            </ul>
        </div>
        
        <!-- المحتوى الرئيسي -->
        <div class="main-content">
            <div class="header">
                <h1 class="page-title">
                    <i class="fas fa-percentage"></i>
                    تطبيق الخصومات
                </h1>
                <div class="user-info">
                    <div class="user-details">
						<div class="user-role">hello back 👋</div>
                        <div class="user-name"><?php echo $_SESSION['full_name']; ?></div>
                                            </div>
                    <a href="../logout.php" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i>
                        تسجيل الخروج
                    </a>
                </div>
            </div>
            
            <div class="content">
                <?php echo $message; ?>
                
                <!-- شريط البحث والتصفية -->
                <div class="search-filters">
                    <form method="GET" action="" class="search-form">
                        <input type="text" name="search" class="search-input" placeholder="🔍 ابحث باسم الطالب أو الرقم أو الهاتف..." value="<?php echo htmlspecialchars($search); ?>">
                        
                        <select name="stage_filter" class="filter-select">
                            <option value="">جميع المراحل</option>
                            <?php foreach ($stages as $stage): ?>
                            <option value="<?php echo $stage['id']; ?>" <?php echo $stage_filter == $stage['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($stage['stage_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <button type="submit" class="search-btn">
                            <i class="fas fa-search"></i>
                            بحث
                        </button>
                        
                        <?php if (!empty($search) || !empty($stage_filter)): ?>
                            <a href="discounts.php" class="search-btn" style="background: linear-gradient(135deg, var(--gray), #475569);">
                                <i class="fas fa-list"></i>
                                عرض الكل
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
                
                <div class="dashboard-grid">
                    <!-- قسم تطبيق الخصم -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-tag"></i>
                                تطبيق خصم جديد
                            </h2>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="discountForm">
                                <input type="hidden" name="action" value="apply_discount">
                                
                                <div class="form-group">
                                    <label class="form-label">اختر الطالب</label>
                                    <div class="student-search">
                                        <input type="text" id="studentSearch" class="form-control" placeholder="ابحث عن الطالب...">
                                        <div id="searchResults" class="search-results"></div>
                                    </div>
                                    <select name="student_id" id="student_id" class="form-control" required onchange="getStudentFees(this.value)">
                                        <option value="">اختر الطالب</option>
                                        <?php foreach ($students as $student): ?>
                                        <option value="<?php echo $student['id']; ?>" data-stage-id="<?php echo $student['stage_id']; ?>" data-balance='<?php echo json_encode(['total_fees' => $student['total_fees'], 'total_paid' => $student['total_paid'], 'remaining' => $student['remaining']]); ?>'>
                                            <?php echo htmlspecialchars($student['full_name']) . ' - ' . $student['student_code'] . ' - ' . $student['stage_name']; ?>
                                            <span class="student-balance-display">
                                                (<?php echo number_format($student['remaining'], 2, '.', ',') . ' ' . CURRENCY . ' مستحق'; ?>)
                                            </span>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">نوع الرسوم</label>
                                    <select name="fee_type_id" id="fee_type_id" class="form-control" required onchange="calculateDiscount()">
                                        <option value="">اختر نوع الرسوم</option>
                                        <?php foreach ($fee_types as $fee): ?>
                                        <option value="<?php echo $fee['id']; ?>" data-amount="<?php echo $fee['amount']; ?>" data-stage-id="<?php echo $fee['stage_id']; ?>">
                                            <?php echo htmlspecialchars($fee['fee_name']) . ' - ' . number_format($fee['amount'], 2) . ' ' . CURRENCY; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">نوع الخصم</label>
                                    <select name="discount_type" id="discount_type" class="form-control" required onchange="calculateDiscount()">
                                        <option value="percentage">نسبة مئوية %</option>
                                        <option value="fixed">مبلغ ثابت</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">قيمة الخصم</label>
                                    <input type="number" name="discount_value" id="discount_value" class="form-control" 
                                           step="0.01" required min="0" oninput="calculateDiscount()" placeholder="أدخل قيمة الخصم">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">سبب الخصم</label>
                                    <textarea name="reason" class="form-control" rows="3" required 
                                              placeholder="سبب تطبيق الخصم (مثال: خصم أشقاء، خصم مبكر، إلخ)"></textarea>
                                </div>
                                
                                <div id="discountPreview" class="discount-preview">
                                    <div class="preview-title">
                                        <i class="fas fa-eye"></i>
                                        معاينة الخصم
                                    </div>
                                    <div class="preview-item">
                                        <span class="preview-label">المبلغ الأصلي:</span>
                                        <span class="preview-value" id="originalAmount">0</span> <?php echo CURRENCY; ?>
                                    </div>
                                    <div class="preview-item">
                                        <span class="preview-label">قيمة الخصم:</span>
                                        <span class="preview-value" id="discountAmount">0</span> <?php echo CURRENCY; ?>
                                    </div>
                                    <div class="preview-item">
                                        <span class="preview-label">المبلغ بعد الخصم:</span>
                                        <span class="preview-value" id="finalAmount">0</span> <?php echo CURRENCY; ?>
                                    </div>
                                </div>
                                
                                <button type="submit" class="submit-btn">
                                    <i class="fas fa-check-circle"></i>
                                    تطبيق الخصم
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- قسم آخر الخصومات -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-history"></i>
                                آخر الخصومات المطبقة
                                <?php if (!empty($search) || !empty($stage_filter)): ?>
                                    <small style="font-size: 14px; color: var(--gray);">(مصفى حسب البحث)</small>
                                <?php endif; ?>
                            </h2>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($recent_discounts)): ?>
                            <div class="table-responsive">
                                <table class="discount-table">
                                    <thead>
                                        <tr>
                                            <th>الطالب</th>
                                            <th>المرحلة</th>
                                            <th>نوع الرسوم</th>
                                            <th>الخصم</th>
                                            <th>السبب</th>
                                            <th>التاريخ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_discounts as $discount): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($discount['student_name']); ?></td>
                                            <td><?php echo htmlspecialchars($discount['stage_name']); ?></td>
                                            <td><?php echo htmlspecialchars($discount['fee_name']); ?></td>
                                            <td class="discount-amount">
                                                <?php echo number_format($discount['discount_value'], 2) . ' ' . CURRENCY; ?>
                                                <?php if ($discount['discount_type'] == 'percentage'): ?>
                                                    <small style="color: var(--gray); font-size: 12px;">(نسبة)</small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($discount['reason']); ?></td>
                                            <td><?php echo date('Y-m-d', strtotime($discount['created_at'])); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-percentage"></i>
                                <p>لا توجد خصومات مطبقة حالياً</p>
                                <?php if (!empty($search) || !empty($stage_filter)): ?>
                                    <p style="font-size: 14px; margin-top: 10px;">جرب مصطلحات بحث أخرى أو أعد ضبط الفلتر</p>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function calculateDiscount() {
        const feeSelect = document.getElementById('fee_type_id');
        const discountType = document.getElementById('discount_type').value;
        const discountValue = parseFloat(document.getElementById('discount_value').value) || 0;
        const selectedOption = feeSelect.options[feeSelect.selectedIndex];
        const originalAmount = parseFloat(selectedOption?.dataset.amount) || 0;
        
        const preview = document.getElementById('discountPreview');
        
        if (originalAmount > 0 && discountValue > 0) {
            let discountAmount = 0;
            
            if (discountType === 'percentage') {
                discountAmount = (originalAmount * discountValue) / 100;
                if (discountAmount > originalAmount) {
                    discountAmount = originalAmount;
                }
            } else {
                discountAmount = discountValue;
                if (discountAmount > originalAmount) {
                    discountAmount = originalAmount;
                    document.getElementById('discount_value').value = originalAmount;
                }
            }
            
            const finalAmount = originalAmount - discountAmount;
            
            document.getElementById('originalAmount').textContent = formatCurrency(originalAmount);
            document.getElementById('discountAmount').textContent = formatCurrency(discountAmount);
            document.getElementById('finalAmount').textContent = formatCurrency(finalAmount);
            preview.style.display = 'block';
        } else {
            preview.style.display = 'none';
        }
    }
    
    function getStudentFees(studentId) {
        if (!studentId) {
            document.getElementById('fee_type_id').value = '';
            calculateDiscount();
            return;
        }
        
        const studentSelect = document.getElementById('student_id');
        const selectedOption = studentSelect.querySelector(`option[value="${studentId}"]`);
        const stageId = selectedOption ? selectedOption.getAttribute('data-stage-id') : null;
        
        if (stageId) {
            const feeSelect = document.getElementById('fee_type_id');
            const feeOptions = feeSelect.querySelectorAll('option:not([value=""])');
            
            feeOptions.forEach(option => {
                if (option.getAttribute('data-stage-id') === stageId) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            });
            
            feeSelect.value = '';
        }
        
        calculateDiscount();
    }
    
    // البحث الديناميكي عن الطلاب
    document.addEventListener('DOMContentLoaded', function() {
        // إعداد زر القائمة للهواتف
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        
        mobileMenuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        });
        
        // إغلاق القائمة عند النقر خارجها
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                const isClickInsideSidebar = sidebar.contains(event.target);
                const isClickOnToggle = mobileMenuToggle.contains(event.target);
                
                if (!isClickInsideSidebar && !isClickOnToggle && sidebar.classList.contains('active')) {
                    sidebar.classList.remove('active');
                    document.body.style.overflow = '';
                }
            }
        });

        const studentSearch = document.getElementById('studentSearch');
        const searchResults = document.getElementById('searchResults');
        const studentSelect = document.getElementById('student_id');
        
        studentSearch.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const options = studentSelect.querySelectorAll('option[value]:not([value=""])');
            
            if (searchTerm.length < 2) {
                searchResults.style.display = 'none';
                return;
            }
            
            let resultsHTML = '';
            let hasResults = false;
            
            options.forEach(option => {
                if (option.textContent.toLowerCase().includes(searchTerm)) {
                    hasResults = true;
                    const balanceData = JSON.parse(option.getAttribute('data-balance'));
                    const remaining = parseFloat(balanceData.remaining || 0).toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                    
                    resultsHTML += `
                        <div class="search-result-item" data-value="${option.value}">
                            <div class="student-info">
                                <span class="student-name">${option.textContent.split(' - ')[0]}</span>
                                <div>
                                    <span class="student-code">${option.textContent.split(' - ')[1]} - ${option.textContent.split(' - ')[2]}</span>
                                    <span class="student-balance-display">
                                        (${remaining} <?php echo CURRENCY; ?> مستحق)
                                    </span>
                                </div>
                            </div>
                        </div>
                    `;
                }
            });
            
            if (hasResults) {
                searchResults.innerHTML = resultsHTML;
                searchResults.style.display = 'block';
            } else {
                searchResults.innerHTML = '<div class="search-result-item" style="color: var(--gray); text-align: center;">لا توجد نتائج</div>';
                searchResults.style.display = 'block';
            }
        });
        
        // اختيار طالب من نتائج البحث
        document.addEventListener('click', function(e) {
            const item = e.target.closest('.search-result-item');
            if (item) {
                const studentId = item.getAttribute('data-value');
                const studentSelect = document.getElementById('student_id');
                
                studentSelect.value = studentId;
                studentSearch.value = item.querySelector('.student-name').textContent;
                searchResults.style.display = 'none';
                
                getStudentFees(studentId);
            }
        });
        
        // إغلاق نتائج البحث عند النقر خارجها
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.student-search')) {
                searchResults.style.display = 'none';
            }
        });
        
        // معالجة اختيار الطالب من القائمة المنسدلة
        studentSelect.addEventListener('change', function() {
            if (this.value) {
                studentSearch.value = '';
                getStudentFees(this.value);
            }
        });
        
        // البحث الديناميكي في شريط البحث العلوي
        const searchInput = document.querySelector('input[name="search"]');
        const stageFilter = document.querySelector('select[name="stage_filter"]');
        let searchTimeout;
        
        function submitForm() {
            if (searchInput.value.length >= 2 || searchInput.value.length === 0 || stageFilter.value !== '') {
                document.querySelector('.search-form').submit();
            }
        }
        
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(submitForm, 500);
        });
        
        stageFilter.addEventListener('change', submitForm);
        
        // إضافة حدث لإعادة الحساب عند تغيير أي حقل
        const inputs = ['student_id', 'fee_type_id', 'discount_type', 'discount_value'];
        inputs.forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.addEventListener('change', calculateDiscount);
            }
        });
    });
    
    // دالة مساعدة لتنسيق الأرقام
    function formatCurrency(amount) {
        return parseFloat(amount).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    </script>
</body>
</html>