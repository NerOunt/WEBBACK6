<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');

// Настройки базы данных
$db_host = 'localhost';
$db_name = 'u68895';
$db_user = 'u68895';
$db_pass = '1562324';

// Функция для подключения к БД
function getPDO() {
    global $db_host, $db_name, $db_user, $db_pass;
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        die("Ошибка подключения: " . $e->getMessage());
    }
}

// Проверка авторизации администратора
function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

// Выход из системы
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit();
}

// Обработка входа администратора
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE login = ?");
    $stmt->execute([$_POST['login']]);
    $admin = $stmt->fetch();
    
    if ($admin && password_verify($_POST['password'], $admin['password_hash'])) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: admin.php');
        exit();
    } else {
        $_SESSION['error'] = 'Неверный логин или пароль';
        header('Location: admin.php');
        exit();
    }
}

// Если не авторизован - показываем форму входа
if (!isAdminLoggedIn()) {
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Вход для администратора</title>
        <style>
            body { font-family: Arial, sans-serif; max-width: 400px; margin: 20px auto; padding: 20px; }
            .login-form { background: #f9f9f9; padding: 20px; border-radius: 5px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
            .form-group { margin-bottom: 15px; }
            label { display: block; margin-bottom: 5px; }
            input { width: 100%; padding: 8px; box-sizing: border-box; }
            button { background: #4CAF50; color: white; border: none; padding: 10px; width: 100%; cursor: pointer; }
            .error { color: red; margin-bottom: 15px; }
        </style>
    </head>
    <body>
        <div class="login-form">
            <h2>Вход для администратора</h2>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="error"><?= htmlspecialchars($_SESSION['error']) ?></div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label>Логин:</label>
                    <input type="text" name="login" required>
                </div>
                <div class="form-group">
                    <label>Пароль:</label>
                    <input type="password" name="password" required>
                </div>
                <button type="submit">Войти</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Основная логика админ-панели
$pdo = getPDO();

// Обработка удаления записи
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    
    try {
        $pdo->beginTransaction();
        
        // Удаляем связанные записи
        $stmt = $pdo->prepare("DELETE FROM application_languages WHERE application_id = ?");
        $stmt->execute([$id]);
        
        // Удаляем основную запись
        $stmt = $pdo->prepare("DELETE FROM applications WHERE id = ?");
        $stmt->execute([$id]);
        
        $pdo->commit();
        $_SESSION['message'] = 'Заявка успешно удалена';
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Ошибка при удалении: ' . $e->getMessage();
    }
    
    header('Location: admin.php');
    exit();
}

// Получаем данные с правильной нумерацией строк
$applications = $pdo->query("
    SELECT 
        (@row_number:=@row_number+1) AS row_num,
        a.*,
        GROUP_CONCAT(p.name SEPARATOR ', ') as languages
    FROM 
        (SELECT @row_number:=0) AS counter,
        applications a
    LEFT JOIN application_languages ap ON a.id = ap.application_id
    LEFT JOIN programming_languages p ON ap.language_id = p.id
    GROUP BY a.id
    ORDER BY a.id
")->fetchAll();

$languages = $pdo->query("SELECT * FROM programming_languages")->fetchAll();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Панель администратора</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background-color: #f5f5f5; position: sticky; top: 0; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        tr:hover { background-color: #f1f1f1; }
        .message { padding: 10px; margin-bottom: 20px; background: #dff0d8; color: #3c763d; border-radius: 4px; }
        .error { padding: 10px; margin-bottom: 20px; background: #f2dede; color: #a94442; border-radius: 4px; }
        .actions a { margin-right: 10px; color: #337ab7; text-decoration: none; }
        .actions a:hover { text-decoration: underline; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Панель администратора</h1>
        <a href="?logout">Выйти</a>
    </div>
    
    <?php if (isset($_SESSION['message'])): ?>
        <div class="message"><?= htmlspecialchars($_SESSION['message']) ?></div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="error"><?= htmlspecialchars($_SESSION['error']) ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    
    <h2>Список заявок</h2>
    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th>№</th>
                    <th>ID</th>
                    <th>ФИО</th>
                    <th>Телефон</th>
                    <th>Email</th>
                    <th>Дата рождения</th>
                    <th>Пол</th>
                    <th>Языки программирования</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($applications) > 0): ?>
                    <?php foreach ($applications as $app): ?>
                        <tr>
                            <td><?= $app['row_num'] ?></td>
                            <td><?= $app['id'] ?></td>
                            <td><?= htmlspecialchars($app['full_name']) ?></td>
                            <td><?= htmlspecialchars($app['phone']) ?></td>
                            <td><?= htmlspecialchars($app['email']) ?></td>
                            <td><?= htmlspecialchars($app['birth_date']) ?></td>
                            <td><?= $app['gender'] === 'male' ? 'Мужской' : 'Женский' ?></td>
                            <td><?= htmlspecialchars($app['languages']) ?></td>
                            <td class="actions">
                                <a href="admin.php?edit=<?= $app['id'] ?>">Редактировать</a>
                                <a href="admin.php?delete=<?= $app['id'] ?>" onclick="return confirm('Вы уверены, что хотите удалить эту заявку?')"> Удалить</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" style="text-align: center;">Нет данных для отображения</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
