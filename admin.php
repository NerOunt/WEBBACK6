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

// Проверка авторизации
function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

// Выход из системы
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit();
}

// Обработка входа
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

// Если не авторизован - форма входа
if (!isAdminLoggedIn()) {
    // ... форма входа (остается без изменений)
    exit();
}

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
        applications a, 
        (SELECT @row_number:=0) AS counter
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
    <title>Панель администратора</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 8px; border: 1px solid #ddd; text-align: left; }
        th { background-color: #f5f5f5; }
        .message { padding: 10px; margin-bottom: 15px; background: #dff0d8; color: #3c763d; }
        .error { padding: 10px; margin-bottom: 15px; background: #f2dede; color: #a94442; }
        .actions a { margin-right: 10px; color: #337ab7; text-decoration: none; }
        .actions a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <h1>Панель администратора <a href="?logout">Выйти</a></h1>
    
    <?php if (isset($_SESSION['message'])): ?>
        <div class="message"><?= $_SESSION['message'] ?></div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="error"><?= $_SESSION['error'] ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    
    <h2>Список заявок</h2>
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
                <th>Языки</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
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
                        <a href="admin.php?delete=<?= $app['id'] ?>" onclick="return confirm('Удалить эту заявку?')">Удалить</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
