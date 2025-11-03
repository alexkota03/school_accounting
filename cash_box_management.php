<?php
include '../includes/config.php';
include '../includes/auth.php';
include '../includes/database.php';

redirectIfNotLoggedIn();
checkPermission('staff');

$database = new Database();
$db = $database->getConnection();

// التحقق من وجود العمود subcategory_id وإضافته إذا لم يكن موجوداً
function checkAndAddSubcategoryColumn($db) {
    try {
        // التحقق مما إذا كان العمود موجوداً
        $check_stmt = $db->query("SHOW COLUMNS FROM cash_box_transactions LIKE 'subcategory_id'");
        $column_exists = $check_stmt->fetch();
        
        if (!$column_exists) {
            // إضافة العمود إذا لم يكن موجوداً
            $db->exec("ALTER TABLE cash_box_transactions ADD COLUMN subcategory_id INT NULL AFTER created_by");
        }
        return true;
    } catch (Exception $e) {
        error_log("Error in checkAndAddSubcategoryColumn: " . $e->getMessage());
        return false;
    }
}

// دالة لإنشاء الجداول إذا لم تكن موجودة
function initializeCashBoxTables($db) {
    try {
        // إنشاء جدول cash_box إذا لم يكن موجوداً
        $db->exec("CREATE TABLE IF NOT EXISTS `cash_box` (
            `id` int NOT NULL AUTO_INCREMENT,
            `current_balance` decimal(10,2) NOT NULL DEFAULT '0.00',
            `last_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // التحقق من وجود سجل في cash_box
        $stmt = $db->query("SELECT COUNT(*) as count FROM cash_box");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] == 0) {
            $db->exec("INSERT INTO cash_box (current_balance) VALUES (0)");
        }
        
        // إنشاء جدول الحركات إذا لم يكن موجوداً
        $db->exec("CREATE TABLE IF NOT EXISTS `cash_box_transactions` (
            `id` int NOT NULL AUTO_INCREMENT,
            `transaction_type` enum('deposit','withdraw') NOT NULL,
            `amount` decimal(10,2) NOT NULL,
            `transaction_date` date NOT NULL,
            `description` text,
            `created_by` int DEFAULT NULL,
            `subcategory_id` int DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `subcategory_id` (`subcategory_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // إنشاء جدول الأقسام الرئيسية إذا لم يكن موجوداً
        $db->exec("CREATE TABLE IF NOT EXISTS `expense_categories` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
            `type` enum('expense','income') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'expense',
            `is_active` tinyint(1) NOT NULL DEFAULT '1',
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        
        // إنشاء جدول الأقسام الفرعية إذا لم يكن موجوداً
        $db->exec("CREATE TABLE IF NOT EXISTS `expense_subcategories` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `category_id` int(11) NOT NULL,
            `name` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
            `is_active` tinyint(1) NOT NULL DEFAULT '1',
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `category_id` (`category_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
        
        return true;
    } catch (Exception $e) {
        error_log("Error in initializeCashBoxTables: " . $e->getMessage());
        return false;
    }
}

// إنشاء الجداول إذا لم تكن موجودة
initializeCashBoxTables($db);

// التحقق من وجود العمود subcategory_id
checkAndAddSubcategoryColumn($db);

// جلب رصيد الخزينة الحالي
$current_balance = 0;
try {
    $stmt = $db->prepare("SELECT current_balance FROM cash_box WHERE id = 1");
    $stmt->execute();
    $cash_box = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cash_box) {
        $current_balance = $cash_box['current_balance'];
    }
} catch (PDOException $e) {
    $current_balance = 0;
}

// معالجة طلب الإيراد أو المصروف
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !isset($_POST['ajax_request'])) {
    $action = $_POST['action'];
    $amount = (float) ($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');
    $subcategory_id = ($action === 'withdraw' && isset($_POST['subcategory_id']) && !empty($_POST['subcategory_id'])) ? intval($_POST['subcategory_id']) : NULL;
    $created_by = $_SESSION['user_id'];
    
    // التحقق من صحة البيانات
    if ($amount <= 0) {
        $_SESSION['error_message'] = 'المبلغ يجب أن يكون أكبر من الصفر';
        header('Location: cash_box_management.php');
        exit;
    }
    
    if (empty($description)) {
        $_SESSION['error_message'] = 'يرجى إدخال وصف للحركة';
        header('Location: cash_box_management.php');
        exit;
    }
    
    if ($action === 'withdraw' && $current_balance < $amount) {
        $_SESSION['error_message'] = 'رصيد الخزينة غير كافي للمصروف';
        header('Location: cash_box_management.php');
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        // إدخال الحركة في جدول الحركات
        $stmt = $db->prepare("INSERT INTO cash_box_transactions (transaction_type, amount, transaction_date, description, created_by, subcategory_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$action, $amount, $transaction_date, $description, $created_by, $subcategory_id]);
        
        // تحديث رصيد الخزينة
        if ($action === 'deposit') {
            $new_balance = $current_balance + $amount;
        } else {
            $new_balance = $current_balance - $amount;
        }
        
        $stmt = $db->prepare("UPDATE cash_box SET current_balance = ? WHERE id = 1");
        $stmt->execute([$new_balance]);
        
        $db->commit();
        $_SESSION['success_message'] = ($action === 'deposit' ? 'تم تسجيل الإيراد بنجاح' : 'تم تسجيل المصروف بنجاح');
        header('Location: cash_box_management.php');
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error_message'] = 'حدث خطأ أثناء حفظ البيانات: ' . $e->getMessage();
        header('Location: cash_box_management.php');
        exit;
    }
}

// معالجة طلب AJAX للفلاتر
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_request'])) {
    $filter_type = $_POST['filter_type'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    
    // جلب البيانات المصفاة
    $transactions = [];
    $filters = [];

    try {
        $query = "SELECT cbt.*, u.full_name as created_by_name, esc.name as subcategory_name, ec.name as category_name, ec.type as category_type
                  FROM cash_box_transactions cbt 
                  LEFT JOIN users u ON cbt.created_by = u.id 
                  LEFT JOIN expense_subcategories esc ON cbt.subcategory_id = esc.id
                  LEFT JOIN expense_categories ec ON esc.category_id = ec.id
                  WHERE 1=1";

        if (!empty($filter_type)) {
            $query .= " AND cbt.transaction_type = :filter_type";
            $filters[':filter_type'] = $filter_type;
        }

        if (!empty($start_date)) {
            $query .= " AND cbt.transaction_date >= :start_date";
            $filters[':start_date'] = $start_date;
        }

        if (!empty($end_date)) {
            $query .= " AND cbt.transaction_date <= :end_date";
            $filters[':end_date'] = $end_date;
        }

        $query .= " ORDER BY cbt.transaction_date DESC, cbt.created_at DESC";

        $stmt = $db->prepare($query);
        $stmt->execute($filters);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // حساب الإجماليات
        $total_income = 0;
        $total_expenses = 0;
        foreach ($transactions as $transaction) {
            if ($transaction['transaction_type'] === 'deposit') {
                $total_income += $transaction['amount'];
            } else {
                $total_expenses += $transaction['amount'];
            }
        }
        
        // إرجاع HTML فقط - محسّن للاستخدام الديناميكي
        ob_start();
        ?>
        <!-- إحصائيات سريعة -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo number_format($current_balance, 2); ?> <?php echo CURRENCY; ?></div>
                    <div class="stat-label">الرصيد الحالي</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: var(--success);">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo number_format($total_income, 2); ?> <?php echo CURRENCY; ?></div>
                    <div class="stat-label">إجمالي الإيرادات</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: var(--danger);">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo number_format($total_expenses, 2); ?> <?php echo CURRENCY; ?></div>
                    <div class="stat-label">إجمالي المصروفات</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: var(--warning);">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-number"><?php echo count($transactions); ?></div>
                    <div class="stat-label">إجمالي الحركات</div>
                </div>
            </div>
        </div>
        
        <div id="transactionsContainer">
            <ul class="activity-list">
                <?php if (!empty($transactions)): ?>
                    <?php foreach ($transactions as $transaction): ?>
                    <li class="activity-item">
                        <div class="activity-info">
                            <span class="activity-title"><?php echo htmlspecialchars($transaction['description']); ?></span>
                            <span class="activity-time">
                                <?php echo date('Y-m-d', strtotime($transaction['transaction_date'])); ?> | 
                                بواسطة: <?php echo htmlspecialchars($transaction['created_by_name'] ?? 'غير معروف'); ?>
                                <?php if ($transaction['subcategory_name']): ?>
                                <br>
                                <span class="category-badge <?php echo ($transaction['category_type'] ?? 'expense') == 'income' ? 'income-badge' : 'expense-badge'; ?>">
                                    <?php echo htmlspecialchars($transaction['category_name'] ?? ''); ?> - 
                                    <?php echo htmlspecialchars($transaction['subcategory_name']); ?>
                                    (<?php echo ($transaction['category_type'] ?? 'expense') == 'income' ? 'إيراد' : 'مصروف'; ?>)
                                </span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span class="transaction-type type-<?php echo $transaction['transaction_type'] === 'deposit' ? 'income' : 'expense'; ?>">
                                <?php echo $transaction['transaction_type'] === 'deposit' ? 'إيراد' : 'مصروف'; ?>
                            </span>
                            <span class="activity-amount amount-<?php echo $transaction['transaction_type'] === 'deposit' ? 'income' : 'expense'; ?>">
                                <?php echo number_format($transaction['amount'], 2) . ' ' . CURRENCY; ?>
                            </span>
                        </div>
                    </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li class="empty-state">
                        <i class="fas fa-exchange-alt"></i>
                        <p>لا توجد حركات مالية</p>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
        
        <!-- ملخص الإيرادات والمصروفات -->
        <div class="balance-card">
            <div class="balance-icon">
                <i class="fas fa-wallet"></i>
            </div>
            <div class="balance-amount">
                <?php echo number_format($current_balance, 2); ?> <?php echo CURRENCY; ?>
            </div>
            <div class="balance-label">الرصيد الحالي</div>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 20px;">
            <div style="background: var(--success-light); padding: 15px; border-radius: var(--border-radius); text-align: center; border: 1px solid rgba(46, 125, 50, 0.2);">
                <div style="font-size: 20px; font-weight: bold; color: var(--success);">
                    <?php echo number_format($total_income, 2); ?>
                </div>
                <div style="font-size: 12px; color: var(--gray);">إجمالي الإيرادات</div>
            </div>
            <div style="background: var(--danger-light); padding: 15px; border-radius: var(--border-radius); text-align: center; border: 1px solid rgba(198, 40, 40, 0.2);">
                <div style="font-size: 20px; font-weight: bold; color: var(--danger);">
                    <?php echo number_format($total_expenses, 2); ?>
                </div>
                <div style="font-size: 12px; color: var(--gray);">إجمالي المصروفات</div>
            </div>
        </div>
        <?php
        $output = ob_get_clean();
        echo $output;
        exit;
    } catch (PDOException $e) {
        error_log("Error fetching filtered transactions: " . $e->getMessage());
        echo '<div class="alert alert-danger">حدث خطأ أثناء جلب البيانات</div>';
        exit;
    }
}

// جلب سجل الحركات (للحملة الأولى)
$transactions = [];
$filters = [];

$filter_type = $_GET['filter_type'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

try {
    $query = "SELECT cbt.*, u.full_name as created_by_name, esc.name as subcategory_name, ec.name as category_name, ec.type as category_type
              FROM cash_box_transactions cbt 
              LEFT JOIN users u ON cbt.created_by = u.id 
              LEFT JOIN expense_subcategories esc ON cbt.subcategory_id = esc.id
              LEFT JOIN expense_categories ec ON esc.category_id = ec.id
              WHERE 1=1";

    if (!empty($filter_type)) {
        $query .= " AND cbt.transaction_type = :filter_type";
        $filters[':filter_type'] = $filter_type;
    }

    if (!empty($start_date)) {
        $query .= " AND cbt.transaction_date >= :start_date";
        $filters[':start_date'] = $start_date;
    }

    if (!empty($end_date)) {
        $query .= " AND cbt.transaction_date <= :end_date";
        $filters[':end_date'] = $end_date;
    }

    $query .= " ORDER BY cbt.transaction_date DESC, cbt.created_at DESC";

    $stmt = $db->prepare($query);
    $stmt->execute($filters);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching transactions: " . $e->getMessage());
}

// جلب الأقسام الرئيسية (المصروفات والإيرادات)
$expense_categories = $db->query("SELECT * FROM expense_categories WHERE is_active = 1 ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);

// حساب الإجماليات
$total_income = 0;
$total_expenses = 0;
foreach ($transactions as $transaction) {
    if ($transaction['transaction_type'] === 'deposit') {
        $total_income += $transaction['amount'];
    } else {
        $total_expenses += $transaction['amount'];
    }
}

// جلب الرسائل من الجلسة
$message = '';
if (isset($_SESSION['success_message'])) {
    $message = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . $_SESSION['success_message'] . '</div>';
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $message = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> ' . $_SESSION['error_message'] . '</div>';
    unset($_SESSION['error_message']);
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الإيرادات والمصروفات - نظام المحاسبة المدرسية</title>
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
        
        /* تصميم الشريط الجانبي - مخفي افتراضيًا */
        .sidebar {
            width: 80px;
            background: linear-gradient(180deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            transition: var(--transition);
            box-shadow: var(--shadow-lg);
            z-index: 100;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            overflow-x: hidden;
        }
        
        /* حالة القائمة المفتوحة */
        .sidebar.open {
            width: 260px;
        }
        
        .sidebar-header {
            padding: 24px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(0, 0, 0, 0.1);
            white-space: nowrap;
            overflow: hidden;
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
        
        .logo-text {
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .sidebar.open .logo-text {
            opacity: 1;
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
            white-space: nowrap;
        }
        
        .sidebar-menu a span {
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .sidebar.open .sidebar-menu a span {
            opacity: 1;
        }
        
        .sidebar-menu a:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: rgba(255, 255, 255, 0.5);
        }
        
        .sidebar-menu a.active {
            background-color: rgba(255, 255, 255, 0.15);
            color: white;
            border-left-color: white;
        }
		
		        /* زر فتح/إغلاق القائمة */
        .sidebar-toggle-btn {
            position: absolute;
            top: 20px;
            left: 20px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            transition: var(--transition);
            z-index: 102;
        }
        
        .sidebar:hover .sidebar-toggle-btn,
        .sidebar.open .sidebar-toggle-btn {
            opacity: 1;
        }
        
		
        
        /* تصميم المحتوى الرئيسي */
        .main-content {
            flex: 1;
            margin-right: 80px;
            transition: var(--transition);
        }
		
        .sidebar.open ~ .main-content {
            margin-right: 260px;
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
        
        /* تصميم الأزرار الكلاسيكي المحسن */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 14px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
            border: 1px solid var(--primary-dark);
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
        }
        
        .btn-success {
            background: var(--success);
            color: white;
            border: 1px solid #1b5e20;
        }
        
        .btn-success:hover {
            background: #1b5e20;
            transform: translateY(-1px);
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
            border: 1px solid #b71c1c;
        }
        
        .btn-danger:hover {
            background: #b71c1c;
            transform: translateY(-1px);
        }
        
        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
        }
        
        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .btn-lg {
            padding: 12px 24px;
            font-size: 16px;
        }
        
        .btn-block {
            width: 100%;
            justify-content: center;
        }
        
        .logout-btn {
            background: #c62828;
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
        
        .content {
            padding: 24px;
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
        
        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--light);
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
            padding: 10px 12px;
            border: 1px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 14px;
            transition: var(--transition);
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(44, 90, 160, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
        .table th {
            background: var(--primary);
            color: white;
            padding: 12px 15px;
            text-align: right;
            font-weight: 600;
            font-size: 14px;
        }
        
        .table td {
            padding: 10px 15px;
            border-bottom: 1px solid var(--gray-light);
            text-align: right;
            font-size: 14px;
        }
        
        .table tr:hover {
            background: var(--primary-light);
        }
        
        .badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-success {
            background: var(--success-light);
            color: var(--success);
            border: 1px solid rgba(46, 125, 50, 0.2);
        }
        
        .badge-warning {
            background: var(--warning-light);
            color: var(--warning);
            border: 1px solid rgba(245, 124, 0, 0.2);
        }
        
        .badge-info {
            background: var(--info-light);
            color: var(--info);
            border: 1px solid rgba(2, 119, 189, 0.2);
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            font-weight: 500;
            border: 1px solid transparent;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: var(--success-light);
            color: var(--success);
            border-color: rgba(46, 125, 50, 0.2);
        }
        
        .alert-danger {
            background: var(--danger-light);
            color: var(--danger);
            border-color: rgba(198, 40, 40, 0.2);
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        @media (max-width: 1200px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
        }
        
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--primary);
            color: white;
            width: 44px;
            height: 44px;
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
        padding: 12px 15px;
        flex-direction: column;
        gap: 12px;
        text-align: center;
        min-height: auto;
    }
    
    .page-title {
        font-size: 18px;
        gap: 8px;
        margin-bottom: 0;
    }
    
    .page-title i {
        font-size: 20px;
    }
    
    .user-info {
        flex-direction: column;
        gap: 8px;
        width: 100%;
    }
    
    .user-details {
        text-align: center;
        width: 100%;
    }
    
    .user-name {
        font-size: 14px;
    }
    
    .user-role {
        font-size: 12px;
    }
    
    .logout-btn {
        padding: 5px 10px;
        font-size: 12px;
        width: 100%;
        justify-content: center;
    }
    
    .content {
        padding: 16px;
    }
    
    .table-responsive {
        overflow-x: auto;
    }
    
    .table {
        min-width: 800px;
    }
}

@media (max-width: 480px) {
    .header {
        padding: 10px 12px;
        gap: 8px;
    }
    
    .page-title {
        font-size: 16px;
    }
    
    .mobile-menu-toggle {
        top: 12px;
        right: 12px;
        width: 40px;
        height: 40px;
        font-size: 16px;
    }
    
    .content {
        padding: 12px 8px;
    }
}
        
        /* تصميم البطاقات الإحصائية */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 24px;
            box-shadow: var(--shadow);
            transition: var(--transition);
            border-top: 4px solid var(--primary);
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            background: var(--primary);
        }
        
        .stat-content {
            flex: 1;
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--gray);
        }
        
        /* تصميم خاص للخزينة */
        .balance-card {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border-radius: var(--border-radius);
            padding: 30px;
            text-align: center;
            box-shadow: var(--shadow-lg);
        }
        
        .balance-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        
        .balance-amount {
            font-size: 42px;
            font-weight: 800;
            margin-bottom: 8px;
        }
        
        .balance-label {
            font-size: 16px;
            opacity: 0.9;
        }
        
        /* تصميم قوائم النشاط */
        .activity-list {
            list-style: none;
        }
        
        .activity-item {
            padding: 16px;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: var(--transition);
        }
        
        .activity-item:hover {
            background: var(--primary-light);
            border-radius: 8px;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-info {
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        
        .activity-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 4px;
        }
        
        .activity-time {
            color: var(--gray);
            font-size: 12px;
        }
        
        .activity-amount {
            font-weight: bold;
            font-size: 16px;
        }
        
        .amount-income {
            color: var(--success);
        }
        
        .amount-expense {
            color: var(--danger);
        }
        
        .transaction-type {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .type-income {
            background: var(--success-light);
            color: var(--success);
        }
        
        .type-expense {
            background: var(--danger-light);
            color: var(--danger);
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
            padding: 30px 20px;
            background: var(--primary-light);
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            border: 1px solid var(--primary-light);
        }
        
        .welcome-section h1 {
            color: var(--dark);
            font-size: 28px;
            margin-bottom: 10px;
            font-weight: 700;
        }
        
        .welcome-section p {
            color: var(--gray);
            font-size: 16px;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .category-badge {
            background: var(--info-light);
            color: var(--info);
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-top: 4px;
            display: inline-block;
        }
        
        .income-badge {
            background: var(--success-light);
            color: var(--success);
        }
        
        .expense-badge {
            background: var(--warning-light);
            color: var(--warning);
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.95);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            flex-direction: column;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary);
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* تحسينات للفلاتر */
        .filter-section {
            transition: all 0.3s ease;
        }
        
        .filter-loading {
            opacity: 0.7;
            pointer-events: none;
        }
        
        .btn-loading {
            position: relative;
            color: transparent !important;
        }
        
        .btn-loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            top: 50%;
            left: 50%;
            margin: -8px 0 0 -8px;
            border: 2px solid #ffffff;
            border-radius: 50%;
            border-right-color: transparent;
            animation: spin 1s linear infinite;
        }
        
        /* تحسينات للرسوم المتحركة */
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .slide-up {
            animation: slideUp 0.3s ease-out;
        }
        
        @keyframes slideUp {
            from { 
                opacity: 0;
                transform: translateY(10px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* تحسينات للاستجابة الديناميكية */
        .dynamic-content {
            transition: all 0.3s ease;
        }
        
        .stats-updating {
            opacity: 0.7;
        }
        
        .transactions-updating {
            position: relative;
        }
        
        .transactions-updating::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
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
				
		            <!-- زر فتح/إغلاق القائمة -->
            <button class="sidebar-toggle-btn" id="sidebarToggleBtn">
                <i class="fas fa-chevron-right" id="sidebarToggleIcon"></i>
            </button>
			
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
                <li><a href="discounts.php"><i class="fas fa-percentage"></i><span>تطبيق الخصومات</span></a></li>
                <li><a href="cash_box_management.php" class="active"><i class="fas fa-exchange-alt"></i><span>الإيرادات والمصروفات</span></a></li>
                <li><a href="../reports/index.php"><i class="fas fa-chart-bar"></i><span>التقارير</span></a></li>
                <li><a href="account_settings.php"><i class="fas fa-cog"></i><span>إعدادات الحساب</span></a></li>
            </ul>
        </div>
        
        <!-- المحتوى الرئيسي -->
        <div class="main-content">
            <div class="header">
                <h1 class="page-title">
                    <i class="fas fa-exchange-alt"></i>
                    الإيرادات والمصروفات
                </h1>
                <div class="user-info">
                    <div class="user-details">
                        <div class="user-role">مرحباً بعودتك 👋</div>
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
                
                <!-- قسم الترحيب -->
                <div class="welcome-section">
                    <h1>إدارة الإيرادات والمصروفات</h1>
                    <p>يمكنك من هنا تسجيل الإيرادات والمصروفات المدرسية ومتابعة جميع الحركات المالية</p>
                </div>
                
                <!-- إحصائيات سريعة -->
                <div class="stats-grid" id="statsGrid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($current_balance, 2); ?> <?php echo CURRENCY; ?></div>
                            <div class="stat-label">الرصيد الحالي</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: var(--success);">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($total_income, 2); ?> <?php echo CURRENCY; ?></div>
                            <div class="stat-label">إجمالي الإيرادات</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: var(--danger);">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($total_expenses, 2); ?> <?php echo CURRENCY; ?></div>
                            <div class="stat-label">إجمالي المصروفات</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: var(--warning);">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo count($transactions); ?></div>
                            <div class="stat-label">إجمالي الحركات</div>
                        </div>
                    </div>
                </div>
                
                <!-- نموذج الإيراد والمصروف -->
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <i class="fas fa-exchange-alt"></i>
                            حركة جديدة
                        </h2>
                    </div>
                    <div class="card-body">
                        <form id="transactionForm" method="POST" onsubmit="return validateForm()">
                            <input type="hidden" name="action" id="actionInput" value="deposit">
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">نوع الحركة</label>
                                    <select name="action" id="actionSelect" class="form-control" required onchange="updateForm()">
                                        <option value="deposit">إيراد</option>
                                        <option value="withdraw">مصروف</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">تاريخ الحركة</label>
                                    <input type="date" name="transaction_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">المبلغ (<?php echo CURRENCY; ?>)</label>
                                <input type="number" name="amount" id="amount" class="form-control" step="0.01" min="0.01" placeholder="0.00" required>
                            </div>
                            
                            <!-- حقول الأقسام (تظهر فقط للمصروفات) -->
                            <div class="form-group" id="categoryField" style="display: none;">
                                <label class="form-label">القسم الرئيسي</label>
                                <select name="category_id" id="category_id" class="form-control" onchange="loadSubcategories(this.value)">
                                    <option value="">اختر القسم الرئيسي</option>
                                    <?php foreach ($expense_categories as $category): ?>
                                    <?php if ($category['type'] == 'expense'): ?>
                                    <option value="<?php echo $category['id']; ?>">
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group" id="subcategoryField" style="display: none;">
                                <label class="form-label">القسم الفرعي</label>
                                <select name="subcategory_id" id="subcategory_id" class="form-control">
                                    <option value="">اختر القسم الفرعي</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">الوصف</label>
                                <textarea name="description" id="description" class="form-control" rows="3" placeholder="وصف الحركة..." required></textarea>
                            </div>
                            <div class="form-group">
                                <button type="submit" id="submitTransactionBtn" class="btn btn-primary btn-block btn-lg">
                                    <i class="fas fa-plus-circle"></i>
                                    تسجيل إيراد
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="grid-2">
                    <!-- سجل الحركات -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-history"></i>
                                سجل حركات الإيرادات والمصروفات
                            </h2>
                        </div>
                        <div class="card-body">
                            <!-- فلتر السجل -->
                            <form id="filterForm" method="GET" class="form-row" style="margin-bottom: 20px;">
                                <div class="form-group">
                                    <select name="filter_type" id="filter_type" class="form-control">
                                        <option value="">جميع الأنواع</option>
                                        <option value="deposit" <?php echo $filter_type === 'deposit' ? 'selected' : ''; ?>>إيرادات</option>
                                        <option value="withdraw" <?php echo $filter_type === 'withdraw' ? 'selected' : ''; ?>>مصروفات</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <input type="date" name="start_date" id="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>" placeholder="من تاريخ">
                                </div>
                                <div class="form-group">
                                    <input type="date" name="end_date" id="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>" placeholder="إلى تاريخ">
                                </div>
                                <div class="form-group">
                                    <button type="button" id="filterBtn" class="btn btn-outline btn-block">
                                        <i class="fas fa-search"></i>
                                        بحث
                                    </button>
                                </div>
                            </form>
                            
                            <div id="transactionsContainer">
                                <ul class="activity-list">
                                    <?php if (!empty($transactions)): ?>
                                        <?php foreach ($transactions as $transaction): ?>
                                        <li class="activity-item">
                                            <div class="activity-info">
                                                <span class="activity-title"><?php echo htmlspecialchars($transaction['description']); ?></span>
                                                <span class="activity-time">
                                                    <?php echo date('Y-m-d', strtotime($transaction['transaction_date'])); ?> | 
                                                    بواسطة: <?php echo htmlspecialchars($transaction['created_by_name'] ?? 'غير معروف'); ?>
                                                    <?php if ($transaction['subcategory_name']): ?>
                                                    <br>
                                                    <span class="category-badge <?php echo ($transaction['category_type'] ?? 'expense') == 'income' ? 'income-badge' : 'expense-badge'; ?>">
                                                        <?php echo htmlspecialchars($transaction['category_name'] ?? ''); ?> - 
                                                        <?php echo htmlspecialchars($transaction['subcategory_name']); ?>
                                                        (<?php echo ($transaction['category_type'] ?? 'expense') == 'income' ? 'إيراد' : 'مصروف'; ?>)
                                                    </span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <span class="transaction-type type-<?php echo $transaction['transaction_type'] === 'deposit' ? 'income' : 'expense'; ?>">
                                                    <?php echo $transaction['transaction_type'] === 'deposit' ? 'إيراد' : 'مصروف'; ?>
                                                </span>
                                                <span class="activity-amount amount-<?php echo $transaction['transaction_type'] === 'deposit' ? 'income' : 'expense'; ?>">
                                                    <?php echo number_format($transaction['amount'], 2) . ' ' . CURRENCY; ?>
                                                </span>
                                            </div>
                                        </li>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <li class="empty-state">
                                            <i class="fas fa-exchange-alt"></i>
                                            <p>لا توجد حركات مالية</p>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ملخص الإيرادات والمصروفات -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-chart-pie"></i>
                                ملخص الإيرادات والمصروفات
                            </h2>
                        </div>
                        <div class="card-body" id="summaryContainer">
                            <div class="balance-card">
                                <div class="balance-icon">
                                    <i class="fas fa-wallet"></i>
                                </div>
                                <div class="balance-amount">
                                    <?php echo number_format($current_balance, 2); ?> <?php echo CURRENCY; ?>
                                </div>
                                <div class="balance-label">الرصيد الحالي</div>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 20px;">
                                <div style="background: var(--success-light); padding: 15px; border-radius: var(--border-radius); text-align: center; border: 1px solid rgba(46, 125, 50, 0.2);">
                                    <div style="font-size: 20px; font-weight: bold; color: var(--success);">
                                        <?php echo number_format($total_income, 2); ?>
                                    </div>
                                    <div style="font-size: 12px; color: var(--gray);">إجمالي الإيرادات</div>
                                </div>
                                <div style="background: var(--danger-light); padding: 15px; border-radius: var(--border-radius); text-align: center; border: 1px solid rgba(198, 40, 40, 0.2);">
                                    <div style="font-size: 20px; font-weight: bold; color: var(--danger);">
                                        <?php echo number_format($total_expenses, 2); ?>
                                    </div>
                                    <div style="font-size: 12px; color: var(--gray);">إجمالي المصروفات</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- نافذة التحميل -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
        <p>جاري معالجة الطلب...</p>
    </div>

    <!-- JavaScript للوظائف التفاعلية -->
    <script>
        // دالة لتحديث النموذج حسب نوع الحركة
        function updateForm() {
            const actionSelect = document.getElementById('actionSelect');
            const submitBtn = document.getElementById('submitTransactionBtn');
            const categoryField = document.getElementById('categoryField');
            const subcategoryField = document.getElementById('subcategoryField');
            
            if (actionSelect.value === 'deposit') {
                submitBtn.innerHTML = '<i class="fas fa-plus-circle"></i> تسجيل إيراد';
                submitBtn.className = 'btn btn-success btn-block btn-lg';
                categoryField.style.display = 'none';
                subcategoryField.style.display = 'none';
                // إعادة تعيين القسم الفرعي
                document.getElementById('subcategory_id').innerHTML = '<option value="">اختر القسم الفرعي</option>';
            } else {
                submitBtn.innerHTML = '<i class="fas fa-minus-circle"></i> تسجيل مصروف';
                submitBtn.className = 'btn btn-danger btn-block btn-lg';
                categoryField.style.display = 'block';
            }
        }

        // دالة لتحميل الأقسام الفرعية
		function loadSubcategories(categoryId) {
			const subcategorySelect = document.getElementById('subcategory_id');
			const subcategoryField = document.getElementById('subcategoryField');
			
			if (!categoryId) {
				subcategorySelect.innerHTML = '<option value="">اختر القسم الفرعي</option>';
				subcategoryField.style.display = 'none';
				return;
			}
			
			// جلب الأقسام الفرعية عبر AJAX
			fetch('get_subcategories.php?category_id=' + categoryId)
				.then(response => {
					if (!response.ok) {
						throw new Error('Network response was not ok');
					}
					return response.json();
				})
				.then(data => {
					if (data && data.length > 0) {
						let options = '<option value="">اختر القسم الفرعي</option>';
						data.forEach(sub => {
							options += `<option value="${sub.id}">${sub.name}</option>`;
						});
						subcategorySelect.innerHTML = options;
						subcategoryField.style.display = 'block';
					} else {
						subcategorySelect.innerHTML = '<option value="">لا توجد أقسام فرعية</option>';
						subcategoryField.style.display = 'block';
					}
				})
				.catch(error => {
					console.error('Error:', error);
					subcategorySelect.innerHTML = '<option value="">خطأ في التحميل</option>';
					subcategoryField.style.display = 'block';
				});
		}

        // دالة للتحقق من صحة النموذج
        function validateForm() {
            const action = document.getElementById('actionSelect').value;
            const amount = parseFloat(document.getElementById('amount').value);
            const description = document.getElementById('description').value.trim();
            const subcategory_id = document.getElementById('subcategory_id')?.value;

            // التحقق من صحة البيانات
            if (isNaN(amount) || amount <= 0) {
                alert('يرجى إدخال مبلغ صحيح أكبر من الصفر');
                return false;
            }

            if (!description) {
                alert('يرجى إدخال وصف للحركة');
                return false;
            }

            if (action === 'withdraw' && amount > <?php echo $current_balance; ?>) {
                alert('رصيد الخزينة غير كافي للمصروف');
                return false;
            }

            if (action === 'withdraw' && (!subcategory_id || subcategory_id === '')) {
                alert('يرجى اختيار قسم فرعي للمصروف');
                return false;
            }

            showLoading();
            return true;
        }

        // دالة لإظهار نافذة التحميل
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }

        // دالة لإخفاء نافذة التحميل
        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }

        // دالة محسّنة لجلب البيانات المصفاة
        function fetchFilteredTransactions() {
            const filterType = document.getElementById('filter_type').value;
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            
            // إظهار نافذة التحميل
            document.getElementById('loadingOverlay').style.display = 'flex';
            
            // إضافة تأثير التحميل على العناصر
            document.getElementById('statsGrid').classList.add('stats-updating');
            document.getElementById('transactionsContainer').classList.add('transactions-updating');
            
            // إعداد البيانات للإرسال
            const formData = new FormData();
            formData.append('filter_type', filterType);
            formData.append('start_date', startDate);
            formData.append('end_date', endDate);
            formData.append('ajax_request', 'true');
            
            // إرسال طلب AJAX
            fetch('cash_box_management.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text();
            })
            .then(html => {
                // استخراج جميع الأقسام من الرد
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                
                // تحديث الشبكة الإحصائية
                const statsGrid = doc.querySelector('.stats-grid');
                if (statsGrid) {
                    document.getElementById('statsGrid').innerHTML = statsGrid.innerHTML;
                    document.getElementById('statsGrid').classList.add('fade-in');
                }
                
                // تحديث قائمة الحركات
                const transactionsContainer = doc.querySelector('#transactionsContainer');
                if (transactionsContainer) {
                    document.getElementById('transactionsContainer').innerHTML = transactionsContainer.innerHTML;
                    document.getElementById('transactionsContainer').classList.add('slide-up');
                }
                
                // تحديث ملخص الإيرادات والمصروفات
                const summaryContainer = doc.querySelector('.grid-2 .card:last-child .card-body');
                if (summaryContainer) {
                    document.getElementById('summaryContainer').innerHTML = summaryContainer.innerHTML;
                    document.getElementById('summaryContainer').classList.add('fade-in');
                }
                
                // إزالة تأثيرات الرسوم المتحركة بعد اكتمالها
                setTimeout(() => {
                    document.getElementById('statsGrid').classList.remove('fade-in');
                    document.getElementById('transactionsContainer').classList.remove('slide-up');
                    document.getElementById('summaryContainer').classList.remove('fade-in');
                }, 500);
            })
            .catch(error => {
                console.error('Error:', error);
                alert('حدث خطأ أثناء جلب البيانات');
            })
            .finally(() => {
                // إخفاء نافذة التحميل وإزالة تأثيرات التحميل
                document.getElementById('loadingOverlay').style.display = 'none';
                document.getElementById('statsGrid').classList.remove('stats-updating');
                document.getElementById('transactionsContainer').classList.remove('transactions-updating');
            });
        }

        // دالة محسّنة لإضافة تأخير لتفادي الطلبات المتكررة
        let filterTimeout;
        function debouncedFetchFilteredTransactions() {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(fetchFilteredTransactions, 300); // تأخير 300 مللي ثانية
        }

        // تهيئة الصفحة المحسنة
        document.addEventListener('DOMContentLoaded', function() {
            // إعداد زر القائمة للهواتف
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const sidebar = document.getElementById('sidebar');
            
            mobileMenuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
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

            // التهيئة الأولية للنموذج
            updateForm();

            // إضافة مستمعي الأحداث للفلاتر
            const filterBtn = document.getElementById('filterBtn');
            const filterType = document.getElementById('filter_type');
            const startDate = document.getElementById('start_date');
            const endDate = document.getElementById('end_date');
            
            // البحث عند النقر على زر البحث
            filterBtn.addEventListener('click', fetchFilteredTransactions);
            
            // البحث التلقائي عند تغيير الفلاتر
            filterType.addEventListener('change', debouncedFetchFilteredTransactions);
            startDate.addEventListener('change', debouncedFetchFilteredTransactions);
            endDate.addEventListener('change', debouncedFetchFilteredTransactions);
            
            // البحث التلقائي عند إدخال النص في التواريخ
            startDate.addEventListener('input', debouncedFetchFilteredTransactions);
            endDate.addEventListener('input', debouncedFetchFilteredTransactions);
            
            // إضافة مؤشر تحميل أثناء البحث
            const originalFilterBtnText = filterBtn.innerHTML;
            let isFiltering = false;
            
            // استبدال دالة fetchFilteredTransactions الأصلية
            const originalFetchFilteredTransactions = fetchFilteredTransactions;
            fetchFilteredTransactions = function() {
                if (isFiltering) return;
                
                isFiltering = true;
                filterBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري البحث...';
                filterBtn.disabled = true;
                filterBtn.classList.add('btn-loading');
                
                originalFetchFilteredTransactions();
                
                // إعادة تعيين زر البحث بعد انتهاء التحميل
                setTimeout(() => {
                    filterBtn.innerHTML = originalFilterBtnText;
                    filterBtn.disabled = false;
                    filterBtn.classList.remove('btn-loading');
                    isFiltering = false;
                }, 1000);
            };
        });
    </script>
	    <!-- JavaScript مع منع إعادة التحميل -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const sidebar = document.getElementById('sidebar');
            const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
            const sidebarToggleIcon = document.getElementById('sidebarToggleIcon');
            const sidebarLinks = document.querySelectorAll('.sidebar-menu a');
            const pageTitle = document.querySelector('.page-title');
            
            let isSidebarOpen = false;
            let sidebarTimeout = null;

            // وظيفة فتح القائمة
            function openSidebar() {
                clearTimeout(sidebarTimeout);
                sidebar.classList.add('open');
                isSidebarOpen = true;
                sidebarToggleIcon.classList.remove('fa-chevron-right');
                sidebarToggleIcon.classList.add('fa-chevron-left');
            }

            // وظيفة إغلاق القائمة
            function closeSidebar() {
                sidebar.classList.remove('open');
                isSidebarOpen = false;
                sidebarToggleIcon.classList.remove('fa-chevron-left');
                sidebarToggleIcon.classList.add('fa-chevron-right');
            }

            // وظيفة تحديث الصفحة الحالية
            function updateCurrentPage(clickedLink) {
                // إزالة النشاط من جميع الروابط
                sidebarLinks.forEach(link => link.classList.remove('active'));
                
                // إضافة النشاط للرابط المختار
                clickedLink.classList.add('active');
                
                // تحديث عنوان الصفحة
                const iconClass = clickedLink.querySelector('i').className;
                const pageName = clickedLink.querySelector('span').textContent;
                pageTitle.innerHTML = `<i class="${iconClass}"></i> ${pageName}`;
            }

            // فتح القائمة عند التمرير (للكمبيوتر فقط)
            if (window.innerWidth > 768) {
                sidebar.addEventListener('mouseenter', openSidebar);
                
                sidebar.addEventListener('mouseleave', function() {
                    sidebarTimeout = setTimeout(() => {
                        if (isSidebarOpen) {
                            closeSidebar();
                        }
                    }, 300);
                });
            }

            // ⭐⭐ الحل الأساسي: منع إعادة التحميل وإغلاق القائمة ⭐⭐
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function(event) {
                    // منع السلوك الافتراضي (إعادة التحميل)
                    event.preventDefault();
                    
                    // إغلاق القائمة فوراً
                    closeSidebar();
                    
                    // تحديث الصفحة الحالية
                    updateCurrentPage(this);
                    
                    // إذا كان الرابط مختلف عن الصفحة الحالية، الانتقال إليه بعد تأخير بسيط
                    const targetPage = this.getAttribute('href');
                    const currentPage = window.location.href;
                    
                    if (!currentPage.includes(targetPage)) {
                        setTimeout(() => {
                            window.location.href = targetPage;
                        }, 300);
                    }
                });
            });

            // زر التبديل للقائمة
            sidebarToggleBtn.addEventListener('click', function() {
                if (isSidebarOpen) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });

            // للهواتف المحمولة
            mobileMenuToggle.addEventListener('click', function() {
                if (isSidebarOpen) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
                document.body.style.overflow = isSidebarOpen ? 'hidden' : '';
            });

            // إغلاق القائمة عند النقر خارجها (للجوال)
            document.addEventListener('click', function(event) {
                if (window.innerWidth <= 768 && isSidebarOpen) {
                    if (!sidebar.contains(event.target) && !mobileMenuToggle.contains(event.target)) {
                        closeSidebar();
                        document.body.style.overflow = '';
                    }
                }
            });
        });
    </script>
</body>
</html>