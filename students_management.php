<?php
include '../includes/config.php';
include '../includes/auth.php';
include '../includes/database.php';

redirectIfNotLoggedIn();
checkPermission('staff');

$database = new Database();
$db = $database->getConnection();

$message = '';

// معالجة حذف الطالب عبر GET
if (isset($_GET['delete_id'])) {
    $student_id = intval($_GET['delete_id']);
    
    try {
        $db->beginTransaction();
        
        // 1. حذف الخصومات المرتبطة بالطالب
        $query = "DELETE FROM discounts WHERE student_id = :student_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->execute();
        
        // 2. حذف المدفوعات المرتبطة بالطالب
        $query = "DELETE FROM payments WHERE student_id = :student_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->execute();
        
        // 3. حذف الرسوم الدراسية المرتبطة بالطالب
        $query = "DELETE FROM student_fees WHERE student_id = :student_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->execute();
        
        // 4. حذف الطالب نفسه
        $query = "DELETE FROM students WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $student_id);
        
        if ($stmt->execute()) {
            $db->commit();
            $_SESSION['message'] = '<div class="alert alert-success">تم حذف الطالب وجميع بياناته المرتبطة بنجاح</div>';
        } else {
            $db->rollBack();
            $_SESSION['message'] = '<div class="alert alert-danger">حدث خطأ في حذف الطالب</div>';
        }
    } catch (PDOException $e) {
        $db->rollBack();
        $_SESSION['message'] = '<div class="alert alert-danger">خطأ في قاعدة البيانات: ' . $e->getMessage() . '</div>';
    }
    
    header("Location: students_management.php");
    exit;
}

