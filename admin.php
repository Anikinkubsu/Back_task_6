<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$db_host = 'localhost';
$db_name = 'u68908';
$db_user = 'u68908';
$db_pass = '9704645';

function getPDO() {
    global $db_host, $db_name, $db_user, $db_pass;
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            die("Ошибка подключения к базе данных: " . $e->getMessage());
        }
    }
    return $pdo;
}

function isAdminAuthenticated() {
    return !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header('Location: admin.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $login = trim($_POST['login'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    try {
        $pdo = getPDO();
        
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE login = ? LIMIT 1");
        $stmt->execute([$login]);
        $admin = $stmt->fetch();
        
        if ($admin) {
            if (password_verify($password, $admin['password_hash'])) {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = $admin['id'];
                header('Location: admin.php');
                exit();
            } else {
                error_log("Неверный пароль для администратора: $login");
                error_log("Ожидаемый хеш: " . $admin['password_hash']);
                error_log("Полученный хеш: " . password_hash($password, PASSWORD_BCRYPT));
            }
        } else {
            error_log("Попытка входа несуществующего администратора: $login");
        }
        
        $_SESSION['admin_error'] = 'Неверный логин или пароль';
        header('Location: admin.php');
        exit();
        
    } catch (PDOException $e) {
        error_log("Ошибка БД при авторизации: " . $e->getMessage());
        $_SESSION['admin_error'] = 'Ошибка системы. Пожалуйста, попробуйте позже.';
        header('Location: admin.php');
        exit();
    }
}

if (!isAdminAuthenticated()) {
    displayAdminLoginForm();
    exit();
}

function getAllApplications($pdo) {
    $stmt = $pdo->query("
        SELECT a.*, GROUP_CONCAT(pl.name SEPARATOR ', ') as languages
        FROM applications a
        LEFT JOIN application_languages al ON a.id = al.application_id
        LEFT JOIN programming_languages pl ON al.language_id = pl.id
        GROUP BY a.id
        ORDER BY a.id DESC
    ");
    return $stmt->fetchAll();
}

function getApplicationById($pdo, $id) {
    $stmt = $pdo->prepare("
        SELECT a.*, GROUP_CONCAT(al.language_id SEPARATOR ',') as language_ids
        FROM applications a
        LEFT JOIN application_languages al ON a.id = al.application_id
        WHERE a.id = ?
        GROUP BY a.id
        LIMIT 1
    ");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function deleteApplication($pdo, $id) {
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM application_languages WHERE application_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM applications WHERE id = ?")->execute([$id]);
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Ошибка при удалении заявки: " . $e->getMessage());
        return false;
    }
}

function updateApplication($pdo, $id, $data) {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            UPDATE applications SET
            full_name = ?, phone = ?, email = ?, birth_date = ?, gender = ?, biography = ?, contract_agreed = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $data['full_name'],
            $data['phone'],
            $data['email'],
            $data['birth_date'],
            $data['gender'],
            $data['biography'],
            $data['contract_agreed'] ? 1 : 0,
            $id
        ]);
        
        $pdo->prepare("DELETE FROM application_languages WHERE application_id = ?")->execute([$id]);
        
        $stmt = $pdo->prepare("INSERT INTO application_languages (application_id, language_id) VALUES (?, ?)");
        foreach ($data['languages'] as $lang_id) {
            if (!empty($lang_id)) {
                $stmt->execute([$id, $lang_id]);
            }
        }
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Ошибка при обновлении заявки: " . $e->getMessage());
        return false;
    }
}

