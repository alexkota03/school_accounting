<?php
include '../includes/config.php';
include '../includes/auth.php';
include '../includes/database.php';

redirectIfNotLoggedIn();
checkPermission('staff');

$database = new Database();
$db = $database->getConnection();

$message = '';

// معالجة AJAX request لتحميل رسوم الطالب (بما فيها رسوم التوصيل)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'get_student_fees') {
    $student_id = intval($_POST['student_id']);
    
    // جلب رسوم الطالب الأساسية + رسوم التوصيل مع الخصومات
    $query = "SELECT 
                sf.id,
                CASE 
                    WHEN sf.fee_type_id IS NOT NULL THEN ft.fee_name
                    WHEN sf.area_id IS NOT NULL THEN CONCAT('رسوم توصيل - ', ra.area_name)
                    ELSE 'رسمة عامة'
                END as fee_name,
                CASE 
                    WHEN sf.fee_type_id IS NOT NULL THEN ft.amount
                    WHEN sf.area_id IS NOT NULL THEN ra.area_price
                    ELSE sf.original_amount
                END as original_amount,
                COALESCE(sf.discount_amount, 0) as discount_amount,
                CASE 
                    WHEN sf.fee_type_id IS NOT NULL THEN ft.amount - COALESCE(sf.discount_amount, 0)
                    WHEN sf.area_id IS NOT NULL THEN ra.area_price - COALESCE(sf.discount_amount, 0)
                    ELSE sf.original_amount - COALESCE(sf.discount_amount, 0)
                END as final_amount,
                sf.paid_amount,
                (CASE 
                    WHEN sf.fee_type_id IS NOT NULL THEN ft.amount - COALESCE(sf.discount_amount, 0)
                    WHEN sf.area_id IS NOT NULL THEN ra.area_price - COALESCE(sf.discount_amount, 0)
                    ELSE sf.original_amount - COALESCE(sf.discount_amount, 0)
                END - sf.paid_amount) as remaining_amount,
                CASE 
                    WHEN (CASE 
                            WHEN sf.fee_type_id IS NOT NULL THEN ft.amount - COALESCE(sf.discount_amount, 0)
                            WHEN sf.area_id IS NOT NULL THEN ra.area_price - COALESCE(sf.discount_amount, 0)
                            ELSE sf.original_amount - COALESCE(sf.discount_amount, 0)
                          END - sf.paid_amount) <= 0 THEN 'paid'
                    WHEN sf.paid_amount > 0 THEN 'partial'
                    ELSE 'pending'
                END as status
              FROM student_fees sf
              LEFT JOIN fee_types ft ON sf.fee_type_id = ft.id
              LEFT JOIN residential_areas ra ON sf.area_id = ra.id
              WHERE sf.student_id = :student_id 
              ORDER BY sf.created_at";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':student_id', $student_id);
    $stmt->execute();
    $fees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($fees);
    exit;
}

// تسجيل مدفوعات
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_payment') {
    $student_id = intval($_POST['student_id']);
    $payment_date = $_POST['payment_date'];
    $payment_method = $_POST['payment_method'];
    $notes = trim($_POST['notes']);
    $additional_amount = floatval($_POST['additional_amount'] ?? 0);
    $used_additional_amount = floatval($_POST['used_additional_amount'] ?? 0);
    $is_additional_payment = isset($_POST['additional_payment_flag']) && $_POST['additional_payment_flag'] == '1';
    
    $total_amount = 0;
    $valid_payments = [];
    
    // تجميع جميع حقول الرسوم من النموذج
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'fee_') === 0) {
            $amount = floatval($value);
            
            if ($amount > 0) {
                $fee_id = str_replace('fee_', '', $key);
                
                if (is_numeric($fee_id)) {
                    // التحقق من أن الرسمة موجودة وتنتمي للطالب
                    $fee_query = "SELECT sf.id, 
                                  CASE 
                                      WHEN sf.fee_type_id IS NOT NULL THEN ft.amount - COALESCE(sf.discount_amount, 0)
                                      WHEN sf.area_id IS NOT NULL THEN ra.area_price - COALESCE(sf.discount_amount, 0)
                                      ELSE sf.original_amount - COALESCE(sf.discount_amount, 0)
                                  END as final_amount,
                                  sf.paid_amount, sf.student_id,
                                  COALESCE(ft.fee_name, CONCAT('رسوم توصيل - ', ra.area_name)) as fee_name
                                  FROM student_fees sf 
                                  LEFT JOIN fee_types ft ON sf.fee_type_id = ft.id
                                  LEFT JOIN residential_areas ra ON sf.area_id = ra.id
                                  WHERE sf.id = :fee_id AND sf.student_id = :student_id";
                    $fee_stmt = $db->prepare($fee_query);
                    $fee_stmt->bindParam(':fee_id', $fee_id);
                    $fee_stmt->bindParam(':student_id', $student_id);
                    $fee_stmt->execute();
                    $fee = $fee_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($fee) {
                        $remaining = $fee['final_amount'] - $fee['paid_amount'];
                        
                        $valid_payments[] = [
                            'fee_id' => $fee_id,
                            'amount' => $amount,
                            'remaining' => $remaining,
                            'fee_name' => $fee['fee_name']
                        ];
                        $total_amount += $amount;
                    }
                }
            }
        }
    }
    
    // إذا كان هناك خصم من الرصيد الإضافي
    if ($is_additional_payment && $used_additional_amount > 0) {
        // المبلغ الإضافي يكون سالباً للإشارة إلى الخصم
        $additional_amount = -$used_additional_amount;
        $total_amount += $used_additional_amount; // إضافة المبلغ المستخدم إلى الإجمالي
    } else if ($additional_amount > 0) {
        // إضافة رصيد إضافي جديد
        $total_amount += $additional_amount;
    }
    
    if ($total_amount > 0 || ($is_additional_payment && $used_additional_amount > 0)) {
        try {
            $db->beginTransaction();
            
            // إنشاء رقم إيصال أساسي
            $last_payment = $db->query("SELECT MAX(id) as last_id FROM payments")->fetch(PDO::FETCH_ASSOC);
            $next_id = ($last_payment['last_id'] ?? 0) + 1;
            $base_receipt_number = 'RCP' . str_pad($next_id, 6, '0', STR_PAD_LEFT);
            
            $payment_count = 0;
            
            // تسجيل مدفوعات لكل رسمة
            foreach ($valid_payments as $payment) {
                if ($payment['amount'] > 0) {
                    $payment_count++;
                    
                    $receipt_number = $base_receipt_number . '-' . str_pad($payment_count, 2, '0', STR_PAD_LEFT);
                    
                    $query = "INSERT INTO payments (student_id, student_fee_id, amount, payment_date, payment_method, notes, received_by, receipt_number) 
                              VALUES (:student_id, :student_fee_id, :amount, :payment_date, :payment_method, :notes, :received_by, :receipt_number)";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':student_id', $student_id);
                    $stmt->bindParam(':student_fee_id', $payment['fee_id']);
                    $stmt->bindParam(':amount', $payment['amount']);
                    $stmt->bindParam(':payment_date', $payment_date);
                    $stmt->bindParam(':payment_method', $payment_method);
                    $stmt->bindParam(':notes', $notes);
                    $stmt->bindParam(':received_by', $_SESSION['user_id']);
                    $stmt->bindParam(':receipt_number', $receipt_number);
                    
                    if ($stmt->execute()) {
                        $update_query = "UPDATE student_fees SET paid_amount = paid_amount + :amount WHERE id = :fee_id";
                        $update_stmt = $db->prepare($update_query);
                        $update_stmt->bindParam(':amount', $payment['amount']);
                        $update_stmt->bindParam(':fee_id', $payment['fee_id']);
                        $update_stmt->execute();
                    }
                }
            }
            
            // تسجيل المبلغ الإضافي (سالب في حالة الخصم، موجب في حالة الإضافة)
            if ($additional_amount != 0) {
                $additional_receipt = $base_receipt_number . '-ADD';
                
                if ($additional_amount < 0) {
                    // خصم من الرصيد الإضافي
                    $deducted_amount = abs($additional_amount);
                    $query = "INSERT INTO payments (student_id, amount, payment_date, payment_method, notes, received_by, receipt_number, is_additional) 
                              VALUES (:student_id, :amount, :payment_date, :payment_method, :notes, :received_by, :receipt_number, 1)";
                    $notes_with_deduction = $notes . " (خصم من الرصيد الإضافي: " . number_format($deducted_amount, 2) . " " . CURRENCY . ")";
                    
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':student_id', $student_id);
                    $stmt->bindParam(':amount', $additional_amount); // هذا سيكون سالباً
                    $stmt->bindParam(':payment_date', $payment_date);
                    $stmt->bindParam(':payment_method', $payment_method);
                    $stmt->bindParam(':notes', $notes_with_deduction);
                    $stmt->bindParam(':received_by', $_SESSION['user_id']);
                    $stmt->bindParam(':receipt_number', $additional_receipt);
                    $stmt->execute();
                } else {
                    // إضافة رصيد إضافي
                    $query = "INSERT INTO payments (student_id, amount, payment_date, payment_method, notes, received_by, receipt_number, is_additional) 
                              VALUES (:student_id, :amount, :payment_date, :payment_method, :notes, :received_by, :receipt_number, 1)";
                    
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':student_id', $student_id);
                    $stmt->bindParam(':amount', $additional_amount);
                    $stmt->bindParam(':payment_date', $payment_date);
                    $stmt->bindParam(':payment_method', $payment_method);
                    $stmt->bindParam(':notes', $notes);
                    $stmt->bindParam(':received_by', $_SESSION['user_id']);
                    $stmt->bindParam(':receipt_number', $additional_receipt);
                    $stmt->execute();
                }
            }
            
            // تحديث حالة الرسوم
            updateStudentFeesStatus($student_id, $db);
            
            $db->commit();
            
            // رسالة نجاح مختلفة حسب نوع العملية
            if ($is_additional_payment && $used_additional_amount > 0) {
                $_SESSION['message'] = '<div class="alert alert-success">تم تسجيل ' . count($valid_payments) . ' مدفوعات بنجاح - تم خصم ' . number_format($used_additional_amount, 2) . ' ' . CURRENCY . ' من الرصيد الإضافي - رقم الإيصال: ' . $base_receipt_number . ' - الإجمالي: ' . number_format($total_amount, 2) . ' ' . CURRENCY . '</div>';
            } else if ($additional_amount > 0) {
                $_SESSION['message'] = '<div class="alert alert-success">تم تسجيل ' . count($valid_payments) . ' مدفوعات بنجاح + ' . number_format($additional_amount, 2) . ' ' . CURRENCY . ' رصيد إضافي - رقم الإيصال: ' . $base_receipt_number . ' - الإجمالي: ' . number_format($total_amount, 2) . ' ' . CURRENCY . '</div>';
            } else {
                $_SESSION['message'] = '<div class="alert alert-success">تم تسجيل ' . count($valid_payments) . ' مدفوعات بنجاح - رقم الإيصال: ' . $base_receipt_number . ' - الإجمالي: ' . number_format($total_amount, 2) . ' ' . CURRENCY . '</div>';
            }
            
            header("Location: payments.php");
            exit;
            
        } catch (Exception $e) {
            $db->rollBack();
            $message = '<div class="alert alert-danger">حدث خطأ أثناء تسجيل المدفوعات: ' . $e->getMessage() . '</div>';
        }
    } else {
        $message = '<div class="alert alert-danger">الرجاء إدخال مبالغ صحيحة للرسوم</div>';
    }
}