// عرض الرسالة من الجلسة إذا وجدت
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// البحث عن الطلاب
$search = $_GET['search'] ?? '';
$stage_filter = $_GET['stage_filter'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'code_asc'; // القيمة الافتراضية: الرقم تصاعدي
$students = [];

// جلب المراحل التعليمية
$stages = $db->query("SELECT * FROM educational_stages WHERE is_active = 1 ORDER BY stage_name")->fetchAll(PDO::FETCH_ASSOC);

// بناء استعلام البحث
$query = "SELECT s.*, es.stage_name, g.grade_name 
          FROM students s 
          LEFT JOIN educational_stages es ON s.stage_id = es.id 
          LEFT JOIN grades g ON s.grade_id = g.id 
          WHERE s.is_active = 1";

$params = [];

if (!empty($search)) {
    $query .= " AND (s.full_name LIKE :search OR s.student_code LIKE :search OR s.parent_father_name LIKE :search OR s.parent_mother_name LIKE :search OR s.parent_father_phone LIKE :search OR s.parent_mother_phone LIKE :search OR s.parent_other_phone LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($stage_filter)) {
    $query .= " AND s.stage_id = :stage_id";
    $params[':stage_id'] = $stage_filter;
}

// إضافة الفرز
switch ($sort_by) {
    case 'code_asc':
        $query .= " ORDER BY CAST(s.student_code AS UNSIGNED) ASC";
        break;
    case 'code_desc':
        $query .= " ORDER BY CAST(s.student_code AS UNSIGNED) DESC";
        break;
    case 'name_asc':
        $query .= " ORDER BY s.full_name ASC";
        break;
    case 'name_desc':
        $query .= " ORDER BY s.full_name DESC";
        break;
    case 'created_at_asc':
        $query .= " ORDER BY s.created_at ASC";
        break;
    case 'created_at_desc':
        $query .= " ORDER BY s.created_at DESC";
        break;
    default:
        $query .= " ORDER BY CAST(s.student_code AS UNSIGNED) ASC";
        break;
}

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// إضافة طالب جديد
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'add_student') {
        try {
            // الحصول على أعلى كود طالب موجود في قاعدة البيانات كقيمة رقمية
            $query = "SELECT MAX(CAST(student_code AS UNSIGNED)) AS max_code FROM students WHERE LENGTH(TRIM(student_code)) > 0 AND student_code REGEXP '^[0-9]+$'";
            $result = $db->query($query)->fetch(PDO::FETCH_ASSOC);

            $max_code = $result['max_code'] ?? 0;

            if ($max_code > 0) {
                // زيادة الكود بواحد
                $next_code = $max_code + 1;
            } else {
                // البدء من 1 إذا لم يتم العثور على أي أكواد صالحة
                $next_code = 1; 
            }

            $student_code = strval($next_code);
            
            // التحقق من أن الكود ليس فارغاً
            if (empty($student_code)) {
                $student_code = "1"; // قيمة افتراضية إذا كان الكود فارغاً
            }
            
            // جمع البيانات من النموذج
            $full_name = trim($_POST['full_name']);
            $stage_id = intval($_POST['stage_id']);
            $grade_id = intval($_POST['grade_id']);
            $parent_father_name = trim($_POST['parent_father_name']);
            $parent_mother_name = trim($_POST['parent_mother_name'] ?? '');
            $parent_father_phone = trim($_POST['parent_father_phone']);
            $parent_mother_phone = trim($_POST['parent_mother_phone'] ?? '');
            $parent_other_phone = trim($_POST['parent_other_phone'] ?? '');
            $parent_email = trim($_POST['parent_email'] ?? '');
            $address = trim($_POST['address']);
            $join_date = $_POST['join_date'];
            $religion = trim($_POST['religion'] ?? '');
            $nationality = trim($_POST['nationality'] ?? '');
            $gender = trim($_POST['gender'] ?? '');
            $birth_date = $_POST['birth_date'] ?? '';
            $area_id = (!empty($_POST['area_id']) && $_POST['area_id'] != '') ? intval($_POST['area_id']) : null;

            // التحقق من البيانات المطلوبة
            if (empty($full_name) || empty($stage_id) || empty($grade_id) || empty($parent_father_name) || empty($parent_father_phone) || empty($student_code)) {
                $message = '<div class="alert alert-danger">جميع الحقول المطلوبة يجب ملؤها</div>';
            } else {
                // التحقق من عدم وجود الرقم مسبقاً
                $check_query = "SELECT id FROM students WHERE student_code = :student_code";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->bindParam(':student_code', $student_code);
                $check_stmt->execute();
                
                if ($check_stmt->fetch()) {
                    // إذا كان الرقم موجوداً، نبحث عن الرقم التالي المتاح
                    $query = "SELECT MAX(CAST(student_code AS UNSIGNED)) + 1 AS next_code FROM students";
                    $result = $db->query($query)->fetch(PDO::FETCH_ASSOC);
                    $student_code = strval($result['next_code'] ?? ($max_code + 1));
                }

                // استعلام الإدخال
                $query = "INSERT INTO students (student_code, full_name, stage_id, grade_id, parent_father_name, parent_mother_name, parent_father_phone, parent_mother_phone, parent_other_phone, parent_email, address, join_date, religion, nationality, gender, birth_date, area_id) 
                          VALUES (:student_code, :full_name, :stage_id, :grade_id, :parent_father_name, :parent_mother_name, :parent_father_phone, :parent_mother_phone, :parent_other_phone, :parent_email, :address, :join_date, :religion, :nationality, :gender, :birth_date, :area_id)";
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':student_code', $student_code);
                $stmt->bindParam(':full_name', $full_name);
                $stmt->bindParam(':stage_id', $stage_id);
                $stmt->bindParam(':grade_id', $grade_id);
                $stmt->bindParam(':parent_father_name', $parent_father_name);
                $stmt->bindParam(':parent_mother_name', $parent_mother_name);
                $stmt->bindParam(':parent_father_phone', $parent_father_phone);
                $stmt->bindParam(':parent_mother_phone', $parent_mother_phone);
                $stmt->bindParam(':parent_other_phone', $parent_other_phone);
                $stmt->bindParam(':parent_email', $parent_email);
                $stmt->bindParam(':address', $address);
                $stmt->bindParam(':join_date', $join_date);
                $stmt->bindParam(':religion', $religion);
                $stmt->bindParam(':nationality', $nationality);
                $stmt->bindParam(':gender', $gender);
                $stmt->bindParam(':birth_date', $birth_date);
                $stmt->bindParam(':area_id', $area_id);
                
                if ($stmt->execute()) {
                    $student_id = $db->lastInsertId();
                    $has_transport_fee = false;
                    $added_fees_count = 0;
                    
                    // إضافة رسوم المنطقة السكنية إذا تم اختيارها
                    if ($student_id && $area_id) {
                        // جلب سعر المنطقة
                        $area_query = "SELECT transport_price, area_name FROM residential_areas WHERE id = :area_id";
                        $area_stmt = $db->prepare($area_query);
                        $area_stmt->bindParam(':area_id', $area_id);
                        $area_stmt->execute();
                        $area = $area_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($area && $area['transport_price'] > 0) {
                            $transport_amount = $area['transport_price'];
                            $notes = "رسوم النقل - " . $area['area_name'];
                            
                            // إضافة رسم المنطقة السكنية مباشرة إلى student_fees
                            $insert_area_fee_query = "INSERT INTO student_fees (student_id, fee_type_id, original_amount, discount_amount, final_amount, paid_amount, remaining_amount, status, due_date, academic_year, area_id, notes) 
                                                      VALUES (:student_id, NULL, :amount, 0, :amount, 0, :amount, 'pending', DATE_ADD(CURDATE(), INTERVAL 30 DAY), '2024-2025', :area_id, :notes)";
                            $insert_area_fee_stmt = $db->prepare($insert_area_fee_query);
                            $insert_area_fee_stmt->bindParam(':student_id', $student_id);
                            $insert_area_fee_stmt->bindParam(':amount', $transport_amount);
                            $insert_area_fee_stmt->bindParam(':area_id', $area_id);
                            $insert_area_fee_stmt->bindParam(':notes', $notes);
                            
                            if ($insert_area_fee_stmt->execute()) {
                                $has_transport_fee = true;
                                $added_fees_count++;
                            }
                        }
                    }
                    
                    // إضافة الرسوم الدراسية للطالب الجديد بناءً على الاختيار
                    if ($student_id && isset($_POST['selected_fees_data'])) {
                        $selected_fees = json_decode($_POST['selected_fees_data'], true);
                        
                        if (!empty($selected_fees) && is_array($selected_fees)) {
                            foreach ($selected_fees as $fee_id) {
                                // جلب بيانات الرسم
                                $fee_query = "SELECT * FROM fee_types WHERE id = :fee_id";
                                $fee_stmt = $db->prepare($fee_query);
                                $fee_stmt->bindParam(':fee_id', $fee_id);
                                $fee_stmt->execute();
                                $fee = $fee_stmt->fetch(PDO::FETCH_ASSOC);
                                
                                if ($fee) {
                                    $insert_query = "INSERT INTO student_fees (student_id, fee_type_id, original_amount, discount_amount, final_amount, paid_amount, remaining_amount, status, due_date, academic_year) 
                                              VALUES (:student_id, :fee_type_id, :amount, 0, :amount, 0, :amount, 'pending', DATE_ADD(CURDATE(), INTERVAL 30 DAY), '2024-2025')";
                                    $insert_stmt = $db->prepare($insert_query);
                                    $insert_stmt->bindParam(':student_id', $student_id);
                                    $insert_stmt->bindParam(':fee_type_id', $fee_id);
                                    $insert_stmt->bindParam(':amount', $fee['amount']);
                                    
                                    if ($insert_stmt->execute()) {
                                        $added_fees_count++;
                                    }
                                }
                            }
                        }
                    }
                    
                    // إعداد رسالة النجاح المناسبة
                    $total_fees_count = $added_fees_count;
                    
                    if ($has_transport_fee) {
                        $_SESSION['message'] = '<div class="alert alert-success">تم إضافة الطالب بنجاح وتوليد الرقم: ' . $student_code . ' مع ' . $total_fees_count . ' من الرسوم (بما في ذلك رسوم النقل)</div>';
                    } else if ($added_fees_count > 0) {
                        $_SESSION['message'] = '<div class="alert alert-success">تم إضافة الطالب بنجاح وتوليد الرقم: ' . $student_code . ' مع ' . $added_fees_count . ' من الرسوم</div>';
                    } else {
                        $_SESSION['message'] = '<div class="alert alert-warning">تم إضافة الطالب بنجاح ولكن لم يتم اختيار أي رسوم</div>';
                    }
                    
                    header("Location: students_management.php");
                    exit;
                } else {
                    $error_info = $stmt->errorInfo();
                    $message = '<div class="alert alert-danger">حدث خطأ في إضافة الطالب: ' . $error_info[2] . '</div>';
                }
            }
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">خطأ في قاعدة البيانات: ' . $e->getMessage() . '</div>';
        } catch (Exception $e) {
            $message = '<div class="alert alert-danger">حدث خطأ: ' . $e->getMessage() . '</div>';
        }
    }
    
    // تعديل طالب
    if ($_POST['action'] == 'edit_student') {
        try {
            $student_id = intval($_POST['student_id']);
            $full_name = trim($_POST['full_name']);
            $stage_id = intval($_POST['stage_id']);
            $grade_id = intval($_POST['grade_id']);
            $parent_father_name = trim($_POST['parent_father_name']);
            $parent_mother_name = trim($_POST['parent_mother_name'] ?? '');
            $parent_father_phone = trim($_POST['parent_father_phone']);
            $parent_mother_phone = trim($_POST['parent_mother_phone'] ?? '');
            $parent_other_phone = trim($_POST['parent_other_phone'] ?? '');
            $parent_email = trim($_POST['parent_email'] ?? '');
            $address = trim($_POST['address']);
            $join_date = $_POST['join_date'];
            $religion = trim($_POST['religion'] ?? '');
            $nationality = trim($_POST['nationality'] ?? '');
            $gender = trim($_POST['gender'] ?? '');
            $birth_date = $_POST['birth_date'] ?? '';
            $area_id = (!empty($_POST['area_id']) && $_POST['area_id'] != '') ? intval($_POST['area_id']) : null;
            
            $query = "UPDATE students SET 
                      full_name = :full_name, 
                      stage_id = :stage_id, 
                      grade_id = :grade_id, 
                      parent_father_name = :parent_father_name,
                      parent_mother_name = :parent_mother_name,
                      parent_father_phone = :parent_father_phone,
                      parent_mother_phone = :parent_mother_phone,
                      parent_other_phone = :parent_other_phone,
                      parent_email = :parent_email, 
                      address = :address, 
                      join_date = :join_date,
                      religion = :religion,
                      nationality = :nationality,
                      gender = :gender,
                      birth_date = :birth_date,
                      area_id = :area_id
                      WHERE id = :id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':full_name', $full_name);
            $stmt->bindParam(':stage_id', $stage_id);
            $stmt->bindParam(':grade_id', $grade_id);
            $stmt->bindParam(':parent_father_name', $parent_father_name);
            $stmt->bindParam(':parent_mother_name', $parent_mother_name);
            $stmt->bindParam(':parent_father_phone', $parent_father_phone);
            $stmt->bindParam(':parent_mother_phone', $parent_mother_phone);
            $stmt->bindParam(':parent_other_phone', $parent_other_phone);
            $stmt->bindParam(':parent_email', $parent_email);
            $stmt->bindParam(':address', $address);
            $stmt->bindParam(':join_date', $join_date);
            $stmt->bindParam(':religion', $religion);
            $stmt->bindParam(':nationality', $nationality);
            $stmt->bindParam(':gender', $gender);
            $stmt->bindParam(':birth_date', $birth_date);
            $stmt->bindParam(':area_id', $area_id);
            $stmt->bindParam(':id', $student_id);
            
            if ($stmt->execute()) {
                // تحديث رسوم النقل إذا تغيرت المنطقة السكنية
                if ($area_id) {
                    // جلب سعر المنطقة الجديدة
                    $area_query = "SELECT transport_price, area_name FROM residential_areas WHERE id = :area_id";
                    $area_stmt = $db->prepare($area_query);
                    $area_stmt->bindParam(':area_id', $area_id);
                    $area_stmt->execute();
                    $area = $area_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($area) {
                        $transport_amount = $area['transport_price'];
                        $notes = "رسوم النقل - " . $area['area_name'];
                        
                        // البحث عن رسم المنطقة السكنية الحالي للطالب
                        $area_fee_query = "SELECT id, paid_amount FROM student_fees WHERE student_id = :student_id AND area_id IS NOT NULL";
                        $area_fee_stmt = $db->prepare($area_fee_query);
                        $area_fee_stmt->bindParam(':student_id', $student_id);
                        $area_fee_stmt->execute();
                        $existing_area_fee = $area_fee_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($existing_area_fee) {
                            // تحديث رسم المنطقة السكنية الحالي
                            $remaining_amount = $transport_amount - $existing_area_fee['paid_amount'];
                            $status = $remaining_amount <= 0 ? 'paid' : ($existing_area_fee['paid_amount'] > 0 ? 'partial' : 'pending');
                            
                            $update_area_fee_query = "UPDATE student_fees SET 
                                                     original_amount = :amount, 
                                                     final_amount = :amount,
                                                     remaining_amount = :remaining_amount,
                                                     status = :status,
                                                     area_id = :area_id, 
                                                     notes = :notes
                                                     WHERE id = :id";
                            $update_area_fee_stmt = $db->prepare($update_area_fee_query);
                            $update_area_fee_stmt->bindParam(':amount', $transport_amount);
                            $update_area_fee_stmt->bindParam(':remaining_amount', $remaining_amount);
                            $update_area_fee_stmt->bindParam(':status', $status);
                            $update_area_fee_stmt->bindParam(':area_id', $area_id);
                            $update_area_fee_stmt->bindParam(':notes', $notes);
                            $update_area_fee_stmt->bindParam(':id', $existing_area_fee['id']);
                            $update_area_fee_stmt->execute();
                        } else {
                            // إنشاء رسم منطقة سكنية جديد
                            $insert_area_fee_query = "INSERT INTO student_fees (student_id, fee_type_id, original_amount, discount_amount, final_amount, paid_amount, remaining_amount, status, due_date, academic_year, area_id, notes) 
                                                      VALUES (:student_id, NULL, :amount, 0, :amount, 0, :amount, 'pending', DATE_ADD(CURDATE(), INTERVAL 30 DAY), '2024-2025', :area_id, :notes)";
                            $insert_area_fee_stmt = $db->prepare($insert_area_fee_query);
                            $insert_area_fee_stmt->bindParam(':student_id', $student_id);
                            $insert_area_fee_stmt->bindParam(':amount', $transport_amount);
                            $insert_area_fee_stmt->bindParam(':area_id', $area_id);
                            $insert_area_fee_stmt->bindParam(':notes', $notes);
                            $insert_area_fee_stmt->execute();
                        }
                    }
                }
                
                // إدارة الرسوم عند التعديل
                if (isset($_POST['selected_fees_data'])) {
                    $selected_fees = json_decode($_POST['selected_fees_data'], true);
                    
                    // جلب الرسوم الحالية للطالب (باستثناء رسوم النقل)
                    $current_fees_query = "SELECT sf.fee_type_id 
                                          FROM student_fees sf 
                                          JOIN fee_types ft ON sf.fee_type_id = ft.id 
                                          WHERE sf.student_id = :student_id AND ft.fee_name != 'رسوم النقل'";
                    $current_fees_stmt = $db->prepare($current_fees_query);
                    $current_fees_stmt->bindParam(':student_id', $student_id);
                    $current_fees_stmt->execute();
                    $current_fees = $current_fees_stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    // إضافة الرسوم الجديدة المختارة
                    if (!empty($selected_fees)) {
                        foreach ($selected_fees as $fee_id) {
                            // إذا كانت الرسوم غير موجودة حالياً، نضيفها
                            if (!in_array($fee_id, $current_fees)) {
                                // جلب بيانات الرسم
                                $fee_query = "SELECT * FROM fee_types WHERE id = :fee_id";
                                $fee_stmt = $db->prepare($fee_query);
                                $fee_stmt->bindParam(':fee_id', $fee_id);
                                $fee_stmt->execute();
                                $fee = $fee_stmt->fetch(PDO::FETCH_ASSOC);
                                
                                if ($fee) {
                                    $insert_query = "INSERT INTO student_fees (student_id, fee_type_id, original_amount, final_amount, status, due_date) 
                                              VALUES (:student_id, :fee_type_id, :amount, :amount, 'pending', DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
                                    $insert_stmt = $db->prepare($insert_query);
                                    $insert_stmt->bindParam(':student_id', $student_id);
                                    $insert_stmt->bindParam(':fee_type_id', $fee_id);
                                    $insert_stmt->bindParam(':amount', $fee['amount']);
                                    $insert_stmt->execute();
                                }
                            }
                        }
                    }
                    
                    // إزالة الرسوم الملغاة (الاختيارية فقط)
                    foreach ($current_fees as $current_fee_id) {
                        if (!in_array($current_fee_id, $selected_fees)) {
                            // التحقق إذا كانت الرسوم اختيارية
                            $fee_type_query = "SELECT is_mandatory FROM fee_types WHERE id = :fee_id";
                            $fee_type_stmt = $db->prepare($fee_type_query);
                            $fee_type_stmt->bindParam(':fee_id', $current_fee_id);
                            $fee_type_stmt->execute();
                            $fee_type = $fee_type_stmt->fetch(PDO::FETCH_ASSOC);
                            
                            // حذف فقط إذا كانت الرسوم اختيارية ولم يتم دفعها
                            if ($fee_type && $fee_type['is_mandatory'] == 0) {
                                $check_payment_query = "SELECT COUNT(*) FROM payments WHERE student_fee_id IN (SELECT id FROM student_fees WHERE student_id = :student_id AND fee_type_id = :fee_id)";
                                $check_payment_stmt = $db->prepare($check_payment_query);
                                $check_payment_stmt->bindParam(':student_id', $student_id);
                                $check_payment_stmt->bindParam(':fee_id', $current_fee_id);
                                $check_payment_stmt->execute();
                                $payment_count = $check_payment_stmt->fetchColumn();
                                
                                if ($payment_count == 0) {
                                    $delete_query = "DELETE FROM student_fees WHERE student_id = :student_id AND fee_type_id = :fee_id";
                                    $delete_stmt = $db->prepare($delete_query);
                                    $delete_stmt->bindParam(':student_id', $student_id);
                                    $delete_stmt->bindParam(':fee_id', $current_fee_id);
                                    $delete_stmt->execute();
                                }
                            }
                        }
                    }
                }
                
                $_SESSION['message'] = '<div class="alert alert-success">تم تعديل بيانات الطالب بنجاح</div>';
                header("Location: students_management.php");
                exit;
            } else {
                $error_info = $stmt->errorInfo();
                $message = '<div class="alert alert-danger">حدث خطأ في تعديل بيانات الطالب: ' . $error_info[2] . '</div>';
            }
        } catch (PDOException $e) {
            $message = '<div class="alert alert-danger">خطأ في قاعدة البيانات: ' . $e->getMessage() . '</div>';
        }
    }
}

// جلب بيانات طالب للتعديل
$edit_student = null;
if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    $query = "SELECT s.*, es.stage_name, g.grade_name 
              FROM students s 
              LEFT JOIN educational_stages es ON s.stage_id = es.id 
              LEFT JOIN grades g ON s.grade_id = g.id 
              WHERE s.id = :id AND s.is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $edit_id);
    $stmt->execute();
    $edit_student = $stmt->fetch(PDO::FETCH_ASSOC);
}

// جلب بيانات طالب للعرض
$view_student = null;
$student_fees = [];
$student_payments = [];
$student_discounts = [];

