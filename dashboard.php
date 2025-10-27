<?php
include '../includes/config.php';
include '../includes/auth.php';
include '../includes/database.php';

redirectIfNotLoggedIn();
checkPermission('staff');

$database = new Database();
$db = $database->getConnection();

// إحصائيات سريعة
$stats = [
    'total_students' => 0,
    'today_payments' => 0,
    'pending_fees' => 0,
    'total_discounts' => 0
];

// عدد الطلاب
$query = "SELECT COUNT(*) as total FROM students WHERE is_active = 1";
$stmt = $db->query($query);
$stats['total_students'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// مدفوعات اليوم
$query = "SELECT SUM(amount) as total FROM payments WHERE DATE(payment_date) = CURDATE()";
$stmt = $db->query($query);
$stats['today_payments'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// الرسوم المعلقة
$query = "SELECT COUNT(*) as total FROM student_fees WHERE status != 'paid'";
$stmt = $db->query($query);
$stats['pending_fees'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// إجمالي الخصومات
$query = "SELECT SUM(discount_value) as total FROM discounts";
$stmt = $db->query($query);
$stats['total_discounts'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// جلب آخر المدفوعات
$query = "SELECT p.*, s.full_name as student_name 
          FROM payments p 
          JOIN students s ON p.student_id = s.id 
          ORDER BY p.created_at DESC 
          LIMIT 5";
$recent_payments = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);

// جلب الطلاب الجدد
$query = "SELECT * FROM students WHERE is_active = 1 ORDER BY created_at DESC LIMIT 5";
$recent_students = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم - الموظف - نظام المحاسبة المدرسية</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --primary-light: #eef2ff;
            --secondary: #7209b7;
            --success: #10b981;
            --success-light: #d1fae5;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --info: #3b82f6;
            --info-light: #dbeafe;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray: #64748b;
            --gray-light: #e2e8f0;
            --border-radius: 12px;
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
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
        
        /* تصميم الشريط الجانبي */
        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            transition: var(--transition);
            box-shadow: var(--shadow);
            z-index: 100;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 24px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 12px;
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
        }
        
        .sidebar-menu a:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }
        
        .sidebar-menu a.active {
            background-color: rgba(255, 255, 255, 0.15);
            color: white;
            border-right: 4px solid white;
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
        
        .card {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
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
        
        /* تصميم بطاقات الإحصائيات */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 25px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.1);
            transform: rotate(30deg);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(67, 97, 238, 0.3);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            font-size: 24px;
            background: rgba(255, 255, 255, 0.2);
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }
        
        /* تصميم أزرار الإجراءات السريعة */
        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .action-btn {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
            padding: 18px 20px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
            text-align: center;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-weight: 600;
        }
        
        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);
        }
        
        .action-btn.secondary {
            background: linear-gradient(135deg, var(--info), #2563eb);
        }
        
        .action-btn.warning {
            background: linear-gradient(135deg, var(--warning), #d97706);
        }
        
        /* تصميم قوائم النشاط الحديث */
        .activity-list, .students-list {
            list-style: none;
        }
        
        .activity-item, .student-item {
            padding: 16px;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: var(--transition);
        }
        
        .activity-item:hover, .student-item:hover {
            background: var(--primary-light);
            border-radius: 8px;
        }
        
        .activity-item:last-child, .student-item:last-child {
            border-bottom: none;
        }
        
        .activity-info, .student-info {
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        
        .activity-title, .student-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 4px;
        }
        
        .activity-time, .student-date {
            color: var(--gray);
            font-size: 12px;
        }
        
        .activity-amount {
            font-weight: bold;
            color: var(--success);
            font-size: 16px;
        }
        
        .student-code {
            font-family: 'Courier New', monospace;
            background: var(--gray-light);
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            color: var(--dark);
        }
        
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
        
        .welcome-section {
            text-align: center;
            padding: 40px 20px;
            background: linear-gradient(135deg, var(--primary-light), #e0e7ff);
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            border: 1px solid var(--primary-light);
        }
        
        .welcome-section h1 {
            color: var(--dark);
            font-size: 32px;
            margin-bottom: 10px;
            font-weight: 700;
        }
        
        .welcome-section p {
            color: var(--gray);
            font-size: 18px;
            max-width: 600px;
            margin: 0 auto;
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
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                grid-template-columns: 1fr;
            }
            
            .dashboard-grid {
                gap: 16px;
            }
            
            .user-info {
                flex-direction: column;
                gap: 10px;
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
                <li><a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i><span>لوحة التحكم</span></a></li>
                <li><a href="students_management.php"><i class="fas fa-users"></i><span>إدارة الطلاب</span></a></li>
                <li><a href="payments.php"><i class="fas fa-credit-card"></i><span>تسجيل المدفوعات</span></a></li>
                <li><a href="discounts.php"><i class="fas fa-percentage"></i><span>تطبيق الخصومات</span></a></li>
                <li><a href="../reports/index.php"><i class="fas fa-chart-bar"></i><span>التقارير</span></a></li>
            </ul>
        </div>
        
        <!-- المحتوى الرئيسي -->
        <div class="main-content">
            <div class="header">
                <h1 class="page-title">
                    <i class="fas fa-tachometer-alt"></i>
                    لوحة التحكم
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
                <!-- قسم الترحيب -->
                <div class="welcome-section">
                    <h1>مرحباً بك في نظام المحاسبة المدرسية</h1>
                    <p>لوحة تحكم موظف الإدخال - يمكنك من هنا إدارة الطلاب والمدفوعات والتقارير</p>
                </div>
                
                <!-- إحصائيات سريعة -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon students">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-number"><?php echo $stats['total_students']; ?></div>
                        <div class="stat-label">إجمالي الطلاب</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon payments">
                            <i class="fas fa-credit-card"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['today_payments'], 2); ?> <?php echo CURRENCY; ?></div>
                        <div class="stat-label">مدفوعات اليوم</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon pending">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-number"><?php echo $stats['pending_fees']; ?></div>
                        <div class="stat-label">رسوم معلقة</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon discounts">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['total_discounts'], 2); ?> <?php echo CURRENCY; ?></div>
                        <div class="stat-label">إجمالي الخصومات</div>
                    </div>
                </div>
                
                <!-- الإجراءات السريعة -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-bolt"></i>
                            إجراءات سريعة
                        </h2>
                    </div>
                    <div class="card-body">
                        <div class="action-buttons">
                            <a href="students_management.php" class="action-btn">
                                <i class="fas fa-user-plus"></i>
                                إضافة طالب جديد
                            </a>
                            <a href="payments.php" class="action-btn secondary">
                                <i class="fas fa-money-bill-wave"></i>
                                تسجيل مدفوعات
                            </a>
                            <a href="discounts.php" class="action-btn warning">
                                <i class="fas fa-percentage"></i>
                                تطبيق خصم
                            </a>
                            <a href="reports.php" class="action-btn">
                                <i class="fas fa-chart-bar"></i>
                                عرض التقارير
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="dashboard-grid">
                    <!-- آخر المدفوعات -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-history"></i>
                                آخر المدفوعات
                            </h2>
                        </div>
                        <div class="card-body">
                            <ul class="activity-list">
                                <?php if (!empty($recent_payments)): ?>
                                    <?php foreach ($recent_payments as $payment): ?>
                                    <li class="activity-item">
                                        <div class="activity-info">
                                            <span class="activity-title"><?php echo htmlspecialchars($payment['student_name']); ?></span>
                                            <span class="activity-time"><?php echo date('Y-m-d H:i', strtotime($payment['created_at'])); ?></span>
                                        </div>
                                        <div class="activity-amount">
                                            <?php echo number_format($payment['amount'], 2) . ' ' . CURRENCY; ?>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <li class="empty-state">
                                        <i class="fas fa-receipt"></i>
                                        <p>لا توجد مدفوعات حديثة</p>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- أحدث الطلاب المسجلين -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-user-plus"></i>
                                أحدث الطلاب المسجلين
                            </h2>
                        </div>
                        <div class="card-body">
                            <ul class="students-list">
                                <?php if (!empty($recent_students)): ?>
                                    <?php foreach ($recent_students as $student): ?>
                                    <li class="student-item">
                                        <div class="student-info">
                                            <span class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></span>
                                            <span class="student-date"><?php echo date('Y-m-d', strtotime($student['created_at'])); ?></span>
                                        </div>
                                        <div class="student-code">
                                            <?php echo $student['student_code']; ?>
                                        </div>
                                    </li>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <li class="empty-state">
                                        <i class="fas fa-users"></i>
                                        <p>لا توجد طلاب مسجلين</p>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript للوظائف التفاعلية -->
    <script>
        // تهيئة الصفحة
        document.addEventListener('DOMContentLoaded', function() {
            // إعداد زر القائمة للهواتف
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const sidebar = document.getElementById('sidebar');
            
            mobileMenuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
                // إضافة أو إزالة منع التمرير عند فتح القائمة
                document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
            });
            
            // إغلاق القائمة عند النقر خارجها (للأجهزة المحمولة)
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
        });
    </script>
</body>
</html>