function getLanguagesStatistics($pdo) {
    $stmt = $pdo->query("
        SELECT pl.id, pl.name, COUNT(al.application_id) as user_count
        FROM programming_languages pl
        LEFT JOIN application_languages al ON pl.id = al.language_id
        GROUP BY pl.id
        ORDER BY user_count DESC, pl.name
    ");
    return $stmt->fetchAll();
}

$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try {
    $pdo = getPDO();
    
    $languages = $pdo->query("SELECT * FROM programming_languages ORDER BY name")->fetchAll();
    
    if ($action === 'delete' && $id > 0) {
        if (deleteApplication($pdo, $id)) {
            $_SESSION['admin_message'] = 'Заявка успешно удалена';
        } else {
            $_SESSION['admin_error'] = 'Ошибка при удалении заявки';
        }
        header('Location: admin.php');
        exit();
    }
    
    if ($action === 'edit' && $id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = [
            'full_name' => trim($_POST['full_name'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'birth_date' => trim($_POST['birth_date'] ?? ''),
            'gender' => trim($_POST['gender'] ?? ''),
            'biography' => trim($_POST['biography'] ?? ''),
            'contract_agreed' => isset($_POST['contract_agreed']),
            'languages' => $_POST['languages'] ?? []
        ];
        
        if (updateApplication($pdo, $id, $data)) {
            $_SESSION['admin_message'] = 'Заявка успешно обновлена';
        } else {
            $_SESSION['admin_error'] = 'Ошибка при обновлении заявки';
        }
        header("Location: admin.php?action=edit&id=$id");
        exit();
    }
    
    $stats = getLanguagesStatistics($pdo);
    $applications = getAllApplications($pdo);
    
} catch (PDOException $e) {
    die("Ошибка базы данных: " . $e->getMessage());
}

displayAdminPanel($applications, $stats, $languages, $action, $id);

function displayAdminLoginForm() {
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Вход для администратора</title>
        <style>
            :root {
                --primary-color: #4361ee;
                --secondary-color: #3f37c9;
                --accent-color: #4895ef;
                --light-color: #f8f9fa;
                --dark-color: #212529;
                --success-color: #4bb543;
                --error-color: #ff3333;
                --border-radius: 8px;
                --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                --transition: all 0.3s ease;
            }
            
            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }
            
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background-color: #f5f7fa;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                color: var(--dark-color);
                line-height: 1.6;
            }
            
            .login-container {
                width: 100%;
                max-width: 420px;
                padding: 2rem;
            }
            
            .login-card {
                background: white;
                border-radius: var(--border-radius);
                box-shadow: var(--box-shadow);
                padding: 2.5rem;
                text-align: center;
                transition: var(--transition);
            }
            
            .login-card:hover {
                box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            }
            
            .login-logo {
                margin-bottom: 1.5rem;
                color: var(--primary-color);
                font-size: 2rem;
                font-weight: 700;
            }
            
            .login-title {
                margin-bottom: 1.5rem;
                color: var(--dark-color);
                font-size: 1.5rem;
                font-weight: 600;
            }
            
            .form-group {
                margin-bottom: 1.5rem;
                text-align: left;
            }
            
            .form-group label {
                display: block;
                margin-bottom: 0.5rem;
                font-weight: 500;
                color: var(--dark-color);
            }
            
            .form-control {
                width: 100%;
                padding: 0.75rem 1rem;
                border: 1px solid #ddd;
                border-radius: var(--border-radius);
                font-size: 1rem;
                transition: var(--transition);
            }
            
            .form-control:focus {
                outline: none;
                border-color: var(--accent-color);
                box-shadow: 0 0 0 3px rgba(72, 149, 239, 0.2);
            }
            
            .btn {
                display: inline-block;
                background-color: var(--primary-color);
                color: white;
                border: none;
                border-radius: var(--border-radius);
                padding: 0.75rem 1.5rem;
                font-size: 1rem;
                font-weight: 500;
                cursor: pointer;
                transition: var(--transition);
                width: 100%;
            }
            
            .btn:hover {
                background-color: var(--secondary-color);
                transform: translateY(-2px);
            }
            
            .btn:active {
                transform: translateY(0);
            }
            
            .alert {
                padding: 0.75rem 1.25rem;
                margin-bottom: 1.5rem;
                border-radius: var(--border-radius);
                font-size: 0.9rem;
            }
            
            .alert-error {
                background-color: rgba(255, 51, 51, 0.1);
                color: var(--error-color);
                border: 1px solid rgba(255, 51, 51, 0.2);
            }
            
            .footer-text {
                margin-top: 1.5rem;
                font-size: 0.9rem;
                color: #6c757d;
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <div class="login-card">
                <div class="login-logo">Admin Panel</div>
                <h1 class="login-title">Вход в систему</h1>
                
                <?php if (!empty($_SESSION['admin_error'])): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($_SESSION['admin_error']) ?></div>
                    <?php unset($_SESSION['admin_error']); ?>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="form-group">
                        <label for="login">Логин</label>
                        <input type="text" id="login" name="login" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Пароль</label>
                        <input type="password" id="password" name="password" class="form-control" required>
                    </div>
                    
                    <button type="submit" class="btn">Войти</button>
                </form>
                
                <p class="footer-text">Только для авторизованного персонала</p>
            </div>
        </div>
    </body>
    </html>
    <?php
}

function displayAdminPanel($applications, $stats, $languages, $action, $id) {
    $app = null;
    if ($action === 'edit' && $id > 0) {
        $pdo = getPDO();
        $app = getApplicationById($pdo, $id);
        if ($app) {
            $app['language_ids'] = explode(',', $app['language_ids']);
        } else {
            header('Location: admin.php');
            exit();
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Панель администратора</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            :root {
                --primary-color: #4361ee;
                --secondary-color: #3f37c9;
                --accent-color: #4895ef;
                --light-color: #f8f9fa;
                --dark-color: #212529;
                --success-color: #4bb543;
                --error-color: #ff3333;
                --warning-color: #ffc107;
                --info-color: #17a2b8;
                --border-radius: 8px;
                --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                --transition: all 0.3s ease;
            }
            
            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }
            
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background-color: #f5f7fa;
                color: var(--dark-color);
                line-height: 1.6;
            }
            
            .container {
                max-width: 1200px;
                margin: 0 auto;
                padding: 20px;
            }
            
            .header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 30px;
                padding-bottom: 20px;
                border-bottom: 1px solid #e0e0e0;
            }
            
            .header h1 {
                color: var(--primary-color);
                font-size: 2rem;
                font-weight: 700;
            }
            
            .logout-btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                background-color: var(--error-color);
                color: white;
                border: none;
                border-radius: var(--border-radius);
                padding: 0.5rem 1rem;
                font-size: 0.9rem;
                font-weight: 500;
                cursor: pointer;
                transition: var(--transition);
                text-decoration: none;
            }
            
            .logout-btn:hover {
                background-color: #d32f2f;
                transform: translateY(-2px);
            }
            
            .alert {
                padding: 1rem;
                margin-bottom: 1.5rem;
                border-radius: var(--border-radius);
                font-size: 0.95rem;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .alert-success {
                background-color: rgba(75, 181, 67, 0.1);
                color: var(--success-color);
                border: 1px solid rgba(75, 181, 67, 0.2);
            }
            
            .alert-error {
                background-color: rgba(255, 51, 51, 0.1);
                color: var(--error-color);
                border: 1px solid rgba(255, 51, 51, 0.2);
            }
            
            .alert i {
                font-size: 1.2rem;
            }
            
            .section {
                background: white;
                border-radius: var(--border-radius);
                box-shadow: var(--box-shadow);
                padding: 1.5rem;
                margin-bottom: 2rem;
            }
            
            .section-title {
                margin-bottom: 1.5rem;
                color: var(--primary-color);
                font-size: 1.5rem;
                font-weight: 600;
                padding-bottom: 0.5rem;
                border-bottom: 2px solid #f0f0f0;
            }
            
            .stats-container {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                gap: 1.5rem;
                margin-bottom: 2rem;
            }
            
            .stats-card {
                background: white;
                border-radius: var(--border-radius);
                box-shadow: var(--box-shadow);
                padding: 1.5rem;
                transition: var(--transition);
            }
            
            .stats-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
            }
            
            .stats-card h3 {
                margin-top: 0;
                margin-bottom: 1rem;
                color: var(--secondary-color);
                font-size: 1.2rem;
                font-weight: 600;
            }
            
            .stats-list {
                list-style: none;
                padding: 0;
            }
            
            .stats-list li {
                padding: 0.5rem 0;
                display: flex;
                justify-content: space-between;
                border-bottom: 1px dashed #eee;
            }
            
            .stats-list li:last-child {
                border-bottom: none;
            }
            
            .stats-list li span:first-child {
                color: var(--dark-color);
            }
            
            .stats-list li span:last-child {
                font-weight: 600;
                color: var(--primary-color);
            }
            
            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 1.5rem;
                background: white;
                border-radius: var(--border-radius);
                overflow: hidden;
                box-shadow: var(--box-shadow);
            }
            
            th, td {
                padding: 1rem;
                text-align: left;
                border-bottom: 1px solid #eee;
            }
            
            th {
                background-color: var(--primary-color);
                color: white;
                font-weight: 600;
            }
            
            tr:nth-child(even) {
                background-color: #f9f9f9;
            }
            
            tr:hover {
                background-color: #f1f1f1;
            }
            
            .actions {
                display: flex;
                gap: 0.5rem;
            }
            
            .action-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 30px;
                height: 30px;
                border-radius: 50%;
                color: white;
                text-decoration: none;
                transition: var(--transition);
            }
            
            .action-btn:hover {
                transform: scale(1.1);
            }
            
            .edit-btn {
                background-color: var(--accent-color);
            }
            
            .edit-btn:hover {
                background-color: #3a7bd5;
            }
            
            .delete-btn {
                background-color: var(--error-color);
            }
            
            .delete-btn:hover {
                background-color: #d32f2f;
            }
            
            .form-container {
                background: white;
                border-radius: var(--border-radius);
                box-shadow: var(--box-shadow);
                padding: 2rem;
                margin-top: 2rem;
            }
            
            .form-title {
                margin-bottom: 1.5rem;
                color: var(--primary-color);
                font-size: 1.5rem;
                font-weight: 600;
            }
            
            .form-group {
                margin-bottom: 1.5rem;
            }
            
            .form-label {
                display: block;
                margin-bottom: 0.5rem;
                font-weight: 500;
                color: var(--dark-color);
            }
            
            .form-control {
                width: 100%;
                padding: 0.75rem 1rem;
                border: 1px solid #ddd;
                border-radius: var(--border-radius);
                font-size: 1rem;
                transition: var(--transition);
            }
            
            .form-control:focus {
                outline: none;
                border-color: var(--accent-color);
                box-shadow: 0 0 0 3px rgba(72, 149, 239, 0.2);
            }
            
            textarea.form-control {
                min-height: 120px;
                resize: vertical;
            }
            
            select[multiple].form-control {
                min-height: 150px;
            }
            
            .radio-group {
                display: flex;
                gap: 1.5rem;
            }
            
            .radio-option {
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }
            
            .radio-option input {
                width: auto;
            }
            
            .checkbox-option {
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }
            
            .checkbox-option input {
                width: auto;
            }
            
            .btn {
                display: inline-block;
                background-color: var(--primary-color);
                color: white;
                border: none;
                border-radius: var(--border-radius);
                padding: 0.75rem 1.5rem;
                font-size: 1rem;
                font-weight: 500;
                cursor: pointer;
                transition: var(--transition);
                text-decoration: none;
            }
            
            .btn:hover {
                background-color: var(--secondary-color);
                transform: translateY(-2px);
            }
            
            .btn-secondary {
                background-color: #6c757d;
            }
            
            .btn-secondary:hover {
                background-color: #5a6268;
            }
            
            .btn-group {
                display: flex;
                gap: 1rem;
                margin-top: 1.5rem;
            }
            
            .badge {
                display: inline-block;
                padding: 0.25rem 0.5rem;
                border-radius: 50px;
                font-size: 0.75rem;
                font-weight: 600;
                text-transform: uppercase;
            }
            
            .badge-male {
                background-color: #d1e7ff;
                color: #0d6efd;
            }
            
            .badge-female {
                background-color: #f8d7da;
                color: #dc3545;
            }
            
            @media (max-width: 768px) {
                .header {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 1rem;
                }
                
                .stats-container {
                    grid-template-columns: 1fr;
                }
                
                table {
                    display: block;
                    overflow-x: auto;
                }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1><i class="fas fa-cog"></i> Панель администратора</h1>
                <a href="admin.php?action=logout" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Выйти
                </a>
            </div>
            
            <?php if (!empty($_SESSION['admin_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($_SESSION['admin_message']) ?>
                </div>
                <?php unset($_SESSION['admin_message']); ?>
            <?php endif; ?>
            
            <?php if (!empty($_SESSION['admin_error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($_SESSION['admin_error']) ?>
                </div>
                <?php unset($_SESSION['admin_error']); ?>
            <?php endif; ?>
            
            <div class="section">
                <h2 class="section-title"><i class="fas fa-chart-pie"></i> Статистика по языкам программирования</h2>
                <div class="stats-container">
                    <div class="stats-card">
                        <h3>Популярность языков</h3>
                        <ul class="stats-list">
                            <?php foreach ($stats as $stat): ?>
                                <li>
                                    <span><?= htmlspecialchars($stat['name']) ?></span>
                                    <span><?= (int)$stat['user_count'] ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="section">
                <h2 class="section-title"><i class="fas fa-list"></i> Все заявки</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>ФИО</th>
                            <th>Телефон</th>
                            <th>Email</th>
                            <th>Дата рождения</th>
                            <th>Пол</th>
                            <th>Языки</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $appItem): ?>
                            <tr>
                                <td><?= htmlspecialchars($appItem['id']) ?></td>
                                <td><?= htmlspecialchars($appItem['full_name']) ?></td>
                                <td><?= htmlspecialchars($appItem['phone']) ?></td>
                                <td><?= htmlspecialchars($appItem['email']) ?></td>
                                <td><?= htmlspecialchars($appItem['birth_date']) ?></td>
                                <td>
                                    <span class="badge <?= $appItem['gender'] === 'male' ? 'badge-male' : 'badge-female' ?>">
                                        <?= $appItem['gender'] === 'male' ? 'Мужской' : 'Женский' ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($appItem['languages']) ?></td>
                                <td class="actions">
                                    <a href="admin.php?action=edit&id=<?= $appItem['id'] ?>" class="action-btn edit-btn" title="Редактировать">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="admin.php?action=delete&id=<?= $appItem['id'] ?>" class="action-btn delete-btn" title="Удалить" onclick="return confirm('Вы уверены?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($action === 'edit' && $app): ?>
                <div class="form-container">
                    <h2 class="form-title"><i class="fas fa-edit"></i> Редактирование заявки #<?= htmlspecialchars($app['id']) ?></h2>
                    <form method="POST" action="admin.php?action=edit&id=<?= $app['id'] ?>">
                        <div class="form-group">
                            <label for="full_name" class="form-label">ФИО*</label>
                            <input type="text" id="full_name" name="full_name" class="form-control" 
                                   value="<?= htmlspecialchars($app['full_name']) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone" class="form-label">Телефон*</label>
                            <input type="tel" id="phone" name="phone" class="form-control" 
                                   value="<?= htmlspecialchars($app['phone']) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email" class="form-label">Email*</label>
                            <input type="email" id="email" name="email" class="form-control" 
                                   value="<?= htmlspecialchars($app['email']) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="birth_date" class="form-label">Дата рождения*</label>
                            <input type="date" id="birth_date" name="birth_date" class="form-control" 
                                   value="<?= htmlspecialchars($app['birth_date']) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Пол*</label>
                            <div class="radio-group">
                                <label class="radio-option">
                                    <input type="radio" name="gender" value="male" 
                                           <?= $app['gender'] === 'male' ? 'checked' : '' ?> required>
                                    Мужской
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="gender" value="female" 
                                           <?= $app['gender'] === 'female' ? 'checked' : '' ?>>
                                    Женский
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="languages" class="form-label">Языки программирования*</label>
                            <select id="languages" name="languages[]" class="form-control" multiple required>
                                <?php foreach ($languages as $lang): ?>
                                    <option value="<?= $lang['id'] ?>"
                                        <?= in_array($lang['id'], $app['language_ids']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($lang['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="biography" class="form-label">Биография</label>
                            <textarea id="biography" name="biography" class="form-control" rows="5"><?= htmlspecialchars($app['biography']) ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label class="checkbox-option">
                                <input type="checkbox" name="contract_agreed" 
                                       <?= $app['contract_agreed'] ? 'checked' : '' ?> required>
                                Согласие с контрактом*
                            </label>
                        </div>
                        
                        <div class="btn-group">
                            <button type="submit" class="btn"><i class="fas fa-save"></i> Сохранить</button>
                            <a href="admin.php" class="btn btn-secondary"><i class="fas fa-times"></i> Отмена</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
}