if (isset($_GET['view_id'])) {
    $view_id = intval($_GET['view_id']);
    $query = "SELECT s.*, es.stage_name, g.grade_name 
              FROM students s 
              LEFT JOIN educational_stages es ON s.stage_id = es.id 
              LEFT JOIN grades g ON s.grade_id = g.id 
              WHERE s.id = :id AND s.is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $view_id);
    $stmt->execute();
    $view_student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // جلب رسوم الطالب
    if ($view_student) {
        $query = "SELECT sf.*, ft.fee_name, ft.amount as original_amount 
                  FROM student_fees sf 
                  JOIN fee_types ft ON sf.fee_type_id = ft.id 
                  WHERE sf.student_id = :student_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':student_id', $view_id);
        $stmt->execute();
        $student_fees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// جلب الصفوف الدراسية من قاعدة البيانات
$grades_query = "SELECT g.*, es.stage_name 
                 FROM grades g 
                 JOIN educational_stages es ON g.stage_id = es.id 
                 WHERE g.is_active = 1 
                 ORDER BY es.stage_name, g.grade_order";
$grades = $db->query($grades_query)->fetchAll(PDO::FETCH_ASSOC);

// جلب المناطق السكنية
$areas_query = "SELECT * FROM residential_areas WHERE is_active = 1 ORDER BY area_name";
$areas_result = $db->query($areas_query);
$areas = $areas_result->fetchAll(PDO::FETCH_ASSOC);

// دالة حساب رصيد الطالب
function getStudentBalance($student_id, $db) {
    // حساب إجمالي الرسوم
    $fees_query = "SELECT COALESCE(SUM(final_amount), 0) as total_fees 
                   FROM student_fees 
                   WHERE student_id = :student_id";
    $fees_stmt = $db->prepare($fees_query);
    $fees_stmt->bindParam(':student_id', $student_id);
    $fees_stmt->execute();
    $fees_result = $fees_stmt->fetch(PDO::FETCH_ASSOC);
    $total_fees = $fees_result['total_fees'];

    // حساب إجمالي المدفوعات
    $payments_query = "SELECT COALESCE(SUM(amount), 0) as total_paid 
                       FROM payments 
                       WHERE student_id = :student_id";
    $payments_stmt = $db->prepare($payments_query);
    $payments_stmt->bindParam(':student_id', $student_id);
    $payments_stmt->execute();
    $payments_result = $payments_stmt->fetch(PDO::FETCH_ASSOC);
    $total_paid = $payments_result['total_paid'];

    // حساب المبلغ المتبقي
    $remaining = $total_fees - $total_paid;

    return [
        'total_fees' => $total_fees,
        'total_paid' => $total_paid,
        'remaining' => $remaining
    ];
}

// جلب المناطق السكنية الرئيسية
$main_areas_query = "SELECT * FROM residential_areas WHERE area_type = 'main' AND is_active = 1 ORDER BY area_name";
$main_areas_result = $db->query($main_areas_query);
$main_areas = $main_areas_result ? $main_areas_result->fetchAll(PDO::FETCH_ASSOC) : [];

