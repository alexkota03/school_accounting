<?php
include 'includes/config.php';
include 'includes/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT * FROM users WHERE username = :username AND is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':username', $username);
    $stmt->execute();
    
    if ($stmt->rowCount() == 1) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // في البيئة الحقيقية استخدم password_verify()
        if ($password == 'password') { // كلمة سر تجريبية
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            
            if ($user['role'] == 'admin') {
                header("Location: admin/dashboard.php");
            } else {
                header("Location: staff/dashboard.php");
            }
            exit();
        } else {
            $error = "كلمة المرور غير صحيحة";
        }
    } else {
        $error = "اسم المستخدم غير موجود";
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول - نظام المحاسبة المدرسية</title>
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
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            color: var(--dark);
            line-height: 1.6;
        }
        
        .login-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            width: 420px;
            max-width: 90%;
            overflow: hidden;
            transition: var(--transition);
        }
        
        .login-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .logo {
            font-size: 28px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 8px;
        }
        
        .logo i {
            font-size: 32px;
        }
        
        .subtitle {
            font-size: 16px;
            opacity: 0.9;
            font-weight: 400;
        }
        
        .login-body {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
            text-align: right;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: var(--dark);
            font-weight: 600;
            font-size: 14px;
        }
        
        .input-with-icon {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
            font-size: 18px;
        }
        
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 14px 15px 14px 50px;
            border: 2px solid var(--gray-light);
            border-radius: var(--border-radius);
            font-size: 16px;
            transition: var(--transition);
            background: var(--light);
        }
        
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .btn-login {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 10px;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(67, 97, 238, 0.3);
        }
        
        .error {
            background: var(--danger-light);
            color: var(--danger);
            padding: 12px 16px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            border: 1px solid var(--danger);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .error i {
            font-size: 18px;
        }
        
        .demo-accounts {
            margin-top: 25px;
            padding: 20px;
            background: var(--primary-light);
            border-radius: var(--border-radius);
            border: 1px solid var(--gray-light);
        }
        
        .demo-accounts h4 {
            margin-bottom: 15px;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
        }
        
        .demo-accounts h4 i {
            color: var(--primary);
        }
        
        .account-info {
            text-align: right;
            margin: 12px 0;
            padding: 12px;
            background: white;
            border-radius: 8px;
            border-left: 4px solid var(--primary);
            font-size: 14px;
        }
        
        .account-info strong {
            color: var(--primary);
        }
        
        .login-footer {
            text-align: center;
            padding: 20px;
            background: var(--light);
            border-top: 1px solid var(--gray-light);
            color: var(--gray);
            font-size: 14px;
        }
        
        /* تحسينات للهواتف المحمولة */
        @media (max-width: 480px) {
            .login-container {
                width: 95%;
            }
            
            .login-header {
                padding: 25px 20px;
            }
            
            .login-body {
                padding: 25px 20px;
            }
            
            .logo {
                font-size: 24px;
            }
            
            .logo i {
                font-size: 28px;
            }
        }
        
        /* تأثيرات إضافية */
        .login-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">
                <i class="fas fa-graduation-cap"></i>
                <span>نظام المحاسبة المدرسية</span>
            </div>
            <div class="subtitle">برنامج إدارة الرسوم المالية للمدارس</div>
        </div>
        
        <div class="login-body">
            <?php if (isset($error)): ?>
                <div class="error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">اسم المستخدم</label>
                    <div class="input-with-icon">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" id="username" name="username" required 
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                               placeholder="أدخل اسم المستخدم">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">كلمة المرور</label>
                    <div class="input-with-icon">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="password" name="password" required 
                               placeholder="أدخل كلمة المرور">
                    </div>
                </div>
                
                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i>
                    تسجيل الدخول
                </button>
            </form>
            
            <div class="demo-accounts">
                <h4>
                    <i class="fas fa-info-circle"></i>
                    حسابات تجريبية:
                </h4>
                <div class="account-info">
                    <strong>مدير النظام:</strong> admin / password
                </div>
                <div class="account-info">
                    <strong>موظف الإدخال:</strong> staff / password
                </div>
            </div>
        </div>
        
        <div class="login-footer">
            © 2024 نظام المحاسبة المدرسية - جميع الحقوق محفوظة
        </div>
    </div>

    <!-- JavaScript للوظائف التفاعلية -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // إضافة تأثيرات تفاعلية للحقول
            const inputs = document.querySelectorAll('input[type="text"], input[type="password"]');
            
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.classList.add('focused');
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.classList.remove('focused');
                });
            });
        });
    </script>
</body>
</html>