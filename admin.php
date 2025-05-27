<?php
// Проверка HTTP-авторизации
if (!isset($_SERVER['PHP_AUTH_USER']) || 
    !isset($_SERVER['PHP_AUTH_PW']) ||
    $_SERVER['PHP_AUTH_USER'] != 'admin' || 
    $_SERVER['PHP_AUTH_PW'] != 'admin123') {
    
    header('WWW-Authenticate: Basic realm="Admin Panel"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Требуется авторизация';
    exit;
}

// Подключение к базе данных
$db_host = 'localhost';
$db_name = 'u68908';
$db_user = 'u68908';
$db_pass = '9704645';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}

// Обработка действий
$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? 0;

// Удаление заявки
if ($action === 'delete' && $id) {
    try {
        $pdo->beginTransaction();
        
        // Удаляем связанные языки
        $stmt = $pdo->prepare("DELETE FROM application_languages WHERE application_id = ?");
        $stmt->execute([$id]);
        
        // Удаляем пользователя
        $stmt = $pdo->prepare("DELETE FROM users WHERE application_id = ?");
        $stmt->execute([$id]);
        
        // Удаляем заявку
        $stmt = $pdo->prepare("DELETE FROM applications WHERE id = ?");
        $stmt->execute([$id]);
        
        $pdo->commit();
        header("Location: admin.php?deleted=1");
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        die("Ошибка при удалении: " . $e->getMessage());
    }
}

// Получение всех заявок
$stmt = $pdo->query("
    SELECT a.*, GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR ', ') as languages
    FROM applications a
    LEFT JOIN application_languages al ON a.id = al.application_id
    LEFT JOIN programming_languages p ON al.language_id = p.id
    GROUP BY a.id
    ORDER BY a.id DESC
");
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получение статистики по языкам
$stmt = $pdo->query("
    SELECT p.id, p.name, COUNT(al.application_id) as user_count
    FROM programming_languages p
    LEFT JOIN application_languages al ON p.id = al.language_id
    GROUP BY p.id
    ORDER BY user_count DESC, p.name
");
$language_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получение всех языков для формы редактирования
$stmt = $pdo->query("SELECT * FROM programming_languages ORDER BY name");
$all_languages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Обработка формы редактирования
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $id = $_POST['id'];
    $data = [
        'fullname' => $_POST['fullname'],
        'phone' => $_POST['phone'],
        'email' => $_POST['email'],
        'birthdate' => $_POST['birthdate'],
        'gender' => $_POST['gender'],
        'bio' => $_POST['bio'],
        'contract_' => isset($_POST['contract_']) ? 1 : 0,
        'languages' => $_POST['languages'] ?? []
    ];
    
    try {
        $pdo->beginTransaction();
        
        // Обновление основной информации
        $stmt = $pdo->prepare("
            UPDATE applications 
            SET fullname = ?, phone = ?, email = ?, birthdate = ?, gender = ?, bio = ?, contract_ = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $data['fullname'],
            $data['phone'],
            $data['email'],
            $data['birthdate'],
            $data['gender'],
            $data['bio'],
            $data['contract_'],
            $id
        ]);
        
        // Удаление старых языков
        $stmt = $pdo->prepare("DELETE FROM application_languages WHERE application_id = ?");
        $stmt->execute([$id]);
        
        // Добавление новых языков
        $stmt = $pdo->prepare("INSERT INTO application_languages (application_id, language_id) VALUES (?, ?)");
        foreach ($data['languages'] as $lang_id) {
            $stmt->execute([$id, $lang_id]);
        }
        
        $pdo->commit();
        header("Location: admin.php?updated=1");
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        die("Ошибка при обновлении: " . $e->getMessage());
    }
}