?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الطلاب - الموظف - نظام المحاسبة المدرسية</title>
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
            background-color: #7594c3;
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
        
        /* المحتوى الرئيسي */
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
        
        /* تنسيقات النماذج المحسنة */
        .form-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #e8f0fe;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }

        .form-section {
            background: linear-gradient(135deg, #f8faff 0%, #f0f4ff 100%);
            border: 2px solid #e3eeff;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
        }

        .form-section::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(135deg, #4f6bff, #2c5aa0);
        }

        .form-section-title {
            color: #2c5aa0;
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e3eeff;
        }

        .form-section-title i {
            color: #4f6bff;
            font-size: 22px;
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #2d3748;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-label i {
            color: #4f6bff;
            font-size: 16px;
        }

        .form-control {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: white;
            color: #2d3748;
            font-family: 'Tajawal', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: #4f6bff;
            box-shadow: 0 0 0 3px rgba(79, 107, 255, 0.1);
            background: #fafbff;
        }

        .form-control:hover {
            border-color: #cbd5e0;
        }

        .phone-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        /* تنسيق الحقول المطلوبة */
        .required-field .form-label::after {
            content: " *";
            color: #e53e3e;
            font-weight: bold;
        }

        .required-field .form-control {
            border-color: #fed7d7;
            background: #fff5f5;
        }

        .required-field .form-control:focus {
            border-color: #e53e3e;
            background: #fff5f5;
        }

        /* تنسيق الأزرار المحسن */
        .submit-btn {
            background: linear-gradient(135deg, #48bb78, #38a169);
            color: white;
            padding: 16px 32px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 10px;
            box-shadow: 0 4px 15px rgba(72, 187, 120, 0.3);
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(72, 187, 120, 0.4);
            background: linear-gradient(135deg, #38a169, #2f855a);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #718096, #4a5568);
            margin-top: 10px;
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #4a5568, #2d3748);
            box-shadow: 0 6px 20px rgba(113, 128, 150, 0.4);
        }


        /* تنسيق قسم الرسوم */
        .fees-section-main {
            background: linear-gradient(135deg, #faf5ff, #e9d8fd);
            border: 2px solid #d6bcfa;
        }

        .fees-section-main::before {
            background: linear-gradient(135deg, #9f7aea, #805ad5);
        }

        .fees-section-main .form-section-title {
            color: #6b46c1;
        }

        .fees-section-main .form-section-title i {
            color: #9f7aea;
        }

        .equal-width {
            grid-template-columns: 1fr 1fr;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        /* تنسيق شريط البحث */
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
        
        /* تصميم بطاقات الطلاب */
        .students-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .student-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-light);
            transition: var(--transition);
        }
        
        .student-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .student-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--gray-light);
        }
        
        .student-name {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }
        
        .student-code {
            font-family: 'Courier New', monospace;
            background: var(--primary-light);
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            color: var(--primary);
        }
        
        .stage-badge {
            background: linear-gradient(135deg, #8b5cf6, #a855f7);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .student-details {
            margin-bottom: 15px;
        }
        
        .detail-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 8px;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .detail-label {
            font-weight: 600;
            color: var(--dark);
            font-size: 12px;
        }
        
        .detail-value {
            color: var(--gray);
            font-size: 13px;
            line-height: 1.4;
        }
        
        .student-balance {
            background: var(--light);
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 15px;
        }
        
        .balance-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 5px;
        }
        
        .balance-label {
            font-size: 12px;
            font-weight: 600;
        }
        
        .balance-value {
            font-size: 12px;
            font-weight: 700;
        }
        
        .total-fees {
            color: var(--dark);
        }
        
        .total-paid {
            color: var(--success);
        }
        
        .remaining {
            color: var(--warning);
        }
        
        .action-btns {
            display: flex;
            gap: 8px;
            justify-content: center;
        }
        
        .btn {
            padding: 8px 12px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: var(--transition);
            flex: 1;
            justify-content: center;
        }
        
        .btn-view {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        
        .btn-edit {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }
        
        .btn-delete {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        /* خيارات الفرز */
        .sort-options {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
            padding: 15px;
            background: rgba(102, 126, 234, 0.05);
            border-radius: 12px;
            border: 1px solid rgba(102, 126, 234, 0.1);
        }
        
        .sort-label {
            font-weight: 600;
            color: var(--dark);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .sort-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .sort-btn {
            background: white;
            color: var(--dark);
            border: 1px solid var(--gray-light);
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            text-decoration: none;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .sort-btn:hover {
            background: rgba(102, 126, 234, 0.1);
            border-color: var(--primary);
            transform: translateY(-1px);
        }
        
        .sort-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .students-count {
            margin-right: auto;
            font-size: 14px;
            color: var(--gray);
            font-weight: 500;
        }
        
        /* نافذة عرض بيانات الطالب */
        .student-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            backdrop-filter: blur(5px);
        }
        
        .modal-content {
            background: white;
            margin: 30px auto;
            padding: 0;
            border-radius: 20px;
            width: 95%;
            max-width: 1000px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 20px 25px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .close-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .close-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        .student-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            padding: 25px;
        }
        
        .info-card {
            background: var(--light);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid var(--gray-light);
        }
        
        .info-card h3 {
            color: var(--dark);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 16px;
        }
        
        .info-item {
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        
        .info-label {
            font-weight: 600;
            color: var(--dark);
            font-size: 13px;
        }
        
        .info-value {
            color: var(--gray);
            text-align: left;
            font-size: 13px;
        }
        
        /* أنماط جديدة للرسوم */
        .fees-selection-container {
            margin-top: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 2px solid #e9ecef;
        }
        
        .fees-section {
            margin-bottom: 20px;
        }
        
        .fees-section-title {
            color: var(--primary);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
        }
        
        .fee-selection-item {
            padding: 12px;
            margin: 8px 0;
            border: 1px solid #ddd;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: var(--transition);
            background: white;
        }
        
        .fee-selection-item:hover {
            background: rgba(102, 126, 234, 0.05);
            transform: translateX(-5px);
        }
        
        .fee-name {
            font-weight: bold;
            flex: 1;
        }
        
        .fee-amount {
            font-weight: 600;
            color: var(--success);
        }
        
        .mandatory-badge {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .optional-badge {
            background: linear-gradient(135deg, #27ae60, #219a52);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .total-fees-display {
            margin-top: 20px;
            padding: 15px;
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
            border-radius: 8px;
            font-weight: bold;
            text-align: center;
            font-size: 16px;
        }
        
        .no-fees-message {
            text-align: center;
            padding: 20px;
            color: var(--gray);
            font-style: italic;
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
        
        .area-price-display {
            margin-top: 10px;
            padding: 12px;
            background: #e8f5e8;
            border: 1px solid #4caf50;
            border-radius: 8px;
            display: none;
        }

        .area-price-display .price-box {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .area-price-display strong {
            color: #2e7d32;
        }

        #studentSelectedAreaPrice {
            font-weight: bold;
            color: #1b5e20;
            font-size: 16px;
        }

        /* تنسيق للهواتف المحمولة */
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
            
            .form-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .phone-grid {
                grid-template-columns: 1fr;
            }
            
            .form-section {
                padding: 20px;
            }
            
            .form-container {
                padding: 20px;
            }
            
            .phone-input-group .form-control {
                padding: 14px 100px 14px 16px;
            }
            

            
            .students-cards {
                grid-template-columns: 1fr;
            }
            
            .student-info-grid {
                grid-template-columns: 1fr;
            }
        }

        /* تنسيق للشاشات المتوسطة */
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
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr 1fr;
            }
        }

        /* تنسيق للشاشات الصغيرة جداً */
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
            
            .card-body {
                padding: 12px;
            }
        }

        /* تأثيرات بصرية إضافية */
        .form-hint {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .error-message {
            color: #e53e3e;
            font-size: 12px;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .success-message {
            color: #48bb78;
            font-size: 12px;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        @keyframes fade-in {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fade-in 0.5s ease-out;
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
                <li><a href="students_management.php" class="active"><i class="fas fa-users"></i><span>إدارة الطلاب</span></a></li>
                <li><a href="payments.php"><i class="fas fa-credit-card"></i><span>تسجيل المدفوعات</span></a></li>
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
                    <i class="fas fa-user-graduate"></i>
                    إدارة الطلاب
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
                            <a href="students_management.php" class="search-btn" style="background: linear-gradient(135deg, var(--gray), #475569);">
                                <i class="fas fa-list"></i>
                                عرض الكل
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
                
                <div class="dashboard-grid">
                    <!-- قسم إضافة/تعديل الطالب -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-<?php echo $edit_student ? 'edit' : 'plus'; ?>"></i>
                                <?php echo $edit_student ? 'تعديل بيانات الطالب' : 'إضافة طالب جديد'; ?>
                            </h2>
                        </div>
                        <div class="card-body">
                            <div class="form-container">
                                <form method="POST" id="studentForm">
                                    <input type="hidden" name="action" value="<?php echo $edit_student ? 'edit_student' : 'add_student'; ?>">
                                    <?php if ($edit_student): ?>
                                        <input type="hidden" name="student_id" value="<?php echo $edit_student['id']; ?>">
                                    <?php endif; ?>
                                    
                                    <!-- قسم المعلومات الشخصية -->
                                    <div class="form-section personal-info-section">
                                        <h3 class="form-section-title">
                                            <i class="fas fa-user-circle"></i>
                                            المعلومات الشخصية
                                        </h3>
                                        
                                        <div class="form-grid">
                                            <div class="form-group">
                                                <label class="form-label required-field">
                                                    <i class="fas fa-user"></i>
                                                    اسم الطالب الكامل
                                                </label>
                                                <input type="text" name="full_name" class="form-control" required 
                                                       value="<?php echo $edit_student ? htmlspecialchars($edit_student['full_name']) : ''; ?>" 
                                                       placeholder="أدخل اسم الطالب ثلاثي">
                                            </div>
                                            
                                            <div class="form-group">
                                                <label class="form-label required-field">
                                                    <i class="fas fa-graduation-cap"></i>
                                                    المرحلة التعليمية
                                                </label>
                                                <select name="stage_id" class="form-control" required id="stageSelect">
                                                    <option value="">اختر المرحلة</option>
                                                    <?php foreach ($stages as $stage): ?>
                                                    <option value="<?php echo $stage['id']; ?>" 
                                                        <?php echo ($edit_student && $edit_student['stage_id'] == $stage['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($stage['stage_name']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="form-grid">
                                            <div class="form-group">
                                                <label class="form-label required-field">
                                                    <i class="fas fa-book"></i>
                                                    الصف الدراسي
                                                </label>
                                                <select name="grade_id" class="form-control" required id="gradeSelect">
                                                    <option value="">اختر الصف الدراسي</option>
                                                    <?php if ($edit_student): ?>
                                                        <?php 
                                                        $grades_query = "SELECT * FROM grades WHERE stage_id = :stage_id AND is_active = 1 ORDER BY grade_order";
                                                        $grades_stmt = $db->prepare($grades_query);
                                                        $grades_stmt->bindParam(':stage_id', $edit_student['stage_id']);
                                                        $grades_stmt->execute();
                                                        $current_grades = $grades_stmt->fetchAll(PDO::FETCH_ASSOC);
                                                        ?>
                                                        <?php foreach ($current_grades as $grade): ?>
                                                        <option value="<?php echo $grade['id']; ?>" 
                                                            <?php echo ($edit_student && $edit_student['grade_id'] == $grade['id']) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($grade['grade_name']); ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label class="form-label required-field">
                                                    <i class="fas fa-venus-mars"></i>
                                                    النوع
                                                </label>
                                                <select name="gender" class="form-control" required>
                                                    <option value="">اختر النوع</option>
                                                    <option value="ذكر" <?php echo ($edit_student && $edit_student['gender'] == 'ذكر') ? 'selected' : ''; ?>>ذكر</option>
                                                    <option value="أنثى" <?php echo ($edit_student && $edit_student['gender'] == 'أنثى') ? 'selected' : ''; ?>>أنثى</option>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="form-grid">
                                            <div class="form-group">
                                                <label class="form-label">
                                                    <i class="fas fa-birthday-cake"></i>
                                                    تاريخ الميلاد
                                                </label>
                                                <input type="date" name="birth_date" class="form-control" 
                                                       value="<?php echo $edit_student ? $edit_student['birth_date'] : ''; ?>">
                                            </div>
                                            
                                            <div class="form-group">
                                                <label class="form-label required-field">
                                                    <i class="fas fa-calendar-plus"></i>
                                                    تاريخ الالتحاق
                                                </label>
                                                <input type="date" name="join_date" class="form-control" required 
                                                       value="<?php echo $edit_student ? $edit_student['join_date'] : date('Y-m-d'); ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- قسم أولياء الأمور -->
                                    <div class="form-section parents-section">
                                        <h3 class="form-section-title">
                                            <i class="fas fa-users"></i>
                                            معلومات أولياء الأمور
                                        </h3>
                                        
                                        <div class="form-grid equal-width">
                                            <div class="form-group">
                                                <label class="form-label required-field">
                                                    <i class="fas fa-male"></i>
                                                    اسم ولي الأمر (الأب)
                                                </label>
                                                <input type="text" name="parent_father_name" class="form-control" required 
                                                       value="<?php echo $edit_student ? htmlspecialchars($edit_student['parent_father_name'] ?? $edit_student['parent_name'] ?? '') : ''; ?>" 
                                                       placeholder="اسم ولي الأمر (الأب)">
                                            </div>

                                            <div class="form-group">
                                                <label class="form-label">
                                                    <i class="fas fa-female"></i>
                                                    اسم ولي الأمر (الأم)
                                                </label>
                                                <input type="text" name="parent_mother_name" class="form-control" 
                                                       value="<?php echo $edit_student ? htmlspecialchars($edit_student['parent_mother_name'] ?? '') : ''; ?>" 
                                                       placeholder="اسم ولي الأمر (الأم)">
                                            </div>
                                        </div>

                                        <!-- قسم الهواتف -->
                                        <div class="phone-section">
                                            <h4 style="color: #2c5aa0; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                                                <i class="fas fa-phone-alt"></i>
                                                معلومات الاتصال
                                            </h4>
                                            
                                            <div class="phone-grid">
                                                <div class="phone-input-group">
                                                    <label class="form-label required-field">تليفون ولي الأمر</label>
                                                    <input type="text" name="parent_father_phone" class="form-control" required 
                                                           value="<?php echo $edit_student ? htmlspecialchars($edit_student['parent_father_phone'] ?? $edit_student['parent_phone'] ?? '') : ''; ?>" 
                                                           placeholder="رقم هاتف الأب">
                                                </div>
                                                
                                                <div class="phone-input-group">
                                                    <label class="form-label">تليفون ولي الأمر</label>
                                                    <input type="text" name="parent_mother_phone" class="form-control" 
                                                           value="<?php echo $edit_student ? htmlspecialchars($edit_student['parent_mother_phone'] ?? '') : ''; ?>" 
                                                           placeholder="رقم هاتف الأم">
                                                </div>
                                            </div>

                                            <div class="form-group" style="margin-top: 20px;">
                                                <div class="phone-input-group">
                                                    <label class="form-label">تليفون آخر</label>
                                                    <input type="text" name="parent_other_phone" class="form-control" 
                                                           value="<?php echo $edit_student ? htmlspecialchars($edit_student['parent_other_phone'] ?? '') : ''; ?>" 
                                                           placeholder="رقم هاتف إضافي">
                                                </div>
                                            </div>

                                            <div class="form-group">
                                                <label class="form-label">
                                                    <i class="fas fa-envelope"></i>
                                                    البريد الإلكتروني (اختياري)
                                                </label>
                                                <input type="email" name="parent_email" class="form-control" 
                                                       value="<?php echo $edit_student ? htmlspecialchars($edit_student['parent_email'] ?? '') : ''; ?>" 
                                                       placeholder="البريد الإلكتروني">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- قسم المعلومات الإضافية -->
                                    <div class="form-section">
                                        <h3 class="form-section-title">
                                            <i class="fas fa-info-circle"></i>
                                            معلومات إضافية
                                        </h3>
                                        
                                        <div class="form-grid">
                                            <div class="form-group">
                                                <label class="form-label">
                                                    <i class="fas fa-pray"></i>
                                                    الديانة
                                                </label>
                                                <select name="religion" class="form-control">
                                                    <option value="">اختر الديانة</option>
                                                    <option value="مسلم" <?php echo ($edit_student && $edit_student['religion'] == 'مسلم') ? 'selected' : ''; ?>>مسلم</option>
                                                    <option value="مسيحي" <?php echo ($edit_student && $edit_student['religion'] == 'مسيحي') ? 'selected' : ''; ?>>مسيحي</option>
                                                    <option value="أخرى" <?php echo ($edit_student && $edit_student['religion'] == 'أخرى') ? 'selected' : ''; ?>>أخرى</option>
                                                </select>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label class="form-label">
                                                    <i class="fas fa-globe"></i>
                                                    الجنسية
                                                </label>
                                                <input type="text" name="nationality" class="form-control" 
                                                       value="<?php echo $edit_student ? htmlspecialchars($edit_student['nationality'] ?? '') : ''; ?>" 
                                                       placeholder="الجنسية">
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">
                                                <i class="fas fa-map-marker-alt"></i>
                                                العنوان التفصيلي
                                            </label>
                                            <textarea name="address" class="form-control" rows="3" 
                                                      placeholder="العنوان الكامل"><?php echo $edit_student ? htmlspecialchars($edit_student['address']) : ''; ?></textarea>
                                        </div>
                                    </div>

                                    <!-- قسم المنطقة السكنية -->
                                    <div class="form-section area-section">
                                        <h3 class="form-section-title">
                                            <i class="fas fa-map-marked-alt"></i>
                                            المنطقة السكنية
                                        </h3>
                                        
                                        <div class="form-grid">
                                            <div class="form-group">
                                                <label class="form-label">
                                                    <i class="fas fa-city"></i>
                                                    المنطقة الرئيسية
                                                </label>
                                                <select class="form-control" id="mainAreaSelect">
                                                    <option value="">اختر المنطقة الرئيسية</option>
                                                    <?php if (!empty($main_areas)): ?>
                                                        <?php foreach ($main_areas as $main_area): ?>
                                                            <option value="<?php echo $main_area['id']; ?>" 
                                                                    data-name="<?php echo htmlspecialchars($main_area['area_name']); ?>">
                                                                <?php echo htmlspecialchars($main_area['area_name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <option value="">لا توجد مناطق رئيسية</option>
                                                    <?php endif; ?>
                                                </select>
                                            </div>

                                            <div class="form-group" id="studentSubAreaGroup" style="display: none;">
                                                <label class="form-label">
                                                    <i class="fas fa-location-arrow"></i>
                                                    المنطقة الفرعية
                                                </label>
                                                <select name="area_id" class="form-control" id="studentSubAreaSelect">
                                                    <option value="">اختر المنطقة الفرعية</option>
                                                </select>
                                            </div>
                                        </div>

                                        <!-- عرض سعر المنطقة المختارة -->
                                        <div id="studentAreaPriceDisplay" class="area-price-display" style="display: none;">
                                            <div class="price-box">
                                                <strong>سعر النقل للمنطقة المختارة: </strong>
                                                <span id="studentSelectedAreaPrice">0.00</span> <?php echo CURRENCY; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- قسم الرسوم الدراسية -->
                                    <div id="feesSelection" class="form-section fees-section-main" style="display: none;">
                                        <h3 class="form-section-title">
                                            <i class="fas fa-money-bill-wave"></i>
                                            الرسوم الدراسية
                                        </h3>
                                        
                                        <!-- الرسوم الإجبارية -->
                                        <div id="mandatoryFees" class="fees-section" style="display: none;">
                                            <h5 style="color: #e53e3e; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                                                <i class="fas fa-exclamation-circle"></i>
                                                الرسوم الإجبارية
                                            </h5>
                                            <div id="mandatoryFeesList"></div>
                                            <p class="form-hint">
                                                <i class="fas fa-info-circle"></i>
                                                الرسوم الإجبارية لا يمكن إلغاء اختيارها
                                            </p>
                                        </div>
                                        
                                        <!-- الرسوم الاختيارية -->
                                        <div id="optionalFees" class="fees-section" style="display: none;">
                                            <h5 style="color: #48bb78; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                                                <i class="fas fa-check-circle"></i>
                                                الرسوم الاختيارية
                                            </h5>
                                            <div id="optionalFeesList"></div>
                                            <p class="form-hint">
                                                <i class="fas fa-info-circle"></i>
                                                يمكنك إلغاء اختيار أي من الرسوم الاختيارية
                                            </p>
                                        </div>
                                        
                                        <!-- إجمالي الرسوم -->
                                        <div id="totalFeesAmount" class="total-fees-display" style="display: none;">
                                            <i class="fas fa-calculator"></i> إجمالي الرسوم المختارة: 0.00 <?php echo CURRENCY; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- أزرار الإرسال -->
                                    <div class="form-actions">
                                        <button type="submit" class="submit-btn">
                                            <i class="fas fa-<?php echo $edit_student ? 'save' : 'user-plus'; ?>"></i>
                                            <?php echo $edit_student ? 'تحديث البيانات' : 'إضافة الطالب'; ?>
                                        </button>
                                        
                                        <?php if ($edit_student): ?>
                                            <a href="students_management.php" class="submit-btn btn-secondary">
                                                <i class="fas fa-times"></i>
                                                إلغاء
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- قسم قائمة الطلاب -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-list"></i>
                                قائمة الطلاب المسجلين (<?php echo count($students); ?>)
                            </h2>
                        </div>
                        <div class="card-body">
                            <!-- خيارات الفرز -->
                            <div class="sort-options">
                                <div class="sort-label">
                                    <i class="fas fa-sort"></i>
                                    ترتيب حسب:
                                </div>
                                <div class="sort-buttons">
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'code_asc'])); ?>" 
                                       class="sort-btn <?php echo $sort_by == 'code_asc' ? 'active' : ''; ?>">
                                        <i class="fas fa-sort-numeric-down"></i>
                                        الرقم (تصاعدي)
                                    </a>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'code_desc'])); ?>" 
                                       class="sort-btn <?php echo $sort_by == 'code_desc' ? 'active' : ''; ?>">
                                        <i class="fas fa-sort-numeric-down-alt"></i>
                                        الرقم (تنازلي)
                                    </a>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'name_asc'])); ?>" 
                                       class="sort-btn <?php echo $sort_by == 'name_asc' ? 'active' : ''; ?>">
                                        <i class="fas fa-sort-alpha-down"></i>
                                        الاسم (أ-ي)
                                    </a>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'name_desc'])); ?>" 
                                       class="sort-btn <?php echo $sort_by == 'name_desc' ? 'active' : ''; ?>">
                                        <i class="fas fa-sort-alpha-down-alt"></i>
                                        الاسم (ي-أ)
                                    </a>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'created_at_desc'])); ?>" 
                                       class="sort-btn <?php echo $sort_by == 'created_at_desc' ? 'active' : ''; ?>">
                                        <i class="fas fa-clock"></i>
                                        الأحدث
                                    </a>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'created_at_asc'])); ?>" 
                                       class="sort-btn <?php echo $sort_by == 'created_at_asc' ? 'active' : ''; ?>">
                                        <i class="fas fa-history"></i>
                                        الأقدم
                                    </a>
                                </div>
                            </div>
                            
                            <?php if (!empty($students)): ?>
                            <div class="students-cards">
                                <?php foreach ($students as $student): ?>
                                <?php
                                $balance = getStudentBalance($student['id'], $db);
                                $percentage = $balance['total_fees'] > 0 ? ($balance['total_paid'] / $balance['total_fees']) * 100 : 0;
                                ?>
                                <div class="student-card">
                                    <div class="student-header">
                                        <div>
                                            <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                            <div class="student-code"><?php echo $student['student_code']; ?></div>
                                        </div>
                                        <span class="stage-badge"><?php echo htmlspecialchars($student['stage_name']); ?></span>
                                    </div>
                                    
                                    <div class="student-details">
                                        <div class="detail-row">
                                            <div class="detail-item">
                                                <span class="detail-label">الصف الدراسي:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($student['grade_name']); ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">النوع:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($student['gender'] ?? 'غير محدد'); ?></span>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-row">
                                            <div class="detail-item">
                                                <span class="detail-label">تاريخ الميلاد:</span>
                                                <span class="detail-value"><?php echo $student['birth_date'] ?? 'غير محدد'; ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">تاريخ الالتحاق:</span>
                                                <span class="detail-value"><?php echo $student['join_date']; ?></span>
                                            </div>
                                        </div>
                                        
                                        <div class="detail-row">
                                            <div class="detail-item">
                                                <span class="detail-label">الديانة:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($student['religion'] ?? 'غير محدد'); ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <span class="detail-label">الجنسية:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($student['nationality'] ?? 'غير محدد'); ?></span>
                                            </div>
                                        </div>
                                        
                                        <?php if ($student['area_id']): ?>
                                        <div class="detail-row">
                                            <div class="detail-item">
                                                <span class="detail-label">المنطقة السكنية:</span>
                                                <span class="detail-value">
                                                    <?php 
                                                    $area_query = "SELECT area_name FROM residential_areas WHERE id = :area_id";
                                                    $area_stmt = $db->prepare($area_query);
                                                    $area_stmt->bindParam(':area_id', $student['area_id']);
                                                    $area_stmt->execute();
                                                    $area = $area_stmt->fetch(PDO::FETCH_ASSOC);
                                                    echo htmlspecialchars($area['area_name'] ?? 'غير محدد');
                                                    ?>
                                                </span>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- قسم معلومات أولياء الأمور المحسن -->
                                    <div class="parents-info-card">
                                        <div class="parents-info-header">
                                            <i class="fas fa-user-friends"></i>
                                            معلومات أولياء الأمور
                                        </div>
                                        <div class="parents-info-grid">
                                            <div class="parent-info-item">
                                                <span class="parent-info-label">
                                                    <i class="fas fa-male icon-father"></i>
                                                    ولي الأمر (الأب)
                                                </span>
                                                <span class="parent-info-value"><?php echo htmlspecialchars($student['parent_father_name'] ?? $student['parent_name'] ?? ''); ?></span>
                                            </div>
                                            
                                            <div class="parent-info-item">
                                                <span class="parent-info-label">
                                                    <i class="fas fa-phone icon-father"></i>
                                                    هاتف الأب
                                                </span>
                                                <span class="parent-info-value"><?php echo htmlspecialchars($student['parent_father_phone'] ?? $student['parent_phone'] ?? ''); ?></span>
                                            </div>
                                            
                                            <div class="parent-info-item">
                                                <span class="parent-info-label">
                                                    <i class="fas fa-female icon-mother"></i>
                                                    ولي الأمر (الأم)
                                                </span>
                                                <span class="parent-info-value"><?php echo htmlspecialchars($student['parent_mother_name'] ?? 'غير محدد'); ?></span>
                                            </div>
                                            
                                            <div class="parent-info-item">
                                                <span class="parent-info-label">
                                                    <i class="fas fa-phone icon-mother"></i>
                                                    هاتف الأم
                                                </span>
                                                <span class="parent-info-value"><?php echo htmlspecialchars($student['parent_mother_phone'] ?? 'غير محدد'); ?></span>
                                            </div>
                                            
                                            <?php if (!empty($student['parent_other_phone'])): ?>
                                            <div class="parent-info-item">
                                                <span class="parent-info-label">
                                                    <i class="fas fa-mobile-alt icon-other"></i>
                                                    هاتف آخر
                                                </span>
                                                <span class="parent-info-value"><?php echo htmlspecialchars($student['parent_other_phone']); ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="student-balance">
                                        <div class="balance-item">
                                            <span class="balance-label">إجمالي الرسوم:</span>
                                            <span class="balance-value total-fees"><?php echo number_format($balance['total_fees'], 2); ?> <?php echo CURRENCY; ?></span>
                                        </div>
                                        <div class="balance-item">
                                            <span class="balance-label">المدفوع:</span>
                                            <span class="balance-value total-paid"><?php echo number_format($balance['total_paid'], 2); ?> <?php echo CURRENCY; ?></span>
                                        </div>
                                        <div class="balance-item">
                                            <span class="balance-label">المتبقي:</span>
                                            <span class="balance-value remaining"><?php echo number_format($balance['remaining'], 2); ?> <?php echo CURRENCY; ?></span>
                                        </div>
                                        <div class="balance-item">
                                            <span class="balance-label">نسبة السداد:</span>
                                            <span class="balance-value"><?php echo number_format($percentage, 1); ?>%</span>
                                        </div>
                                    </div>
                                    
                                    <div class="action-btns">
                                        <a href="?view_id=<?php echo $student['id']; ?>" class="btn btn-view">
                                            <i class="fas fa-eye"></i>عرض التفاصيل
                                        </a>
                                        <a href="?edit_id=<?php echo $student['id']; ?>" class="btn btn-edit">
                                            <i class="fas fa-edit"></i>تعديل
                                        </a>
                                        <a href="?delete_id=<?php echo $student['id']; ?>" class="btn btn-delete" onclick="return confirm('هل أنت متأكد من حذف هذا الطالب؟')">
                                            <i class="fas fa-trash"></i>حذف
                                        </a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-users-slash"></i>
                                <h3><?php echo (empty($search) && empty($stage_filter)) ? 'لا توجد طلاب مسجلين' : 'لا توجد نتائج للبحث'; ?></h3>
                                <p><?php echo (empty($search) && empty($stage_filter)) ? 'ابدأ بإضافة طالب جديد باستخدام النموذج' : 'جرب مصطلحات بحث أخرى أو أعد ضبط الفلتر'; ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- نافذة عرض بيانات الطالب -->
    <?php if ($view_student): ?>
    <div id="studentModal" class="student-modal" style="display: block;">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-graduate"></i> بيانات الطالب - <?php echo htmlspecialchars($view_student['full_name']); ?></h2>
                <a href="students_management.php" class="close-btn">
                    <i class="fas fa-times"></i>إغلاق
                </a>
            </div>
            
            <div class="student-info-grid">
                <div class="info-card">
                    <h3><i class="fas fa-user"></i> المعلومات الشخصية</h3>
                    <div class="info-item">
                        <span class="info-label">الرقم:</span>
                        <span class="info-value"><?php echo $view_student['student_code']; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">الاسم:</span>
                        <span class="info-value"><?php echo htmlspecialchars($view_student['full_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">المرحلة:</span>
                        <span class="info-value"><?php echo htmlspecialchars($view_student['stage_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">الصف:</span>
                        <span class="info-value"><?php echo htmlspecialchars($view_student['grade_name']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">النوع:</span>
                        <span class="info-value"><?php echo htmlspecialchars($view_student['gender'] ?? 'غير محدد'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">تاريخ الميلاد:</span>
                        <span class="info-value"><?php echo $view_student['birth_date'] ?? 'غير محدد'; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">تاريخ الالتحاق:</span>
                        <span class="info-value"><?php echo $view_student['join_date']; ?></span>
                    </div>
                </div>
                
                <div class="info-card">
                    <h3><i class="fas fa-users"></i> معلومات أولياء الأمور</h3>
                    
                    <div class="parents-info-grid">
                        <div class="parent-info-item">
                            <span class="parent-info-label">
                                <i class="fas fa-male icon-father"></i>
                                اسم ولي الأمر (الأب)
                            </span>
                            <span class="parent-info-value"><?php echo htmlspecialchars($view_student['parent_father_name'] ?? $view_student['parent_name'] ?? ''); ?></span>
                        </div>
                        
                        <div class="parent-info-item">
                            <span class="parent-info-label">
                                <i class="fas fa-phone icon-father"></i>
                                رقم هاتف الأب
                            </span>
                            <span class="parent-info-value"><?php echo htmlspecialchars($view_student['parent_father_phone'] ?? $view_student['parent_phone'] ?? ''); ?></span>
                        </div>
                        
                        <div class="parent-info-item">
                            <span class="parent-info-label">
                                <i class="fas fa-female icon-mother"></i>
                                اسم ولي الأمر (الأم)
                            </span>
                            <span class="parent-info-value"><?php echo htmlspecialchars($view_student['parent_mother_name'] ?? 'غير محدد'); ?></span>
                        </div>
                        
                        <div class="parent-info-item">
                            <span class="parent-info-label">
                                <i class="fas fa-phone icon-mother"></i>
                                رقم هاتف الأم
                            </span>
                            <span class="parent-info-value"><?php echo htmlspecialchars($view_student['parent_mother_phone'] ?? 'غير محدد'); ?></span>
                        </div>
                        
                        <?php if (!empty($view_student['parent_other_phone'])): ?>
                        <div class="parent-info-item">
                            <span class="parent-info-label">
                                <i class="fas fa-mobile-alt icon-other"></i>
                                رقم هاتف آخر
                            </span>
                            <span class="parent-info-value"><?php echo htmlspecialchars($view_student['parent_other_phone']); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="parent-info-item">
                            <span class="parent-info-label">
                                <i class="fas fa-envelope"></i>
                                البريد الإلكتروني
                            </span>
                            <span class="parent-info-value"><?php echo htmlspecialchars($view_student['parent_email'] ?? 'غير محدد'); ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="info-card">
                    <h3><i class="fas fa-map-marker-alt"></i> المعلومات الجغرافية</h3>
                    <div class="info-item">
                        <span class="info-label">العنوان:</span>
                        <span class="info-value"><?php echo htmlspecialchars($view_student['address']); ?></span>
                    </div>
                    <?php if ($view_student['area_id']): ?>
                    <div class="info-item">
                        <span class="info-label">المنطقة السكنية:</span>
                        <span class="info-value">
                            <?php 
                            $area_query = "SELECT area_name, transport_price FROM residential_areas WHERE id = :area_id";
                            $area_stmt = $db->prepare($area_query);
                            $area_stmt->bindParam(':area_id', $view_student['area_id']);
                            $area_stmt->execute();
                            $area = $area_stmt->fetch(PDO::FETCH_ASSOC);
                            echo htmlspecialchars($area['area_name']) . ' - ' . number_format($area['transport_price'], 2) . ' ' . CURRENCY;
                            ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="info-card">
                    <h3><i class="fas fa-info-circle"></i> معلومات إضافية</h3>
                    <div class="info-item">
                        <span class="info-label">الديانة:</span>
                        <span class="info-value"><?php echo htmlspecialchars($view_student['religion'] ?? 'غير محدد'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">الجنسية:</span>
                        <span class="info-value"><?php echo htmlspecialchars($view_student['nationality'] ?? 'غير محدد'); ?></span>
                    </div>
                </div>
                
                <div class="info-card">
                    <h3><i class="fas fa-money-bill-wave"></i> الرسوم الدراسية</h3>
                    <?php
                    $fees_query = "SELECT sf.*, ft.fee_name, ra.area_name 
                                   FROM student_fees sf 
                                   LEFT JOIN fee_types ft ON sf.fee_type_id = ft.id 
                                   LEFT JOIN residential_areas ra ON sf.area_id = ra.id 
                                   WHERE sf.student_id = :student_id 
                                   ORDER BY sf.due_date";
                    $fees_stmt = $db->prepare($fees_query);
                    $fees_stmt->bindParam(':student_id', $view_student['id']);
                    $fees_stmt->execute();
                    $fees = $fees_stmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    <?php if (!empty($fees)): ?>
                        <?php foreach ($fees as $fee): ?>
                        <div class="fee-item" style="border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 10px;">
                            <h4 style="margin: 0 0 8px 0; color: var(--primary);">
                                <?php 
                                if ($fee['fee_name']) {
                                    echo htmlspecialchars($fee['fee_name']);
                                } elseif ($fee['area_name']) {
                                    echo "رسوم النقل - " . htmlspecialchars($fee['area_name']);
                                } else {
                                    echo "رسوم إضافية";
                                }
                                ?>
                            </h4>
                            <div class="info-item">
                                <span class="info-label">المبلغ الأصلي:</span>
                                <span class="info-value"><?php echo number_format($fee['original_amount'], 2); ?> <?php echo CURRENCY; ?></span>
                            </div>
                            <?php if ($fee['discount_amount'] > 0): ?>
                            <div class="info-item">
                                <span class="info-label">قيمة الخصم:</span>
                                <span class="info-value" style="color: var(--success);">-<?php echo number_format($fee['discount_amount'], 2); ?> <?php echo CURRENCY; ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="info-item">
                                <span class="info-label">المبلغ النهائي:</span>
                                <span class="info-value">
                                    <?php echo number_format($fee['final_amount'], 2); ?> <?php echo CURRENCY; ?>
                                    <span style="color: <?php echo $fee['status'] == 'paid' ? 'var(--success)' : 'var(--danger)'; ?>; margin-right: 10px;">
                                        (<?php echo $fee['status'] == 'paid' ? 'مدفوع' : 'غير مدفوع'; ?>)
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="info-item">
                            <span class="info-label">لا توجد رسوم مسجلة</span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="info-card">
                    <h3><i class="fas fa-chart-bar"></i> ملخص المدفوعات</h3>
                    <?php
                    $balance = getStudentBalance($view_student['id'], $db);
                    $percentage = $balance['total_fees'] > 0 ? ($balance['total_paid'] / $balance['total_fees']) * 100 : 0;
                    ?>
                    <div class="info-item">
                        <span class="info-label">إجمالي الرسوم:</span>
                        <span class="info-value"><?php echo number_format($balance['total_fees'], 2); ?> <?php echo CURRENCY; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">إجمالي المدفوع:</span>
                        <span class="info-value" style="color: var(--success);"><?php echo number_format($balance['total_paid'], 2); ?> <?php echo CURRENCY; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">المبلغ المتبقي:</span>
                        <span class="info-value" style="color: var(--warning);"><?php echo number_format($balance['remaining'], 2); ?> <?php echo CURRENCY; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">نسبة السداد:</span>
                        <span class="info-value"><?php echo number_format($percentage, 1); ?>%</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- JavaScript للوظائف التفاعلية -->
    <script>
// =============================================
// نظام إدارة الطلاب - JavaScript الكامل
// =============================================

// تهيئة الصفحة عند التحميل
document.addEventListener('DOMContentLoaded', function() {
    console.log('تم تحميل صفحة إدارة الطلاب بنجاح');
    
    // تهيئة القائمة الجانبية
    initSidebar();
    
    // تهيئة نظام البحث
    initSearchSystem();
    
    // تهيئة نظام المناطق السكنية
    initResidentialAreas();
    
    // تهيئة نظام الرسوم الدراسية
    initFeesSystem();
    
    // تهيئة النماذج
    initForms();
    
    // تهيئة الأزرار والتفاعلات
    initButtons();
    
    // تحميل البيانات الأولية إذا لزم الأمر
    loadInitialData();
});

// =============================================
// نظام القائمة الجانبية
// =============================================

function initSidebar() {
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
    const sidebarToggleIcon = document.getElementById('sidebarToggleIcon');
    
    if (!mobileMenuToggle || !sidebar) return;
    
    let isSidebarOpen = false;

    // زر القائمة للهواتف
    mobileMenuToggle.addEventListener('click', function() {
        toggleSidebar();
    });

    // زر التبديل في الشريط الجانبي
    if (sidebarToggleBtn) {
        sidebarToggleBtn.addEventListener('click', function() {
            toggleSidebar();
        });
    }

    // إغلاق القائمة عند النقر خارجها (للجوال)
    document.addEventListener('click', function(event) {
        if (window.innerWidth <= 768 && isSidebarOpen) {
            if (!sidebar.contains(event.target) && !mobileMenuToggle.contains(event.target)) {
                closeSidebar();
            }
        }
    });

    // إغلاق القائمة عند تغيير حجم النافذة
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768 && !isSidebarOpen) {
            openSidebar();
        }
    });

    function toggleSidebar() {
        if (isSidebarOpen) {
            closeSidebar();
        } else {
            openSidebar();
        }
    }

    function openSidebar() {
        sidebar.classList.add('open', 'active');
        isSidebarOpen = true;
        if (sidebarToggleIcon) {
            sidebarToggleIcon.classList.remove('fa-chevron-right');
            sidebarToggleIcon.classList.add('fa-chevron-left');
        }
        // منع التمرير عند فتح القائمة على الجوال
        if (window.innerWidth <= 768) {
            document.body.style.overflow = 'hidden';
        }
    }

    function closeSidebar() {
        sidebar.classList.remove('open', 'active');
        isSidebarOpen = false;
        if (sidebarToggleIcon) {
            sidebarToggleIcon.classList.remove('fa-chevron-left');
            sidebarToggleIcon.classList.add('fa-chevron-right');
        }
        // إعادة التمرير عند إغلاق القائمة
        document.body.style.overflow = '';
    }
}

// =============================================
// نظام البحث والتصفية
// =============================================

function initSearchSystem() {
    const searchInput = document.querySelector('input[name="search"]');
    const stageFilter = document.querySelector('select[name="stage_filter"]');
    let searchTimeout;

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(submitSearchForm, 500);
        });
    }

    if (stageFilter) {
        stageFilter.addEventListener('change', submitSearchForm);
    }

    function submitSearchForm() {
        const form = document.querySelector('.search-form');
        if (form) {
            form.submit();
        }
    }
}

// =============================================
// نظام المناطق السكنية الهرمي
// =============================================

function initResidentialAreas() {
    const mainAreaSelect = document.getElementById('mainAreaSelect');
    const subAreaSelect = document.getElementById('studentSubAreaSelect');
    
    console.log('تهيئة نظام المناطق السكنية...');
    
    // عرض المناطق الرئيسية المتاحة
    displayAvailableMainAreas();
    
    // تهيئة المناطق الرئيسية
    if (mainAreaSelect) {
        mainAreaSelect.addEventListener('change', function() {
            const parentId = this.value;
            const areaName = this.options[this.selectedIndex]?.getAttribute('data-name') || 'غير معروف';
            console.log('تم اختيار منطقة رئيسية:', parentId, '-', areaName);
            loadSubAreas(parentId);
        });
        
        // تشغيل حدث التغيير مرة واحدة لتحميل المناطق الفرعية إذا كانت هناك قيمة افتراضية
        if (mainAreaSelect.value) {
            mainAreaSelect.dispatchEvent(new Event('change'));
        }
    } else {
        console.error('لم يتم العثور على عنصر اختيار المنطقة الرئيسية');
    }
    
    // تهيئة المناطق الفرعية
    if (subAreaSelect) {
        subAreaSelect.addEventListener('change', function() {
            const areaName = this.options[this.selectedIndex]?.getAttribute('data-name') || 'غير معروف';
            console.log('تم اختيار منطقة فرعية:', this.value, '-', areaName);
            updateAreaPrice();
            updateTotalFees();
        });
    }
    
    // تحميل بيانات المنطقة في وضع التعديل
    <?php if ($edit_student && $edit_student['area_id']): ?>
    console.log('تحميل بيانات المنطقة للطالب في وضع التعديل:', <?php echo $edit_student['area_id']; ?>);
    loadEditAreaData(<?php echo $edit_student['area_id']; ?>);
    <?php endif; ?>
}

// عرض المناطق الرئيسية المتاحة
function displayAvailableMainAreas() {
    const mainAreaSelect = document.getElementById('mainAreaSelect');
    if (!mainAreaSelect) return;
    
    const options = mainAreaSelect.querySelectorAll('option');
    const availableAreas = options.length - 1;
    
    console.log('المناطق الرئيسية المتاحة:', availableAreas);
    
    if (availableAreas <= 0) {
        console.warn('لا توجد مناطق رئيسية متاحة في القائمة');
        showNoMainAreasMessage();
    }
}

// إظهار رسالة عندما لا توجد مناطق رئيسية
function showNoMainAreasMessage() {
    const areaSection = document.querySelector('.form-group:has(#mainAreaSelect)');
    if (areaSection) {
        const message = document.createElement('div');
        message.className = 'alert alert-warning';
        message.innerHTML = `
            <i class="fas fa-exclamation-triangle"></i>
            لا توجد مناطق رئيسية متاحة. 
            <a href="residential_areas.php" style="color: #856404; font-weight: bold;">
                الرجاء إضافة مناطق رئيسية أولاً
            </a>
        `;
        areaSection.appendChild(message);
    }
}

// دالة محسنة لتحميل المناطق الفرعية
function loadSubAreas(parentId) {
    const subAreaGroup = document.getElementById('studentSubAreaGroup');
    const subAreaSelect = document.getElementById('studentSubAreaSelect');
    const priceDisplay = document.getElementById('studentAreaPriceDisplay');
    
    console.log('تحميل المناطق الفرعية للمنطقة الرئيسية:', parentId);
    
    if (!parentId) {
        console.log('لم يتم اختيار منطقة رئيسية - إخفاء المناطق الفرعية');
        hideSubAreaSelection();
        return;
    }
    
    // إظهار حالة التحميل
    showLoadingState(subAreaSelect);
    
    // طلب AJAX لجلب المناطق الفرعية
    const xhr = new XMLHttpRequest();
    xhr.open('GET', `get_sub_areas.php?parent_id=${parentId}`, true);
    xhr.timeout = 10000;
    
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            console.log('استجابة تحميل المناطق الفرعية:', xhr.status);
            handleSubAreasResponse(xhr, subAreaSelect, subAreaGroup);
        }
    };
    
    xhr.ontimeout = function() {
        console.error('انتهت مهلة تحميل المناطق الفرعية');
        showErrorState(subAreaSelect, 'انتهت مهلة الاتصال بالخادم');
    };
    
    xhr.onerror = function() {
        console.error('خطأ في الاتصال لتحميل المناطق الفرعية');
        showErrorState(subAreaSelect, 'خطأ في الاتصال بالخادم');
    };
    
    xhr.send();
}