// دالة حساب رصيد الطالب (محدثة بالكامل مع الخصومات)
function getStudentBalance($student_id, $db) {
    // حساب إجمالي الرسوم الأساسية بشكل ديناميكي مع الخصومات
    $fees_query = "SELECT COALESCE(SUM(
                    CASE 
                        WHEN sf.fee_type_id IS NOT NULL THEN ft.amount - COALESCE(sf.discount_amount, 0)
                        WHEN sf.area_id IS NOT NULL THEN ra.area_price - COALESCE(sf.discount_amount, 0)
                        ELSE sf.original_amount - COALESCE(sf.discount_amount, 0)
                    END
                ), 0) as total_fees,
                COALESCE(SUM(sf.discount_amount), 0) as total_discounts
                FROM student_fees sf
                LEFT JOIN fee_types ft ON sf.fee_type_id = ft.id
                LEFT JOIN residential_areas ra ON sf.area_id = ra.id
                WHERE sf.student_id = :student_id";
    
    $fees_stmt = $db->prepare($fees_query);
    $fees_stmt->bindParam(':student_id', $student_id);
    $fees_stmt->execute();
    $fees_result = $fees_stmt->fetch(PDO::FETCH_ASSOC);
    $total_fees = $fees_result['total_fees'];
    $total_discounts = $fees_result['total_discounts'];

    // حساب إجمالي المدفوعات للرسوم الأساسية فقط (بدون المبالغ الإضافية)
    $payments_query = "SELECT COALESCE(SUM(amount), 0) as total_paid 
                       FROM payments 
                       WHERE student_id = :student_id AND (student_fee_id IS NOT NULL AND is_additional = 0)";
    $payments_stmt = $db->prepare($payments_query);
    $payments_stmt->bindParam(':student_id', $student_id);
    $payments_stmt->execute();
    $payments_result = $payments_stmt->fetch(PDO::FETCH_ASSOC);
    $total_paid = $payments_result['total_paid'];

    // حساب المبلغ الإضافي (الإضافات ناقص الخصومات)
    $additional_query = "SELECT COALESCE(SUM(amount), 0) as additional_amount
                         FROM payments 
                         WHERE student_id = :student_id AND is_additional = 1";
    $additional_stmt = $db->prepare($additional_query);
    $additional_stmt->bindParam(':student_id', $student_id);
    $additional_stmt->execute();
    $additional_result = $additional_stmt->fetch(PDO::FETCH_ASSOC);
    $additional_amount = $additional_result['additional_amount'];

    // حساب المبلغ المتبقي (الرسوم الأساسية فقط بعد الخصم)
    $remaining = max(0, $total_fees - $total_paid);

    return [
        'total_fees' => $total_fees + $total_discounts, // إجمالي الرسوم قبل الخصم
        'total_discounts' => $total_discounts, // إجمالي الخصومات
        'total_fees_after_discount' => $total_fees, // إجمالي الرسوم بعد الخصم
        'total_paid' => $total_paid,
        'remaining' => $remaining,
        'additional_amount' => $additional_amount,
        'total_payments' => $total_paid + $additional_amount
    ];
}