// Получение данных для редактирования
$edit_data = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("
        SELECT a.*, GROUP_CONCAT(al.language_id) as language_ids
        FROM applications a
        LEFT JOIN application_languages al ON a.id = al.application_id
        WHERE a.id = ?
        GROUP BY a.id
    ");
    $stmt->execute([$id]);
    $edit_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($edit_data) {
        $edit_data['language_ids'] = $edit_data['language_ids'] ? explode(',', $edit_data['language_ids']) : [];
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ-панель</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #4895ef;
            --secondary: #3f37c9;
            --dark: #1a1a2e;
            --light: #f8f9fa;
            --success: #4cc9f0;
            --danger: #f72585;
            --warning: #f8961e;
            --info: #560bad;
            --gray: #6c757d;
            --white: #ffffff;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Montserrat', sans-serif;
            background-color: #f5f7fa;
            color: var(--dark);
            line-height: 1.6;
            padding: 0;
            margin: 0;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        h1, h2, h3 {
            color: var(--dark);
            margin-bottom: 1rem;
            font-weight: 600;
        }
        
        h1 {
            font-size: 2.2rem;
            border-bottom: 2px solid var(--primary);
            padding-bottom: 0.5rem;
            margin-bottom: 1.5rem;
        }
        
        h2 {
            font-size: 1.8rem;
            margin-top: 2rem;
        }
        
        h3 {
            font-size: 1.4rem;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 2.5rem;
        }
        
        .stats-box {
            background: var(--white);
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .stats-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        
        .stats-box h3 {
            color: var(--primary);
            margin-bottom: 0.5rem;
        }
        
        .stats-box p {
            color: var(--gray);
            font-size: 1.1rem;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2rem;
            background: var(--white);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
            overflow: hidden;
        }
        
        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        th {
            background-color: var(--primary);
            color: var(--white);
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
        }
        
        tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        tr:hover {
            background-color: #e9f0ff;
        }
        
        .actions {
            white-space: nowrap;
        }
        
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 5px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            margin-right: 0.5rem;
            border: none;
            cursor: pointer;
        }
        
        .btn i {
            margin-right: 5px;
        }
        
        .btn-edit {
            background-color: var(--success);
            color: var(--white);
        }
        
        .btn-delete {
            background-color: var(--danger);
            color: var(--white);
        }
        
        .btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }
        
        .form-container {
            background: var(--white);
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--dark);
            font-weight: 500;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 0.8rem 1rem;
            border: 1px solid #ced4da;
            border-radius: 5px;
            font-family: inherit;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
        }
        
        select[multiple] {
            height: auto;
            min-height: 150px;
            padding: 0.5rem;
        }
        
        .checkbox-group {
            margin-top: 1rem;
        }
        
        .checkbox-option {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .checkbox-option input {
            width: auto;
            margin-right: 0.75rem;
        }
        
        .checkbox-option label {
            margin-bottom: 0;
            font-weight: normal;
        }
        
        .btn-save {
            background-color: var(--primary);
            color: var(--white);
            padding: 0.8rem 1.5rem;
            font-weight: 500;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
        }
        
        .btn-save:hover {
            background-color: var(--secondary);
            transform: translateY(-2px);
        }
        
        .btn-cancel {
            padding: 0.8rem 1.5rem;
            background-color: var(--gray);
            color: var(--white);
            border-radius: 5px;
            text-decoration: none;
            margin-left: 1rem;
            transition: all 0.3s ease;
            display: inline-block;
        }
        
        .btn-cancel:hover {
            background-color: #5a6268;
            transform: translateY(-2px);
        }
        
        .notification {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 5px;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .success {
            background-color: #d4edda;
            color: #155724;
            border-left: 5px solid #28a745;
        }
        
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border-left: 5px solid #dc3545;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-start;
            margin-top: 1.5rem;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            table {
                display: block;
                overflow-x: auto;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Админ-панель</h1>
        
        <?php if (isset($_GET['updated'])): ?>
            <div class="notification success">
                <i class="fas fa-check-circle"></i> Данные успешно обновлены!
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['deleted'])): ?>
            <div class="notification success">
                <i class="fas fa-check-circle"></i> Запись успешно удалена!
            </div>
        <?php endif; ?>
        
        <h2>Статистика по языкам программирования</h2>
        <div class="stats-container">
            <?php foreach ($language_stats as $stat): ?>
                <div class="stats-box">
                    <h3><?= htmlspecialchars($stat['name']) ?></h3>
                    <p>Пользователей: <?= $stat['user_count'] ?></p>
                </div>
            <?php endforeach; ?>
        </div>
        
        <h2>Все заявки</h2>
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
                    <th>Контракт</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($applications as $app): ?>
                    <tr>
                        <td><?= $app['id'] ?></td>
                        <td><?= htmlspecialchars($app['fullname']) ?></td>
                        <td><?= htmlspecialchars($app['phone']) ?></td>
                        <td><?= htmlspecialchars($app['email']) ?></td>
                        <td><?= $app['birthdate'] ?></td>
                        <td><?= $app['gender'] === 'male' ? 'Мужской' : ($app['gender'] === 'female' ? 'Женский' : 'Другой') ?></td>
                        <td><?= htmlspecialchars($app['languages']) ?></td>
                        <td><?= $app['contract_'] ? 'Да' : 'Нет' ?></td>
                        <td class="actions">
                            <a href="admin.php?action=edit&id=<?= $app['id'] ?>" class="btn btn-edit">
                                <i class="fas fa-edit"></i> Ред.
                            </a>
                            <a href="admin.php?action=delete&id=<?= $app['id'] ?>" class="btn btn-delete" onclick="return confirm('Вы уверены?')">
                                <i class="fas fa-trash-alt"></i> Удал.
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if ($edit_data): ?>
            <h2>Редактирование заявки #<?= $edit_data['id'] ?></h2>
            <div class="form-container">
                <form method="POST">
                    <input type="hidden" name="id" value="<?= $edit_data['id'] ?>">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="fullname">ФИО:</label>
                            <input type="text" id="fullname" name="fullname" value="<?= htmlspecialchars($edit_data['fullname']) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Телефон:</label>
                            <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($edit_data['phone']) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email:</label>
                            <input type="email" id="email" name="email" value="<?= htmlspecialchars($edit_data['email']) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="birthdate">Дата рождения:</label>
                            <input type="date" id="birthdate" name="birthdate" value="<?= $edit_data['birthdate'] ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Пол:</label>
                            <select name="gender" required>
                                <option value="male" <?= $edit_data['gender'] === 'male' ? 'selected' : '' ?>>Мужской</option>
                                <option value="female" <?= $edit_data['gender'] === 'female' ? 'selected' : '' ?>>Женский</option>
                                <option value="other" <?= $edit_data['gender'] === 'other' ? 'selected' : '' ?>>Другой</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="languages">Языки программирования:</label>
                            <select id="languages" name="languages[]" multiple required>
                                <?php foreach ($all_languages as $lang): ?>
                                    <option value="<?= $lang['id'] ?>" <?= in_array($lang['id'], $edit_data['language_ids']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($lang['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small>Для множественного выбора удерживайте Ctrl (Windows) или Command (Mac)</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="bio">Биография:</label>
                            <textarea id="bio" name="bio" rows="4"><?= htmlspecialchars($edit_data['bio']) ?></textarea>
                        </div>
                        
                        <div class="form-group checkbox-group">
                            <div class="checkbox-option">
                                <input type="checkbox" id="contract_" name="contract_" value="1" <?= $edit_data['contract_'] ? 'checked' : '' ?>>
                                <label for="contract_">С контрактом ознакомлен(а)</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" name="save" class="btn-save">
                            <i class="fas fa-save"></i> Сохранить изменения
                        </button>
                        <a href="admin.php" class="btn-cancel">Отмена</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Font Awesome для иконок -->
    <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
</body>
</html>