function handleSubAreasResponse(xhr, subAreaSelect, subAreaGroup) {
    if (xhr.status === 200) {
        try {
            const subAreas = JSON.parse(xhr.responseText);
            console.log('المناطق الفرعية التي تم جلبها:', subAreas);
            populateSubAreasSelect(subAreas, subAreaSelect, subAreaGroup);
        } catch (e) {
            console.error('خطأ في تحليل البيانات:', e);
            showErrorState(subAreaSelect, 'خطأ في تنسيق البيانات');
        }
    } else {
        console.error(`خطأ في الخادم: ${xhr.status}`);
        showErrorState(subAreaSelect, `خطأ في الخادم: ${xhr.status}`);
    }
}

function populateSubAreasSelect(subAreas, subAreaSelect, subAreaGroup) {
    subAreaSelect.innerHTML = '<option value="">اختر المنطقة الفرعية</option>';
    
    if (subAreas.length > 0) {
        subAreas.forEach(area => {
            const option = createAreaOption(area);
            subAreaSelect.appendChild(option);
        });
        subAreaGroup.style.display = 'block';
        console.log(`تم تحميل ${subAreas.length} منطقة فرعية`);
    } else {
        subAreaSelect.innerHTML = '<option value="">لا توجد مناطق فرعية</option>';
        subAreaGroup.style.display = 'block';
        console.log('لا توجد مناطق فرعية للمنطقة الرئيسية المحددة');
    }
}

