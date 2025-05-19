<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');

// Настройки базы данных
$db_host = 'localhost';
$db_name = 'u68895';
$db_user = 'u68895';
$db_pass = '1562324';


function isAdminAuthenticated() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
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
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare("SELECT * FROM admins WHERE login = ?");
        $stmt->execute([$login]);
        $admin = $stmt->fetch();
        
        if ($admin && password_verify($password, $admin['password_hash'])) {
            $_SESSION['admin_logged_in'] = true;
            header('Location: admin.php');
            exit();
        } else {
            $_SESSION['admin_error'] = 'Неверный логин или пароль';
            header('Location: admin.php');
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['admin_error'] = 'Ошибка системы';
        header('Location: admin.php');
        exit();
    }
}

// Если администратор не авторизован, показываем форму входа
if (!isAdminAuthenticated()) {
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Вход для администратора</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                max-width: 400px;
                margin: 0 auto;
                padding: 20px;
            }
            .login-form {
                background: #f9f9f9;
                padding: 20px;
                border-radius: 5px;
                box-shadow: 0 0 10px rgba(0,0,0,0.1);
            }
            .form-group {
                margin-bottom: 15px;
            }
            label {
                display: block;
                margin-bottom: 5px;
            }
            input {
                width: 100%;
                padding: 8px;
                box-sizing: border-box;
            }
            button {
                background: #4CAF50;
                color: white;
                border: none;
                padding: 10px;
                width: 100%;
                cursor: pointer;
            }
            .error {
                color: red;
                margin-bottom: 15px;
            }
        </style>
    </head>
    <body>
        <div class="login-form">
            <h2>Вход для администратора</h2>
            
            <?php if (!empty($_SESSION['admin_error'])): ?>
                <div class="error"><?= htmlspecialchars($_SESSION['admin_error']) ?></div>
                <?php unset($_SESSION['admin_error']); ?>
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