// جلب الطلاب للقائمة المنسدلة
$students = $db->query("SELECT * FROM students WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

// تحديث بيانات الرصيد لكل طالب
foreach ($students as &$student) {
    $student['balance'] = getStudentBalance($student['id'], $db);
}
unset($student); // كسر المرجع

// جلب آخر المدفوعات مع بيانات مفصلة
$query = "SELECT 
            p.*, 
            s.full_name as student_name,
            s.student_code,
            u.full_name as received_by_name,
            COALESCE(ft.fee_name, CONCAT('رسوم توصيل - ', ra.area_name)) as fee_name,
            p.is_additional,
            (SELECT COALESCE(SUM(sf.final_amount), 0) FROM student_fees sf WHERE sf.student_id = p.student_id) as total_fees,
            (SELECT COALESCE(SUM(p2.amount), 0) FROM payments p2 WHERE p2.student_id = p.student_id) as total_paid,
            ((SELECT COALESCE(SUM(sf.final_amount), 0) FROM student_fees sf WHERE sf.student_id = p.student_id) - 
             (SELECT COALESCE(SUM(p2.amount), 0) FROM payments p2 WHERE p2.student_id = p.student_id)) as remaining
          FROM payments p 
          JOIN students s ON p.student_id = s.id 
          JOIN users u ON p.received_by = u.id
          LEFT JOIN student_fees sf ON p.student_fee_id = sf.id
          LEFT JOIN fee_types ft ON sf.fee_type_id = ft.id
          LEFT JOIN residential_areas ra ON sf.area_id = ra.id
          ORDER BY p.created_at DESC 
          LIMIT 10";
$recent_payments = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);