function createAreaOption(area) {
    const option = document.createElement('option');
    option.value = area.id;
    option.textContent = `${area.area_name} - ${parseFloat(area.transport_price).toFixed(2)} <?php echo CURRENCY; ?>`;
    option.setAttribute('data-price', area.transport_price);
    option.setAttribute('data-name', area.area_name);
    return option;
}

function showLoadingState(subAreaSelect) {
    if (subAreaSelect) {
        subAreaSelect.innerHTML = '<option value="">جاري تحميل المناطق الفرعية...</option>';
        document.getElementById('studentSubAreaGroup').style.display = 'block';
    }
}

function showErrorState(subAreaSelect, message) {
    if (subAreaSelect) {
        subAreaSelect.innerHTML = `<option value="">${message}</option>`;
    }
}

function hideSubAreaSelection() {
    const subAreaGroup = document.getElementById('studentSubAreaGroup');
    const priceDisplay = document.getElementById('studentAreaPriceDisplay');
    
    if (subAreaGroup) subAreaGroup.style.display = 'none';
    if (priceDisplay) priceDisplay.style.display = 'none';
}

// تحديث سعر المنطقة المختارة
function updateAreaPrice() {
    const subAreaSelect = document.getElementById('studentSubAreaSelect');
    const priceDisplay = document.getElementById('studentAreaPriceDisplay');
    const priceElement = document.getElementById('studentSelectedAreaPrice');
    
    if (!subAreaSelect || !priceDisplay || !priceElement) {
        console.error('عناصر عرض السعر غير موجودة');
        return;
    }
    
    const selectedOption = subAreaSelect.options[subAreaSelect.selectedIndex];
    
    if (subAreaSelect.value && selectedOption) {
        const areaPrice = selectedOption.getAttribute('data-price') || 0;
        const areaName = selectedOption.getAttribute('data-name') || 'غير معروف';
        priceElement.textContent = parseFloat(areaPrice).toFixed(2);
        priceDisplay.style.display = 'block';
        console.log('سعر المنطقة المختارة:', areaPrice, '-', areaName);
    } else {
        priceDisplay.style.display = 'none';
        console.log('لم يتم اختيار منطقة فرعية');
    }
}