function getAllApplications($pdo) {
    $stmt = $pdo->query("
        SELECT a.*, GROUP_CONCAT(pl.name SEPARATOR ', ') as languages
        FROM applications a
        LEFT JOIN application_languages al ON a.id = al.application_id
        LEFT JOIN programming_languages pl ON al.language_id = pl.id
        GROUP BY a.id
        ORDER BY a.id DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getApplicationById($pdo, $id) {
    $stmt = $pdo->prepare("
        SELECT a.*, GROUP_CONCAT(al.language_id SEPARATOR ',') as language_ids
        FROM applications a
        LEFT JOIN application_languages al ON a.id = al.application_id
        WHERE a.id = ?
        GROUP BY a.id
    ");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function deleteApplication($pdo, $id) {
    $stmt = $pdo->prepare("DELETE FROM applications WHERE id = ?");
    return $stmt->execute([$id]);
}

function updateApplication($pdo, $id, $data) {
    $stmt = $pdo->prepare("
        UPDATE applications 
        SET full_name = ?, phone = ?, email = ?, birth_date = ?, gender = ?, biography = ?, contract_agreed = ?
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
        $stmt->execute([$id, $lang_id]);
    }
}

function getLanguagesStatistics($pdo) {
    $stmt = $pdo->query("
        SELECT pl.name, COUNT(al.application_id) as user_count
        FROM programming_languages pl
        LEFT JOIN application_languages al ON pl.id = al.language_id
        GROUP BY pl.id
        ORDER BY user_count DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? 0;

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
   
    $languages = $pdo->query("SELECT * FROM programming_languages")->fetchAll(PDO::FETCH_ASSOC);
    
   
    if ($action === 'delete' && $id) {
        deleteApplication($pdo, $id);
        $_SESSION['admin_message'] = 'Заявка успешно удалена';
        header('Location: admin.php');
        exit();
    }
    
   
    if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = [
            'full_name' => $_POST['full_name'],
            'phone' => $_POST['phone'],
            'email' => $_POST['email'],
            'birth_date' => $_POST['birth_date'],
            'gender' => $_POST['gender'],
            'biography' => $_POST['biography'],
            'contract_agreed' => isset($_POST['contract_agreed']),
            'languages' => $_POST['languages'] ?? []
        ];
        
        updateApplication($pdo, $id, $data);
        $_SESSION['admin_message'] = 'Заявка успешно обновлена';
        header("Location: admin.php?action=edit&id=$id");
        exit();
    }
    
   
    $stats = getLanguagesStatistics($pdo);
    
 
    $applications = getAllApplications($pdo);
    
} catch (PDOException $e) {
    die("Ошибка базы данных: " . $e->getMessage());
}


?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Панель администратора</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ddd;
        }
        .message {
            padding: 10px;
            margin-bottom: 20px;
            background: #dff0d8;
            color: #3c763d;
            border: 1px solid #d6e9c6;
            border-radius: 4px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            padding: 8px;
            border: 1px solid #ddd;
            text-align: left;
        }
        th {
            background-color: #f5f5f5;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .actions a {
            margin-right: 10px;
            color: #337ab7;
            text-decoration: none;
        }
        .actions a:hover {
            text-decoration: underline;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input, select, textarea {
            width: 100%;
            padding: 8px;
            box-sizing: border-box;
        }
        select[multiple] {
            height: 120px;
        }
        .stats-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 30px;
        }
        .stats-card {
            flex: 1;
            min-width: 200px;
            background: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .stats-card h3 {
            margin-top: 0;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        .stats-list {
            list-style: none;
            padding: 0;
        }
        .stats-list li {
            padding: 5px 0;
            display: flex;
            justify-content: space-between;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Панель администратора</h1>
            <a href="admin.php?action=logout">Выйти</a>
        </div>
        
        <?php if (!empty($_SESSION['admin_message'])): ?>
            <div class="message"><?= htmlspecialchars($_SESSION['admin_message']) ?></div>
            <?php unset($_SESSION['admin_message']); ?>
        <?php endif; ?>
        
        <h2>Статистика по языкам программирования</h2>
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
                    <th>Языки программирования</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($applications as $app): ?>
                    <tr>
                        <td><?= htmlspecialchars($app['id']) ?></td>
                        <td><?= htmlspecialchars($app['full_name']) ?></td>
                        <td><?= htmlspecialchars($app['phone']) ?></td>
                        <td><?= htmlspecialchars($app['email']) ?></td>
                        <td><?= htmlspecialchars($app['birth_date']) ?></td>
                        <td><?= $app['gender'] === 'male' ? 'Мужской' : 'Женский' ?></td>
                        <td><?= htmlspecialchars($app['languages']) ?></td>
                        <td class="actions">
                            <a href="admin.php?action=edit&id=<?= $app['id'] ?>">Редактировать</a>
                            <a href="admin.php?action=delete&id=<?= $app['id'] ?>" onclick="return confirm('Вы уверены?')">Удалить</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if ($action === 'edit' && $id): ?>
            <?php 
            $app = getApplicationById($pdo, $id);
            if (!$app) {
                header('Location: admin.php');
                exit();
            }
            $app['language_ids'] = explode(',', $app['language_ids']);
            ?>
            
            <h2>Редактирование заявки #<?= htmlspecialchars($app['id']) ?></h2>
            <form method="POST" action="admin.php?action=edit&id=<?= $app['id'] ?>">
                <div class="form-group">
                    <label for="full_name">ФИО*</label>
                    <input type="text" id="full_name" name="full_name" 
                           value="<?= htmlspecialchars($app['full_name']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="phone">Телефон*</label>
                    <input type="tel" id="phone" name="phone" 
                           value="<?= htmlspecialchars($app['phone']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email*</label>
                    <input type="email" id="email" name="email" 
                           value="<?= htmlspecialchars($app['email']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="birth_date">Дата рождения*</label>
                    <input type="date" id="birth_date" name="birth_date" 
                           value="<?= htmlspecialchars($app['birth_date']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Пол*</label>
                    <div>
                        <label>
                            <input type="radio" name="gender" value="male" 
                                   <?= $app['gender'] === 'male' ? 'checked' : '' ?> required> Мужской
                        </label>
                        <label>
                            <input type="radio" name="gender" value="female" 
                                   <?= $app['gender'] === 'female' ? 'checked' : '' ?>> Женский
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="languages">Языки программирования*</label>
                    <select id="languages" name="languages[]" multiple required>
                        <?php foreach ($languages as $lang): ?>
                            <option value="<?= $lang['id'] ?>"
                                <?= in_array($lang['id'], $app['language_ids']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($lang['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="biography">Биография</label>
                    <textarea id="biography" name="biography"><?= htmlspecialchars($app['biography']) ?></textarea>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="contract_agreed" 
                               <?= $app['contract_agreed'] ? 'checked' : '' ?> required> Согласие с контрактом*
                    </label>
                </div>
                
                <button type="submit">Сохранить</button>
                <a href="admin.php">Отмена</a>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>