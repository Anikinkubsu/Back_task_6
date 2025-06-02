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
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #EDF5E1;
            color: #05386B;
            margin: 0;
            padding: 20px;
        }
        
        h1, h2 {
            color: #05386B;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .stats-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stats-box {
            background-color: #379683;
            color: #EDF5E1;
            padding: 15px;
            border-radius: 5px;
            flex: 1;
            min-width: 200px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        
        th, td {
            padding: 10px;
            border: 1px solid #5CDB95;
            text-align: left;
        }
        
        th {
            background-color: #379683;
            color: #EDF5E1;
        }
        
        tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        
        .actions {
            white-space: nowrap;
        }
        
        .btn {
            padding: 5px 10px;
            text-decoration: none;
            border-radius: 3px;
            margin-right: 5px;
            display: inline-block;
        }
        
        .btn-edit {
            background-color: #5CDB95;
            color: #05386B;
        }
        
        .btn-delete {
            background-color: #ff6b6b;
            color: white;
        }
        
        .btn:hover {
            opacity: 0.8;
        }
        
        .form-container {
            background-color: #379683;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 30px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            color: #EDF5E1;
            font-weight: bold;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #5CDB95;
            border-radius: 3px;
        }
        
        select[multiple] {
            height: 150px;
        }
        
        .checkbox-group {
            margin-top: 10px;
        }
        
        .checkbox-option {
            display: flex;
            align-items: center;
            margin-bottom: 5px;
        }
        
        .checkbox-option input {
            width: auto;
            margin-right: 10px;
        }
        
        .btn-save {
            background-color: #5CDB95;
            color: #05386B;
            padding: 10px 20px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-weight: bold;
        }
        
        .btn-save:hover {
            background-color: #8EE4AF;
        }
        
        .notification {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 3px;
            text-align: center;
        }
        
        .success {
            background-color: #d4edda;
            color: #155724;
        }
        
        .error {
            background-color: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Админ-панель</h1>
        
        <?php if (isset($_GET['updated'])): ?>
            <div class="notification success">Данные успешно обновлены!</div>
        <?php endif; ?>
        
        <?php if (isset($_GET['deleted'])): ?>
            <div class="notification success">Запись успешно удалена!</div>
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
                            <a href="admin.php?action=edit&id=<?= $app['id'] ?>" class="btn btn-edit">Редактировать</a>
                            <a href="admin.php?action=delete&id=<?= $app['id'] ?>" class="btn btn-delete" onclick="return confirm('Вы уверены?')">Удалить</a>
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
                        <small>Для множественного выбора удерживайте Ctrl (Windows)</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="bio">Биография:</label>
                        <textarea id="bio" name="bio"><?= htmlspecialchars($edit_data['bio']) ?></textarea>
                    </div>
                    
                    <div class="form-group checkbox-group">
                        <div class="checkbox-option">
                            <input type="checkbox" id="contract_" name="contract_" value="1" <?= $edit_data['contract_'] ? 'checked' : '' ?>>
                            <label for="contract_">С контрактом ознакомлен(а)</label>
                        </div>
                    </div>
                    
                    <button type="submit" name="save" class="btn-save">Сохранить изменения</button>
                    <a href="admin.php" style="margin-left: 10px;">Отмена</a>
                </form>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