// تحميل بيانات المنطقة في وضع التعديل
function loadEditAreaData(areaId) {
    console.log('بدء تحميل بيانات المنطقة للتحرير:', areaId);
    
    const xhr = new XMLHttpRequest();
    xhr.open('GET', `get_area_parent.php?area_id=${areaId}`, true);
    
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            console.log('استجابة تحميل بيانات المنطقة:', xhr.status);
            if (xhr.status === 200) {
                try {
                    const areaData = JSON.parse(xhr.responseText);
                    console.log('بيانات المنطقة التي تم جلبها:', areaData);
                    if (areaData.success && areaData.parent_id) {
                        initializeEditArea(areaData.parent_id, areaId);
                    } else {
                        console.error('بيانات المنطقة غير صالحة:', areaData);
                    }
                } catch (e) {
                    console.error('خطأ في تحميل بيانات المنطقة:', e);
                }
            } else {
                console.error(`خطأ في الخادم: ${xhr.status}`);
            }
        }
    };
    
    xhr.onerror = function() {
        console.error('خطأ في الاتصال لتحميل بيانات المنطقة');
    };
    
    xhr.send();
}

function initializeEditArea(parentId, areaId) {
    console.log('تهيئة المنطقة للتحرير - الرئيسية:', parentId, 'الفرعية:', areaId);
    
    const mainAreaSelect = document.getElementById('mainAreaSelect');
    if (mainAreaSelect) {
        // تحديد المنطقة الرئيسية
        mainAreaSelect.value = parentId;
        console.log('تم تحديد المنطقة الرئيسية:', parentId);
        
        // تحميل المناطق الفرعية
        loadSubAreas(parentId);
        
        // تأخير لضمان تحميل المناطق الفرعية أولاً ثم تحديد المنطقة الفرعية
        setTimeout(() => {
            const subAreaSelect = document.getElementById('studentSubAreaSelect');
            if (subAreaSelect) {
                // البحث عن المنطقة الفرعية في القائمة
                let found = false;
                for (let i = 0; i < subAreaSelect.options.length; i++) {
                    if (subAreaSelect.options[i].value == areaId) {
                        subAreaSelect.value = areaId;
                        found = true;
                        console.log('تم تحديد المنطقة الفرعية:', areaId);
                        break;
                    }
                }
                
                if (found) {
                    updateAreaPrice();
                } else {
                    console.error('لم يتم العثور على المنطقة الفرعية في القائمة:', areaId);
                }
            }
        }, 1000);
    }
}

// =============================================
// نظام الرسوم الدراسية
// =============================================

function initFeesSystem() {
    const stageSelect = document.getElementById('stageSelect');
    const gradeSelect = document.getElementById('gradeSelect');
    
    if (stageSelect) {
        stageSelect.addEventListener('change', function() {
            const stageId = this.value;
            loadGrades(stageId);
            resetFeesSelection();
        });
    }
    
    if (gradeSelect) {
        gradeSelect.addEventListener('change', function() {
            const stageId = document.getElementById('stageSelect').value;
            const gradeId = this.value;
            
            if (stageId && gradeId) {
                loadFees(stageId, gradeId);
            } else {
                hideFeesSelection();
            }
        });
    }
    
    // تحديث الإجمالي عند تغيير الرسوم
    document.addEventListener('change', function(e) {
        if (e.target && e.target.name === 'selected_fees[]') {
            updateTotalFees();
        }
    });
}

// تحميل الصفوف الدراسية
function loadGrades(stageId) {
    const gradeSelect = document.getElementById('gradeSelect');
    
    if (!stageId) {
        gradeSelect.innerHTML = '<option value="">اختر الصف الدراسي</option>';
        return;
    }
    
    const xhr = new XMLHttpRequest();
    xhr.open('GET', `get_grades.php?stage_id=${stageId}`, true);
    
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                const grades = JSON.parse(xhr.responseText);
                populateGradesSelect(grades, gradeSelect);
            } catch (e) {
                console.error('خطأ في تحميل الصفوف:', e);
                gradeSelect.innerHTML = '<option value="">خطأ في تحميل البيانات</option>';
            }
        }
    };
    
    xhr.send();
}

function populateGradesSelect(grades, gradeSelect) {
    gradeSelect.innerHTML = '<option value="">اختر الصف الدراسي</option>';
    
    grades.forEach(grade => {
        const option = document.createElement('option');
        option.value = grade.id;
        option.textContent = grade.grade_name;
        gradeSelect.appendChild(option);
    });
}

// تحميل الرسوم الدراسية
function loadFees(stageId, gradeId) {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', `get_fees.php?stage_id=${stageId}&grade_id=${gradeId}`, true);
    
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                const fees = JSON.parse(xhr.responseText);
                displayFees(fees);
            } catch (e) {
                console.error('خطأ في تحميل الرسوم:', e);
                showFeesError();
            }
        }
    };
    
    xhr.send();
}

function displayFees(fees) {
    const mandatoryFeesList = document.getElementById('mandatoryFeesList');
    const optionalFeesList = document.getElementById('optionalFeesList');
    const feesSelection = document.getElementById('feesSelection');
    
    if (!mandatoryFeesList || !optionalFeesList || !feesSelection) return;
    
    mandatoryFeesList.innerHTML = '';
    optionalFeesList.innerHTML = '';
    
    let hasMandatory = false;
    let hasOptional = false;
    let totalAmount = 0;
    
    // فصل الرسوم الإجبارية والاختيارية
    fees.forEach(fee => {
        const feeElement = createFeeElement(fee);
        totalAmount += parseFloat(fee.amount);
        
        if (fee.is_mandatory == 1) {
            mandatoryFeesList.appendChild(feeElement);
            hasMandatory = true;
        } else {
            optionalFeesList.appendChild(feeElement);
            hasOptional = true;
        }
    });
    
    // تحديث العرض
    updateFeesDisplay(hasMandatory, hasOptional, totalAmount);
}

function createFeeElement(fee) {
    const div = document.createElement('div');
    div.className = 'fee-selection-item';
    
    const isMandatory = fee.is_mandatory == 1;
    const badgeClass = isMandatory ? 'mandatory-badge' : 'optional-badge';
    const badgeText = isMandatory ? 'إجباري' : 'اختياري';
    const color = isMandatory ? '#e53e3e' : '#48bb78';
    
    div.innerHTML = `
        <input type="checkbox" name="selected_fees[]" value="${fee.id}" 
               ${isMandatory ? 'checked disabled' : 'checked'}>
        <span class="fee-name" style="color: ${color};">${fee.fee_name}</span>
        <span class="fee-amount">${parseFloat(fee.amount).toFixed(2)} <?php echo CURRENCY; ?></span>
        <span class="${badgeClass}">${badgeText}</span>
    `;
    
    return div;
}