// دالة تحديث حالة الرسوم
function updateStudentFeesStatus($student_id, $db) {
    // تحديث حالة كل رسمة على حدة بناءً على أحدث البيانات
    $fees_query = "SELECT 
                    sf.id,
                    CASE 
                        WHEN sf.fee_type_id IS NOT NULL THEN ft.amount - COALESCE(sf.discount_amount, 0)
                        WHEN sf.area_id IS NOT NULL THEN ra.area_price - COALESCE(sf.discount_amount, 0)
                        ELSE sf.original_amount - COALESCE(sf.discount_amount, 0)
                    END as final_amount,
                    sf.paid_amount
                   FROM student_fees sf
                   LEFT JOIN fee_types ft ON sf.fee_type_id = ft.id
                   LEFT JOIN residential_areas ra ON sf.area_id = ra.id
                   WHERE sf.student_id = :student_id";
    $fees_stmt = $db->prepare($fees_query);
    $fees_stmt->bindParam(':student_id', $student_id);
    $fees_stmt->execute();
    $fees = $fees_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($fees as $fee) {
        $remaining = $fee['final_amount'] - $fee['paid_amount'];
        
        if ($remaining <= 0) {
            $status = 'paid';
        } else if ($fee['paid_amount'] > 0) {
            $status = 'partial';
        } else {
            $status = 'pending';
        }
        
        $update_query = "UPDATE student_fees SET status = :status WHERE id = :fee_id";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':status', $status);
        $update_stmt->bindParam(':fee_id', $fee['id']);
        $update_stmt->execute();
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل المدفوعات - الموظف - نظام المحاسبة المدرسية</title>
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
        
        .alert {
            padding: 16px 20px;
            border-radius: var(--border-radius);
            margin-bottom: 24px;
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
        
        .alert-warning {
            background: var(--warning-light);
            color: var(--warning);
            border-color: rgba(245, 158, 11, 0.2);
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
        
        .submit-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }
        
        .submit-btn:disabled {
            background: var(--gray);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        /* تصميم البحث */
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
            transition: background 0.2s ease;
        }
        
        .search-result-item:hover {
            background: rgba(67, 97, 238, 0.1);
        }
        
        .search-result-item:last-child {
            border-bottom: none;
        }
        
        .student-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .student-name {
            font-weight: 600;
            color: var(--dark);
        }
        
        .student-code {
            font-family: 'Courier New', monospace;
            background: var(--primary-light);
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            color: var(--primary);
        }
        
        .student-balance-display {
            font-size: 12px;
            color: var(--warning);
            font-weight: 600;
            margin-right: 5px;
        }
        
        .select-divider {
            text-align: center;
            margin: 10px 0;
            color: var(--gray);
            font-size: 14px;
            position: relative;
        }
        
        .select-divider::before {
            content: '';
            position: absolute;
            top: 50%;
            right: 0;
            left: 0;
            height: 1px;
            background: var(--gray-light);
        }
        
        .select-divider span {
            background: white;
            padding: 0 10px;
            position: relative;
        }
        
        /* رصيد الطالب */
        .student-balance {
            background: var(--light);
            padding: 20px;
            border-radius: var(--border-radius);
            margin-top: 15px;
            border: 1px solid var(--gray-light);
            display: none;
        }
        
        .balance-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .balance-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .balance-label {
            color: var(--gray);
            font-size: 14px;
            font-weight: 500;
        }
        
        .balance-value {
            font-size: 16px;
            font-weight: 700;
        }
        
        .total-fees { color: var(--danger); }
        .total-paid { color: var(--success); }
        .remaining { color: var(--warning); }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--gray-light);
            border-radius: 10px;
            overflow: hidden;
            margin: 10px 0;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, var(--success), #059669);
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        
        .progress-text {
            text-align: center;
            font-size: 12px;
            color: var(--gray);
            font-weight: 500;
        }
        
        .fully-paid {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            padding: 15px;
            border-radius: var(--border-radius);
            text-align: center;
            font-weight: 600;
            border: 1px solid rgba(16, 185, 129, 0.2);
            margin-top: 15px;
            display: none;
        }
        
        .status-notes {
            font-size: 14px;
            font-weight: 500;
            text-align: center;
            padding: 10px;
            border-radius: 8px;
            margin-top: 10px;
        }
        
        .status-notes i {
            margin-left: 5px;
        }
        
        .balance-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        @media (max-width: 768px) {
            .balance-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* جدول الرسوم */
        .fees-list {
            margin-top: 20px;
            display: none;
        }
        
        .fees-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }
        
        .fees-table th {
            background: var(--primary);
            color: white;
            padding: 15px;
            text-align: right;
            font-weight: 600;
            font-size: 14px;
        }
        
        .fees-table td {
            padding: 12px;
            text-align: right;
            border-bottom: 1px solid var(--gray-light);
            color: var(--dark);
        }
        
        .fees-table tr:hover {
            background: rgba(67, 97, 238, 0.05);
        }
        
        /* تنسيق الخصم داخل الخلية */
        .fees-table td div:first-child {
            margin-bottom: 2px;
        }
        
        .fees-table td div:last-child {
            border-top: 1px dashed rgba(16, 185, 129, 0.3);
            padding-top: 4px;
            margin-top: 4px;
        }
        
        .fee-amount-input {
            width: 120px;
            padding: 8px 12px;
            border: 2px solid var(--gray-light);
            border-radius: 8px;
            text-align: left;
            direction: ltr;
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }
        
        .fee-amount-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .fee-amount-input.valid {
            border-color: var(--success);
            background: rgba(16, 185, 129, 0.05);
        }
        
        .fee-amount-input.invalid {
            border-color: var(--danger);
            background: rgba(239, 68, 68, 0.05);
        }
        
        .fee-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-pending { 
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }
        
        .status-partial { 
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        
        .status-paid { 
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        /* الإجراءات السريعة */
        .quick-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .quick-action-btn {
            background: rgba(67, 97, 238, 0.1);
            color: var(--primary);
            border: 1px solid rgba(67, 97, 238, 0.2);
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .quick-action-btn:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }
        
        /* تنسيق خاص للزر الجديد */
        .quick-action-btn.wallet-btn {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .quick-action-btn.wallet-btn:hover {
            background: var(--success);
            color: white;
        }
        
        /* المبلغ الإضافي */
        .additional-amount-section {
            background: rgba(59, 130, 246, 0.05);
            padding: 15px;
            border-radius: var(--border-radius);
            border: 1px solid rgba(59, 130, 246, 0.2);
            margin-top: 15px;
        }
        
        .additional-amount-label {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--info);
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        /* إجمالي المبلغ */
        .total-amount-section {
            background: var(--light);
            padding: 20px;
            border-radius: var(--border-radius);
            margin-top: 20px;
            border: 1px solid var(--gray-light);
        }
        
        .total-amount {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
            margin: 10px 0;
            text-align: center;
        }
        
        .amount-help {
            color: var(--gray);
            font-size: 14px;
            margin-top: 10px;
            text-align: center;
        }
        
        /* جدول المدفوعات */
        .payments-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .payments-table th,
        .payments-table td {
            padding: 8px 10px;
            white-space: nowrap;
        }
        
        .payments-table th {
            background: var(--primary);
            color: white;
            padding: 15px;
            text-align: right;
            font-weight: 600;
            font-size: 14px;
        }
        
        .payments-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--gray-light);
            text-align: right;
            font-size: 14px;
        }
        
        .payments-table tr:hover {
            background: var(--primary-light);
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
        
        .cash { 
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .bank { 
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        
        .receipt-number {
            font-family: 'Courier New', monospace;
            background: var(--primary-light);
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            color: var(--primary);
        }
        
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
        }
        
        .no-data i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .no-data h3 {
            font-size: 20px;
            margin-bottom: 10px;
            color: var(--dark);
        }
        
        .no-data p {
            font-size: 16px;
            opacity: 0.8;
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
        padding: 12px 15px;
        flex-direction: column;
        gap: 12px;
        text-align: center;
    }
    
    .page-title {
        font-size: 18px;
        gap: 8px;
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
        padding: 6px 12px;
        font-size: 12px;
        width: 100%;
        justify-content: center;
    }
    
    .content {
        padding: 16px;
    }
    
    .dashboard-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .stat-card {
        padding: 20px;
    }
    
    .stat-number {
        font-size: 28px;
    }
    
    .action-buttons {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    
    .action-btn {
        padding: 12px 15px;
        font-size: 14px;
    }
    
    .welcome-section {
        padding: 30px 20px;
    }
    
    .welcome-section h1 {
        font-size: 24px;
    }
    
    .welcome-section p {
        font-size: 16px;
    }
    
    .card-body {
        padding: 16px;
    }
    
    .activity-item, .student-item {
        padding: 12px;
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    
    .activity-info, .student-info {
        width: 100%;
    }
    
    .activity-amount, .student-code {
        align-self: flex-end;
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
        
/* تحسينات للشاشات الصغيرة جداً */
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
    
    .stats-grid {
        gap: 12px;
    }
    
    .stat-card {
        padding: 15px;
    }
    
    .stat-icon {
        width: 50px;
        height: 50px;
        font-size: 20px;
    }
    
    .stat-number {
        font-size: 24px;
    }
    
    .action-btn {
        padding: 10px 12px;
        font-size: 13px;
    }
    
    .welcome-section {
        padding: 20px 15px;
    }
    
    .welcome-section h1 {
        font-size: 20px;
    }
    
    .welcome-section p {
        font-size: 14px;
    }
    
    .card-header {
        padding: 15px;
    }
    
    .card-title {
        font-size: 16px;
    }
    
    .card-body {
        padding: 12px;
    }
    
    .activity-item, .student-item {
        padding: 10px;
    }
    
    .activity-title, .student-name {
        font-size: 14px;
    }
    
    .activity-time, .student-date {
        font-size: 11px;
    }
    
    .activity-amount {
        font-size: 14px;
    }
    
    .student-code {
        font-size: 11px;
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
                <li><a href="payments.php" class="active"><i class="fas fa-credit-card"></i><span>تسجيل المدفوعات</span></a></li>
                <li><a href="discounts.php"><i class="fas fa-percentage"></i><span>تطبيق الخصومات</span></a></li>
                <li><a href="cash_box_management.php"><i class="fas fa-exchange-alt"></i><span>الإيرادات والمصروفات</span></a></li>
                <li><a href="../reports/index.php"><i class="fas fa-chart-bar"></i><span>التقارير</span></a></li>
                <li><a href="account_settings.php"><i class="fas fa-cog"></i><span>إعدادات الحساب</span></a></li>
            </ul>
        </div>
        
        <!-- المحتوى الرئيسي -->
        <div class="main-content">
            <div class="header">
                <h1 class="page-title">
                    <i class="fas fa-credit-card"></i>
                    تسجيل المدفوعات
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
                
                <div class="dashboard-grid">
                    <!-- قسم تسجيل المدفوعات -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-plus-circle"></i>
                                تسجيل مدفوعات جديدة
                            </h2>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="paymentForm">
                                <input type="hidden" name="action" value="add_payment">
                                
                                <div class="form-group">
                                    <label class="form-label">اختر الطالب</label>
                                    
                                    <!-- البحث الديناميكي -->
                                    <div class="student-search">
                                        <input type="text" id="studentSearch" class="form-control" 
                                               placeholder="🔍 اكتب اسم الطالب أو الرقم للبحث..." 
                                               autocomplete="off">
                                        <div id="searchResults" class="search-results"></div>
                                    </div>
                                    
                                    <!-- فاصل بين البحث والقائمة -->
                                    <div class="select-divider">
                                        <span>أو</span>
                                    </div>
                                    
                                    <!-- القائمة المنسدلة التقليدية -->
                                    <div class="traditional-select">
                                        <select name="student_id" id="student_id" class="form-control" required onchange="handleStudentSelect(this.value)">
                                            <option value="">-- اختر الطالب من القائمة --</option>
                                            <?php foreach ($students as $student): ?>
                                            <option value="<?php echo $student['id']; ?>" data-balance='<?php echo json_encode($student['balance']); ?>'>
                                                <?php echo htmlspecialchars($student['full_name']) . ' - ' . $student['student_code']; ?>
                                                <span class="student-balance-display">
                                                    (<?php 
                                                    $remaining = $student['balance']['remaining'];
                                                    if ($remaining > 0) {
                                                        echo number_format($remaining, 2) . ' ' . CURRENCY . ' مستحق';
                                                    } else {
                                                        echo 'مدفوع بالكامل';
                                                        if ($student['balance']['additional_amount'] > 0) {
                                                            echo ' + ' . number_format($student['balance']['additional_amount'], 2) . ' ' . CURRENCY . ' رصيد إضافي';
                                                        }
                                                    }
                                                    ?>)
                                                </span>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div id="balanceInfo" class="student-balance">
                                        <div class="balance-grid">
                                            <div class="balance-item">
                                                <span class="balance-label">إجمالي الرسوم:</span>
                                                <span class="balance-value total-fees" id="totalFees">0.00</span>
                                            </div>
                                            <div class="balance-item">
                                                <span class="balance-label">المدفوع:</span>
                                                <span class="balance-value total-paid" id="totalPaid">0.00</span>
                                            </div>
                                            <div class="balance-item">
                                                <span class="balance-label">المستحق:</span>
                                                <span class="balance-value remaining" id="remainingAmount">0.00</span>
                                            </div>
                                            <div class="balance-item">
                                                <span class="balance-label">نسبة السداد:</span>
                                                <span class="balance-value" id="paymentPercentage">0%</span>
                                            </div>
                                            <!-- إضافة مبلغ إضافي والحالة -->
                                            <div class="balance-item">
                                                <span class="balance-label">المبلغ الإضافي:</span>
                                                <span class="balance-value additional-amount" id="additionalAmount" style="color: var(--info);">0.00</span>
                                            </div>
                                            <div class="balance-item">
                                                <span class="balance-label">الحالة:</span>
                                                <span class="balance-value status" id="paymentStatus" style="font-weight: bold;">-</span>
                                            </div>
                                        </div>
                                        
                                        <div class="progress-bar">
                                            <div class="progress-fill" id="progressFill" style="width: 0%"></div>
                                        </div>
                                        <div class="progress-text" id="progressText">0% من الرسوم مدفوعة</div>
                                        
                                        <!-- ملاحظات الحالة -->
                                        <div id="statusNotes" class="status-notes" style="margin-top: 15px; padding: 10px; border-radius: 8px; display: none;"></div>
                                    </div>
                                </div>
                                
                                <!-- قسم الرسوم - سيظهر دائمًا -->
                                <div id="feesList" class="fees-list">
                                    <h4 style="margin: 20px 0 10px 0; color: var(--dark); border-bottom: 1px solid var(--gray-light); padding-bottom: 10px;">
                                        <i class="fas fa-list"></i> الرسوم المطلوبة
                                    </h4>
                                    
                                    <!-- أزرار الإجراءات السريعة -->
                                    <div class="quick-actions">
                                        <button type="button" class="quick-action-btn" onclick="payRemainingFees()">
                                            <i class="fas fa-coins"></i>
                                            سداد المستحق
                                        </button>
                                        <!-- الزر الجديد -->
                                        <button type="button" class="quick-action-btn wallet-btn" onclick="payFromAdditionalBalance()">
                                            <i class="fas fa-wallet"></i>
                                            سداد من الرصيد الإضافي
                                        </button>
                                        <button type="button" class="quick-action-btn" onclick="clearAllFees()">
                                            <i class="fas fa-times"></i>
                                            مسح الكل
                                        </button>
                                    </div>
                                    
                                    <div class="table-responsive">
                                        <table class="fees-table">
                                            <thead>
                                                <tr>
                                                    <th>اسم الرسمة</th>
                                                    <th>المبلغ الأصلي</th>
                                                    <th>المستحق</th>
                                                    <th>المسدد</th>
                                                    <th>المتبقي</th>
                                                    <th>الحالة</th>
                                                    <th>المبلغ المسدد</th>
                                                </tr>
                                            </thead>
                                            <tbody id="feesTableBody">
                                                <!-- سيتم ملؤها بالجافاسكربت -->
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <!-- قسم المبلغ الإضافي -->
                                    <div class="additional-amount-section">
                                        <div class="additional-amount-label">
                                            <i class="fas fa-plus-circle"></i>
                                            مبلغ إضافي (رصيد لحساب الطالب)
                                        </div>
                                        <input type="number" name="additional_amount" id="additional_amount" 
                                               class="form-control" placeholder="0.00" step="0.01" min="0" 
                                               value="0" oninput="updateTotalAmount()">
                                        <small style="color: var(--info); display: block; margin-top: 5px;">
                                            يمكن إضافة مبلغ إضافي كرصيد لحساب الطالب للمستقبل
                                        </small>
                                    </div>
                                    
                                    <!-- ملخص المدفوعات -->
                                    <div id="totalAmountSection" class="total-amount-section" style="display: none;">
                                        <div class="total-amount" id="totalAmount">0.00 <?php echo CURRENCY; ?></div>
                                        <div class="amount-help" id="amountHelpText">سيتم توزيع المبلغ على الرسوم المحددة</div>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">تاريخ السداد</label>
                                    <input type="date" name="payment_date" class="form-control" required 
                                           value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">طريقة السداد</label>
                                    <select name="payment_method" class="form-control" required>
                                        <option value="cash">نقدي</option>
                                        <option value="bank_transfer">تحويل بنكي</option>
                                        <option value="credit_card">بطاقة ائتمان</option>
                                        <option value="check">شيك</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">ملاحظات (اختياري)</label>
                                    <textarea name="notes" class="form-control" rows="3" 
                                              placeholder="أي ملاحظات حول المدفوعات"></textarea>
                                </div>
                                
                                <button type="submit" class="submit-btn" id="submitBtn" disabled>
                                    <i class="fas fa-save"></i>
                                    تسجيل المدفوعات
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- قسم آخر المدفوعات -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-history"></i>
                                آخر المدفوعات المسجلة
                            </h2>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($recent_payments)): ?>
                            <div class="table-responsive">
                                <table class="payments-table">
                                    <thead>
                                        <tr>
                                            <th>الطالب</th>
                                            <th>المرحلة</th>
                                            <th>نوع الرسمة</th>
                                            <th>المبلغ</th>
                                            <th>طريقة الدفع</th>
                                            <th>التاريخ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_payments as $payment): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($payment['student_name']); ?></td>
                                            <td><?php echo htmlspecialchars($payment['stage_name'] ?? 'غير محدد'); ?></td>
                                            <td>
                                                <?php 
                                                if ($payment['is_additional'] ?? 0) {
                                                    echo '<span style="color: var(--info);">مبلغ إضافي</span>';
                                                } else {
                                                    echo htmlspecialchars($payment['fee_name'] ?? 'عام');
                                                }
                                                ?>
                                            </td>
                                            <td style="color: var(--success); font-weight: bold;">
                                                <?php echo number_format($payment['amount'], 2) . ' ' . CURRENCY; ?>
                                            </td>
                                            <td>
                                                <?php if ($payment['payment_method'] == 'cash'): ?>
                                                    <span>نقدي</span>
                                                <?php elseif ($payment['payment_method'] == 'bank_transfer'): ?>
                                                    <span>تحويل بنكي</span>
                                                <?php elseif ($payment['payment_method'] == 'credit_card'): ?>
                                                    <span>بطاقة ائتمان</span>
                                                <?php else: ?>
                                                    <span>شيك</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('Y-m-d', strtotime($payment['payment_date'])); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-receipt"></i>
                                <p>لا توجد مدفوعات مسجلة حالياً</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // تهيئة الصفحة
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
        
        // إضافة event listener للقائمة المنسدلة
        document.getElementById('student_id').addEventListener('change', function() {
            handleStudentSelect(this.value);
        });
        
        // إظهار رصيد عشوائي عند التحميل للعرض
        const firstStudent = document.querySelector('#student_id option[value]:not([value=""])');
        if (firstStudent) {
            handleStudentSelect(firstStudent.value);
        }
    });

    // تعريف handleStudentSelect في الأعلى
    function handleStudentSelect(studentId) {
        if (studentId) {
            document.getElementById('studentSearch').value = '';
            getStudentBalance(studentId);
        } else {
            document.getElementById('feesList').style.display = 'none';
            document.getElementById('totalAmountSection').style.display = 'none';
            document.getElementById('submitBtn').disabled = true;
            document.getElementById('balanceInfo').style.display = 'none';
        }
    }

    // البحث الديناميكي عن الطلاب
    document.getElementById('studentSearch').addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase();
        const searchResults = document.getElementById('searchResults');
        const studentSelect = document.getElementById('student_id');
        const options = studentSelect.querySelectorAll('option');
        
        if (searchTerm.length < 2) {
            searchResults.style.display = 'none';
            return;
        }
        
        let resultsHTML = '';
        let hasResults = false;
        
        options.forEach(option => {
            if (option.value && option.textContent.toLowerCase().includes(searchTerm)) {
                hasResults = true;
                const balanceData = JSON.parse(option.getAttribute('data-balance'));
                const remaining = parseFloat(balanceData.remaining || 0);
                
                let balanceText = '';
                if (remaining > 0) {
                    balanceText = remaining.toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }) + ' <?php echo CURRENCY; ?> مستحق';
                } else {
                    balanceText = 'مدفوع بالكامل';
                    if (balanceData.additional_amount > 0) {
                        balanceText += ' + ' + parseFloat(balanceData.additional_amount || 0).toLocaleString('en-US', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        }) + ' <?php echo CURRENCY; ?> رصيد إضافي';
                    }
                }
                
                resultsHTML += `
                    <div class="search-result-item" data-value="${option.value}">
                        <div class="student-info">
                            <span class="student-name">${option.textContent.split(' - ')[0]}</span>
                            <div>
                                <span class="student-code">${option.textContent.split(' - ')[1]}</span>
                                <span style="color: var(--warning); font-size: 11px; margin-right: 5px;">
                                    (${balanceText})
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
            searchResults.style.display = 'none';
            searchResults.innerHTML = '<div class="search-result-item" style="color: var(--gray); text-align: center;">لا توجد نتائج</div>';
        }
    });

    // دالة سداد الرسوم المستحقة من المبلغ الإضافي
    function payFromAdditionalBalance() {
        const studentId = document.getElementById('student_id').value;
        if (!studentId) {
            alert('الرجاء اختيار طالب أولاً');
            return;
        }

        const studentSelect = document.getElementById('student_id');
        const selectedOption = studentSelect.querySelector(`option[value="${studentId}"]`);
        
        if (!selectedOption) return;
        
        const balanceData = JSON.parse(selectedOption.getAttribute('data-balance'));
        const additionalBalance = parseFloat(balanceData.additional_amount || 0);
        
        if (additionalBalance <= 0) {
            alert('لا يوجد رصيد إضافي متاح للسداد');
            return;
        }

        const feeInputs = document.querySelectorAll('.fee-amount-input');
        let totalRemaining = 0;
        let feesToPay = [];
        
        // حساب إجمالي المبلغ المستحق وجمع الرسوم المستحقة
        feeInputs.forEach(input => {
            const maxAmount = parseFloat(input.getAttribute('max')) || 0;
            if (maxAmount > 0) {
                totalRemaining += maxAmount;
                feesToPay.push({
                    input: input,
                    amount: maxAmount,
                    feeId: input.getAttribute('data-fee-id')
                });
            }
        });
        
        if (totalRemaining <= 0) {
            alert('لا توجد رسوم مستحقة للسداد');
            return;
        }
        
        // تحديد المبلغ الذي يمكن سداده من الرصيد الإضافي
        const payableAmount = Math.min(additionalBalance, totalRemaining);
        
        if (payableAmount < totalRemaining) {
            if (!confirm(`الرصيد الإضافي (${formatCurrency(additionalBalance)} <?php echo CURRENCY; ?>) لا يكفي لسداد جميع الرسوم المستحقة (${formatCurrency(totalRemaining)} <?php echo CURRENCY; ?>). سيتم سداد ${formatCurrency(payableAmount)} <?php echo CURRENCY; ?> فقط. هل تريد المتابعة؟`)) {
                return;
            }
        } else {
            if (!confirm(`سيتم سداد جميع الرسوم المستحقة (${formatCurrency(totalRemaining)} <?php echo CURRENCY; ?>) من الرصيد الإضافي. هل تريد المتابعة؟`)) {
                return;
            }
        }
        
        // توزيع المبلغ على الرسوم المستحقة
        let remainingBalance = payableAmount;
        
        feesToPay.forEach(fee => {
            if (remainingBalance <= 0) return;
            
            const amountToPay = Math.min(fee.amount, remainingBalance);
            fee.input.value = amountToPay;
            validateFeeAmount(fee.input, fee.amount);
            remainingBalance -= amountToPay;
        });
        
        // إضافة حقل مخفي لتحديد أن المدفوعات من الرصيد الإضافي
        let additionalPaymentField = document.getElementById('additional_payment_flag');
        if (!additionalPaymentField) {
            additionalPaymentField = document.createElement('input');
            additionalPaymentField.type = 'hidden';
            additionalPaymentField.name = 'additional_payment_flag';
            additionalPaymentField.id = 'additional_payment_flag';
            additionalPaymentField.value = '1';
            document.getElementById('paymentForm').appendChild(additionalPaymentField);
        } else {
            additionalPaymentField.value = '1';
        }
        
        // إضافة حقل مخفي لحساب المبلغ المستخدم من الرصيد الإضافي
        let usedAdditionalField = document.getElementById('used_additional_amount');
        if (!usedAdditionalField) {
            usedAdditionalField = document.createElement('input');
            usedAdditionalField.type = 'hidden';
            usedAdditionalField.name = 'used_additional_amount';
            usedAdditionalField.id = 'used_additional_amount';
            usedAdditionalField.value = payableAmount;
            document.getElementById('paymentForm').appendChild(usedAdditionalField);
        } else {
            usedAdditionalField.value = payableAmount;
        }
        
        // إخفاء حقل المبلغ الإضافي أو تعطيله بدلاً من تعيين قيمة سالبة
        document.getElementById('additional_amount').value = '0';
        document.getElementById('additional_amount').style.display = 'none';
        
        // إضافة رسالة توضيحية بدلاً من الحقل
        let additionalMessage = document.getElementById('additional_payment_message');
        if (!additionalMessage) {
            additionalMessage = document.createElement('div');
            additionalMessage.id = 'additional_payment_message';
            additionalMessage.className = 'additional-payment-message';
            additionalMessage.innerHTML = `
                <div style="background: rgba(16, 185, 129, 0.1); padding: 10px; border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.2); margin-top: 10px;">
                    <i class="fas fa-info-circle" style="color: var(--success);"></i>
                    <span style="color: var(--success); font-weight: 600;">
                        سيتم خصم ${formatCurrency(payableAmount)} <?php echo CURRENCY; ?> من الرصيد الإضافي تلقائياً
                    </span>
                </div>
            `;
            document.querySelector('.additional-amount-section').appendChild(additionalMessage);
        } else {
            additionalMessage.innerHTML = `
                <div style="background: rgba(16, 185, 129, 0.1); padding: 10px; border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.2); margin-top: 10px;">
                    <i class="fas fa-info-circle" style="color: var(--success);"></i>
                    <span style="color: var(--success); font-weight: 600;">
                        سيتم خصم ${formatCurrency(payableAmount)} <?php echo CURRENCY; ?> من الرصيد الإضافي تلقائياً
                    </span>
                </div>
            `;
        }
        
        updateTotalAmount();
        
        // عرض رسالة تأكيد
        document.getElementById('amountHelpText').innerHTML = 
            `<span style="color: var(--success);">
                <i class="fas fa-check-circle"></i> 
                تم تعبئة المبالغ من الرصيد الإضافي - المبلغ المسدد: ${formatCurrency(payableAmount)} <?php echo CURRENCY; ?>
                <br><small style="color: var(--info);">سيتم خصم ${formatCurrency(payableAmount)} <?php echo CURRENCY; ?> من الرصيد الإضافي تلقائياً عند التسجيل</small>
            </span>`;
    }

    function getStudentBalance(studentId) {
        const balanceInfo = document.getElementById('balanceInfo');
        const submitBtn = document.getElementById('submitBtn');
        const studentSelect = document.getElementById('student_id');
        const selectedOption = studentSelect.querySelector(`option[value="${studentId}"]`);
        
        if (!studentId || !selectedOption) {
            balanceInfo.style.display = 'none';
            submitBtn.disabled = true;
            document.getElementById('feesList').style.display = 'none';
            document.getElementById('totalAmountSection').style.display = 'none';
            return;
        }
        
        try {
            const balanceData = JSON.parse(selectedOption.getAttribute('data-balance'));
            
            const totalFees = parseFloat(balanceData.total_fees || 0);
            const totalPaid = parseFloat(balanceData.total_paid || 0);
            const additionalAmount = parseFloat(balanceData.additional_amount || 0);
            const remaining = parseFloat(balanceData.remaining || 0);
            const totalPayments = parseFloat(balanceData.total_payments || 0);
            
            // تحديث القيم في الواجهة
            document.getElementById('totalFees').textContent = totalFees.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            document.getElementById('totalPaid').textContent = totalPaid.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            document.getElementById('remainingAmount').textContent = remaining.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            document.getElementById('additionalAmount').textContent = additionalAmount.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            
            // حساب نسبة السداد (بناءً على الرسوم الأساسية فقط)
            const paymentPercentage = totalFees > 0 ? Math.min(100, (totalPaid / totalFees) * 100) : 100;
            
            document.getElementById('paymentPercentage').textContent = paymentPercentage.toFixed(1) + '%';
            document.getElementById('progressFill').style.width = paymentPercentage + '%';
            document.getElementById('progressText').textContent = paymentPercentage.toFixed(1) + '% من الرسوم مدفوعة';
            
            // تحديث حالة السداد
            const statusElement = document.getElementById('paymentStatus');
            const statusNotes = document.getElementById('statusNotes');
            
            if (remaining <= 0) {
                if (additionalAmount > 0) {
                    statusElement.textContent = 'مدفوع بالكامل + رصيد إضافي';
                    statusElement.style.color = 'var(--info)';
                    statusNotes.innerHTML = '<div style="color: var(--info); background: rgba(23, 162, 184, 0.1); padding: 10px; border-radius: 6px; border-right: 3px solid var(--info);"><i class="fas fa-coins"></i> جميع الرسوم مدفوعة بالكامل + ' + additionalAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' <?php echo CURRENCY; ?> رصيد إضافي</div>';
                } else {
                    statusElement.textContent = 'مدفوع بالكامل';
                    statusElement.style.color = 'var(--success)';
                    statusNotes.innerHTML = '<div style="color: var(--success); background: rgba(40, 167, 69, 0.1); padding: 10px; border-radius: 6px; border-right: 3px solid var(--success);"><i class="fas fa-check-circle"></i> جميع الرسوم مدفوعة بالكامل. يمكن إضافة مدفوعات إضافية كرصيد.</div>';
                }
            } else if (totalPaid > 0) {
                statusElement.textContent = 'مدفوع جزئياً';
                statusElement.style.color = 'var(--warning)';
                statusNotes.innerHTML = '<div style="color: var(--warning); background: rgba(255, 193, 7, 0.1); padding: 10px; border-radius: 6px; border-right: 3px solid var(--warning);"><i class="fas fa-exclamation-circle"></i> هناك ' + remaining.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' <?php echo CURRENCY; ?> رسوم مستحقة.</div>';
            } else {
                statusElement.textContent = 'غير مدفوع';
                statusElement.style.color = 'var(--danger)';
                statusNotes.innerHTML = '<div style="color: var(--danger); background: rgba(220, 53, 69, 0.1); padding: 10px; border-radius: 6px; border-right: 3px solid var(--danger);"><i class="fas fa-info-circle"></i> لم يتم سداد أي رسوم بعد.</div>';
            }
            
            statusNotes.style.display = 'block';
            balanceInfo.style.display = 'block';
            
            // تحميل الرسوم
            loadStudentFees(studentId);
            
        } catch (error) {
            console.error('Error parsing balance data:', error);
            balanceInfo.style.display = 'none';
            submitBtn.disabled = true;
        }
    }

    // دالة تحميل رسوم الطالب
    function loadStudentFees(studentId) {
        if (!studentId) {
            document.getElementById('feesList').style.display = 'none';
            document.getElementById('totalAmountSection').style.display = 'none';
            return;
        }

        const formData = new FormData();
        formData.append('action', 'get_student_fees');
        formData.append('student_id', studentId);

        fetch('payments.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(fees => {
            displayFeesTable(fees);
        })
        .catch(error => {
            console.error('Error loading student fees:', error);
            document.getElementById('feesList').style.display = 'none';
            document.getElementById('totalAmountSection').style.display = 'none';
        });
    }

    // دالة عرض جدول الرسوم مع الخصومات تحت الرقم مباشرة
    function displayFeesTable(fees) {
        const tbody = document.getElementById('feesTableBody');
        const feesList = document.getElementById('feesList');
        const totalAmountSection = document.getElementById('totalAmountSection');
        const submitBtn = document.getElementById('submitBtn');
        
        if (!fees || fees.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="padding: 20px; text-align: center; color: var(--gray);">لا توجد رسوم معلقة لهذا الطالب</td></tr>';
            feesList.style.display = 'block';
            totalAmountSection.style.display = 'block';
            submitBtn.disabled = false;
            return;
        }

        let html = '';
        let hasPendingFees = false;
        
        fees.forEach(fee => {
            const originalAmount = parseFloat(fee.original_amount) || 0;
            const discountAmount = parseFloat(fee.discount_amount) || 0;
            const finalAmount = parseFloat(fee.final_amount) || 0;
            const paidAmount = parseFloat(fee.paid_amount) || 0;
            const remaining = parseFloat(fee.remaining_amount) || 0;
            
            if (remaining > 0) {
                hasPendingFees = true;
            }
            
            let statusClass = 'status-pending';
            let statusText = 'معلق';
            
            if (fee.status === 'paid') {
                statusClass = 'status-paid';
                statusText = 'مدفوع';
            } else if (fee.status === 'partial') {
                statusClass = 'status-partial';
                statusText = 'جزئي';
            }
            
            // صف الرسمة الرئيسي
            html += `
                <tr>
                    <td><strong>${fee.fee_name}</strong></td>
                    <td>
                        <div><strong>${formatCurrency(originalAmount)} <?php echo CURRENCY; ?></strong></div>
                        ${discountAmount > 0 ? `
                        <div style="color: var(--success); font-size: 12px; margin-top: 5px;">
                            <i class="fas fa-tag"></i> خصم: -${formatCurrency(discountAmount)} <?php echo CURRENCY; ?>
                        </div>
                        ` : ''}
                    </td>
                    <td><strong>${formatCurrency(finalAmount)} <?php echo CURRENCY; ?></strong></td>
                    <td><strong>${formatCurrency(paidAmount)} <?php echo CURRENCY; ?></strong></td>
                    <td>
                        <strong style="color: ${remaining > 0 ? 'var(--warning)' : 'var(--success)'};">
                            ${formatCurrency(remaining)} <?php echo CURRENCY; ?>
                        </strong>
                    </td>
                    <td>
                        <span class="fee-status ${statusClass}">
                            ${statusText}
                        </span>
                    </td>
                    <td>
                        <input type="number" name="fee_${fee.id}" class="fee-amount-input" 
                               placeholder="0.00" step="0.01" min="0" max="${remaining}"
                               value="0" oninput="validateFeeAmount(this, ${remaining})"
                               data-fee-id="${fee.id}">
                    </td>
                </tr>
            `;
        });
        
        tbody.innerHTML = html;
        feesList.style.display = 'block';
        totalAmountSection.style.display = 'block';
        
        // تمكين زر التسجيل دائمًا
        submitBtn.disabled = false;
        
        if (!hasPendingFees) {
            document.getElementById('amountHelpText').innerHTML = '<span style="color: var(--success);"><i class="fas fa-check-circle"></i> جميع الرسوم مدفوعة بالكامل - يمكن إضافة رصيد إضافي</span>';
        }
    }

    // دالة سداد جميع الرسوم
    function payAllFees() {
        const feeInputs = document.querySelectorAll('.fee-amount-input');
        feeInputs.forEach(input => {
            const maxAmount = parseFloat(input.getAttribute('max')) || 0;
            input.value = maxAmount;
            validateFeeAmount(input, maxAmount);
        });
        updateTotalAmount();
    }

    // دالة سداد المتبقي فقط
    function payFullFees() {
        const feeInputs = document.querySelectorAll('.fee-amount-input');
        feeInputs.forEach(input => {
            const finalAmount = parseFloat(input.closest('tr').querySelector('td:nth-child(2)').textContent) || 0;
            input.value = finalAmount;
            validateFeeAmount(input, finalAmount);
        });
        updateTotalAmount();
    }

    // دالة سداد المستحق فقط
    function payRemainingFees() {
        const feeInputs = document.querySelectorAll('.fee-amount-input');
        let paidAny = false;
        let totalAmount = 0;
        
        feeInputs.forEach(input => {
            const maxAmount = parseFloat(input.getAttribute('max')) || 0;
            if (maxAmount > 0) {
                input.value = maxAmount;
                validateFeeAmount(input, maxAmount);
                paidAny = true;
                totalAmount += maxAmount;
            }
        });
        
        // تحديث الواجهة بدلاً من showMessage
        if (paidAny) {
            document.getElementById('amountHelpText').innerHTML = 
                `<span style="color: var(--success);">
                    <i class="fas fa-check-circle"></i> 
                    تم تعبئة المبالغ المستحلة تلقائياً - الإجمالي: ${formatCurrency(totalAmount)} <?php echo CURRENCY; ?>
                </span>`;
        } else {
            document.getElementById('amountHelpText').innerHTML = 
                `<span style="color: var(--info);">
                    <i class="fas fa-info-circle"></i> 
                    لا توجد مبالغ مستحقة للدفع
                </span>`;
        }
        
        updateTotalAmount();
    }

    // دالة مسح جميع الحقول
    function clearAllFees() {
        const feeInputs = document.querySelectorAll('.fee-amount-input');
        feeInputs.forEach(input => {
            input.value = 0;
            input.classList.remove('valid', 'invalid');
        });
        document.getElementById('additional_amount').value = 0;
        updateTotalAmount();
    }

    // دالة التحقق من صحة مبلغ الرسمة (معدلة للسماح بالمبالغ الإضافية)
    function validateFeeAmount(input, maxAmount) {
        const value = parseFloat(input.value) || 0;
        
        if (value > 0) {
            input.classList.add('valid');
            input.classList.remove('invalid');
        } else {
            input.classList.remove('valid', 'invalid');
        }
        
        // تحديث إجمالي المبلغ
        updateTotalAmount();
    }

    // دالة تحديث إجمالي المبلغ (معدلة بعد إزالة الملخص)
    function updateTotalAmount() {
        const feeInputs = document.querySelectorAll('.fee-amount-input');
        let feesPaid = 0;
        let hasValidAmount = false;
        
        feeInputs.forEach(input => {
            const value = parseFloat(input.value) || 0;
            if (value > 0) {
                feesPaid += value;
                hasValidAmount = true;
            }
        });
        
        const additionalAmount = parseFloat(document.getElementById('additional_amount').value) || 0;
        const totalAmount = feesPaid + additionalAmount;
        
        // تحديث المبلغ الإجمالي فقط بدون الملخص المفصل
        document.getElementById('totalAmount').textContent = formatCurrency(totalAmount) + ' <?php echo CURRENCY; ?>';
        
        // تمكين الزر دائمًا
        document.getElementById('submitBtn').disabled = false;
        
        // عرض معلومات إضافية عن الرصيد
        const studentId = document.getElementById('student_id').value;
        if (studentId) {
            const studentSelect = document.getElementById('student_id');
            const selectedOption = studentSelect.querySelector(`option[value="${studentId}"]`);
            if (selectedOption) {
                const balanceData = JSON.parse(selectedOption.getAttribute('data-balance'));
                const currentAdditionalBalance = parseFloat(balanceData.additional_amount || 0);
                
                let helpText = '';
                if (hasValidAmount || additionalAmount > 0) {
                    helpText = `<span style="color: var(--success);"><i class="fas fa-check-circle"></i> جاهز للتسجيل - الإجمالي: ${formatCurrency(totalAmount)} <?php echo CURRENCY; ?></span>`;
                    
                    if (currentAdditionalBalance > 0) {
                        helpText += `<br><small style="color: var(--info);">الرصيد الإضافي الحالي: ${formatCurrency(currentAdditionalBalance)} <?php echo CURRENCY; ?></small>`;
                    }
                } else {
                    helpText = 'أدخل المبالغ المراد تسديدها للرسوم';
                    if (currentAdditionalBalance > 0) {
                        helpText += `<br><small style="color: var(--info);">الرصيد الإضافي الحالي: ${formatCurrency(currentAdditionalBalance)} <?php echo CURRENCY; ?></small>`;
                    }
                }
                
                document.getElementById('amountHelpText').innerHTML = helpText;
            }
        }
    }

    // دالة مساعدة لتنسيق الأرقام
    function formatCurrency(amount) {
        return parseFloat(amount).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    // منع إعادة إرسال النموذج عند تحديث الصفحة
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
    </script>
</body>
</html>