function updateFeesDisplay(hasMandatory, hasOptional, totalAmount) {
    const mandatorySection = document.getElementById('mandatoryFees');
    const optionalSection = document.getElementById('optionalFees');
    const totalElement = document.getElementById('totalFeesAmount');
    const feesSelection = document.getElementById('feesSelection');
    
    if (mandatorySection) mandatorySection.style.display = hasMandatory ? 'block' : 'none';
    if (optionalSection) optionalSection.style.display = hasOptional ? 'block' : 'none';
    if (feesSelection) feesSelection.style.display = (hasMandatory || hasOptional) ? 'block' : 'none';
    
    if (totalElement) {
        if (totalAmount > 0) {
            totalElement.innerHTML = `<i class="fas fa-calculator"></i> إجمالي الرسوم المختارة: ${totalAmount.toFixed(2)} <?php echo CURRENCY; ?>`;
            totalElement.style.display = 'block';
        } else {
            totalElement.style.display = 'none';
        }
    }
    
    updateTotalFees();
}

function resetFeesSelection() {
    const mandatoryFeesList = document.getElementById('mandatoryFeesList');
    const optionalFeesList = document.getElementById('optionalFeesList');
    const feesSelection = document.getElementById('feesSelection');
    const totalElement = document.getElementById('totalFeesAmount');
    
    if (mandatoryFeesList) mandatoryFeesList.innerHTML = '';
    if (optionalFeesList) optionalFeesList.innerHTML = '';
    if (feesSelection) feesSelection.style.display = 'none';
    if (totalElement) totalElement.style.display = 'none';
}

function hideFeesSelection() {
    const feesSelection = document.getElementById('feesSelection');
    const totalElement = document.getElementById('totalFeesAmount');
    
    if (feesSelection) feesSelection.style.display = 'none';
    if (totalElement) totalElement.style.display = 'none';
}

function showFeesError() {
    const feesSelection = document.getElementById('feesSelection');
    if (feesSelection) {
        feesSelection.innerHTML = '<div class="alert alert-danger">خطأ في تحميل الرسوم الدراسية</div>';
        feesSelection.style.display = 'block';
    }
}

// حساب إجمالي الرسوم
function updateTotalFees() {
    let totalAmount = 0;
    
    // جمع أسعار الرسوم المختارة
    const selectedFees = document.querySelectorAll('input[name="selected_fees[]"]:checked');
    selectedFees.forEach(checkbox => {
        const feeItem = checkbox.closest('.fee-selection-item');
        if (feeItem) {
            const amountElement = feeItem.querySelector('.fee-amount');
            if (amountElement) {
                const amountText = amountElement.textContent.trim();
                const amount = parseFloat(amountText.replace(/[^\d.]/g, ''));
                if (!isNaN(amount)) {
                    totalAmount += amount;
                }
            }
        }
    });
    
    // إضافة سعر المنطقة السكنية
    const subAreaSelect = document.getElementById('studentSubAreaSelect');
    if (subAreaSelect && subAreaSelect.value) {
        const selectedOption = subAreaSelect.options[subAreaSelect.selectedIndex];
        if (selectedOption) {
            const areaPrice = parseFloat(selectedOption.getAttribute('data-price')) || 0;
            totalAmount += areaPrice;
        }
    }
    
    // تحديث العرض
    const totalElement = document.getElementById('totalFeesAmount');
    if (totalElement) {
        if (totalAmount > 0) {
            totalElement.innerHTML = `<i class="fas fa-calculator"></i> إجمالي الرسوم المختارة: ${totalAmount.toFixed(2)} <?php echo CURRENCY; ?>`;
            totalElement.style.display = 'block';
        } else {
            totalElement.style.display = 'none';
        }
    }
}

// =============================================
// نظام النماذج والتحقق
// =============================================

function initForms() {
    const studentForm = document.getElementById('studentForm');
    
    if (studentForm) {
        studentForm.addEventListener('submit', function(e) {
            prepareFormData();
            if (!validateForm()) {
                e.preventDefault();
                showFormError('يرجى ملء جميع الحقول المطلوبة بشكل صحيح');
            }
        });
    }
    
    // إضافة أحداث التحقق للهواتف
    initPhoneValidation();
    
    // منع إعادة إرسال النموذج عند تحديث الصفحة
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
}

// التحقق من صحة أرقام الهواتف
function initPhoneValidation() {
    const phoneInputs = document.querySelectorAll('input[type="text"][name*="phone"]');
    
    phoneInputs.forEach(input => {
        input.addEventListener('blur', function() {
            validatePhoneNumber(this);
        });
        
        input.addEventListener('input', function() {
            clearPhoneError(this);
        });
    });
}

function validatePhoneNumber(input) {
    const value = input.value.trim();
    if (!value) return true;
    
    const phoneRegex = /^[\+]?[0-9]{8,15}$/;
    
    if (!phoneRegex.test(value)) {
        showPhoneError(input, 'رقم هاتف غير صالح');
        return false;
    }
    
    showPhoneSuccess(input);
    return true;
}

function showPhoneError(input, message) {
    input.classList.add('input-error');
    input.classList.remove('input-success');
    
    let errorDiv = input.parentNode.querySelector('.phone-error');
    if (!errorDiv) {
        errorDiv = document.createElement('div');
        errorDiv.className = 'phone-error';
        input.parentNode.appendChild(errorDiv);
    }
    errorDiv.textContent = message;
}

function showPhoneSuccess(input) {
    input.classList.remove('input-error');
    input.classList.add('input-success');
    
    const errorDiv = input.parentNode.querySelector('.phone-error');
    if (errorDiv) {
        errorDiv.remove();
    }
}

function clearPhoneError(input) {
    input.classList.remove('input-error', 'input-success');
    const errorDiv = input.parentNode.querySelector('.phone-error');
    if (errorDiv) {
        errorDiv.remove();
    }
}

function prepareFormData() {
    const selectedFees = [];
    document.querySelectorAll('input[name="selected_fees[]"]:checked').forEach(checkbox => {
        selectedFees.push(checkbox.value);
    });
    
    // إضافة حقل مخفي للرسوم المختارة
    let feesInput = document.getElementById('selectedFeesInput');
    if (!feesInput) {
        feesInput = document.createElement('input');
        feesInput.type = 'hidden';
        feesInput.name = 'selected_fees_data';
        feesInput.id = 'selectedFeesInput';
        document.getElementById('studentForm').appendChild(feesInput);
    }
    feesInput.value = JSON.stringify(selectedFees);
}

function validateForm() {
    const requiredFields = document.querySelectorAll('[required]');
    let isValid = true;
    
    // التحقق من الحقول المطلوبة
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            isValid = false;
            highlightError(field);
        } else {
            removeErrorHighlight(field);
        }
    });
    
    // التحقق من صحة الهواتف
    const phoneInputs = document.querySelectorAll('input[type="text"][name*="phone"]');
    phoneInputs.forEach(input => {
        if (input.value.trim() && !validatePhoneNumber(input)) {
            isValid = false;
        }
    });
    
    // تحقق إضافي من البيانات
    const stageSelect = document.getElementById('stageSelect');
    const gradeSelect = document.getElementById('gradeSelect');
    
    if (stageSelect && !stageSelect.value) {
        isValid = false;
        highlightError(stageSelect);
    }
    
    if (gradeSelect && !gradeSelect.value) {
        isValid = false;
        highlightError(gradeSelect);
    }
    
    return isValid;
}

function highlightError(element) {
    element.style.borderColor = '#dc2626';
    element.style.boxShadow = '0 0 0 2px rgba(220, 38, 38, 0.1)';
}

function removeErrorHighlight(element) {
    element.style.borderColor = '';
    element.style.boxShadow = '';
}

function showFormError(message) {
    // إظهار رسالة خطأ للمستخدم
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-danger';
    alertDiv.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${message}`;
    
    const content = document.querySelector('.content');
    if (content) {
        content.insertBefore(alertDiv, content.firstChild);
        
        // إزالة الرسالة بعد 5 ثواني
        setTimeout(() => {
            alertDiv.remove();
        }, 5000);
    }
}

// =============================================
// نظام الأزرار والتفاعلات
// =============================================

function initButtons() {
    // تأكيد الحذف
    const deleteButtons = document.querySelectorAll('a[href*="delete_id"]');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            if (!confirm('⚠️ هل أنت متأكد من حذف هذا الطالب؟\n\nهذا الإجراء سيمسح:\n• بيانات الطالب\n• جميع الرسوم المرتبطة\n• جميع المدفوعات\n• جميع الخصومات\n\nلا يمكن التراجع عن هذا الإجراء.')) {
                e.preventDefault();
            }
        });
    });
    
    // إغلاق نافذة عرض الطالب
    const studentModal = document.getElementById('studentModal');
    if (studentModal) {
        studentModal.addEventListener('click', function(e) {
            if (e.target === studentModal) {
                window.location.href = 'students_management.php';
            }
        });
        
        // إغلاق بالزر ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                window.location.href = 'students_management.php';
            }
        });
    }
    
    // تحسين تجربة المستخدم للأزرار
    const buttons = document.querySelectorAll('.btn, .submit-btn, .search-btn');
    buttons.forEach(button => {
        button.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
        });
        
        button.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
}

// =============================================
// تحميل البيانات الأولية
// =============================================

function loadInitialData() {
    // إذا كانت الصفحة تحتوي على طالب للعرض، نقوم بتهيئة إضافية
    <?php if ($view_student): ?>
    initializeStudentView();
    <?php endif; ?>
    
    // إذا كانت هناك رسالة من الجلسة، نقوم بإخفائها بعد فترة
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.5s ease';
            setTimeout(() => alert.remove(), 500);
        }, 5000);
    });
    
    // تحميل الصفوف إذا كانت هناك مرحلة محددة مسبقاً
    const stageSelect = document.getElementById('stageSelect');
    if (stageSelect && stageSelect.value) {
        loadGrades(stageSelect.value);
    }
    
    // تحميل الرسوم إذا كانت هناك مرحلة وصف محددين مسبقاً
    const gradeSelect = document.getElementById('gradeSelect');
    if (stageSelect && stageSelect.value && gradeSelect && gradeSelect.value) {
        loadFees(stageSelect.value, gradeSelect.value);
    }
}

function initializeStudentView() {
    // أي تهيئة إضافية لوضع العرض
    console.log('تهيئة وضع عرض الطالب');
    
    // إضافة تأثيرات للبطاقات في نافذة العرض
    const infoCards = document.querySelectorAll('.info-card');
    infoCards.forEach((card, index) => {
        card.style.animationDelay = `${index * 0.1}s`;
        card.classList.add('fade-in');
    });
}

// =============================================
// أدوات مساعدة عامة
// =============================================

// تنسيق الأرقام
function formatNumber(number) {
    return new Intl.NumberFormat('ar-EG').format(number);
}

// تحويل التاريخ
function formatDate(dateString) {
    if (!dateString) return 'غير محدد';
    const options = { year: 'numeric', month: 'long', day: 'numeric' };
    return new Date(dateString).toLocaleDateString('ar-EG', options);
}

// =============================================
// تصدير الدوال للاستخدام العام
// =============================================

window.StudentManagement = {
    loadSubAreas,
    updateTotalFees,
    formatNumber,
    formatDate,
    validatePhoneNumber
};

console.log('✅ تم تحميل نظام إدارة الطلاب بالكامل مع التحديثات الجديدة');

    </script>
</body>
</html>