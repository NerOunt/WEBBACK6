<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$db_host = 'localhost';
$db_name = 'u68895';
$db_user = 'u68895';
$db_pass = '1562324';

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
    if (!isset($_SERVER['PHP_AUTH_USER'])) {
        return false;
    }
    
    $providedUsername = $_SERVER['PHP_AUTH_USER'];
    $providedPassword = $_SERVER['PHP_AUTH_PW'];
    
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE login = ? LIMIT 1");
        $stmt->execute([$providedUsername]);
        $admin = $stmt->fetch();
        
        if ($admin && password_verify($providedPassword, $admin['password_hash'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $admin['id'];
            return true;
        }
    } catch (PDOException $e) {
        error_log("Ошибка проверки авторизации: " . $e->getMessage());
    }
    
    return false;
}


if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    /
    $_SESSION = array();
    session_destroy();
    
  
    header('HTTP/1.1 401 Unauthorized');
    header('WWW-Authenticate: Basic realm="Logged out"');
    
    header('Location: admin.php?loggedout=1');
    exit();
}


if (isset($_GET['loggedout'])) {
    die('Вы успешно вышли. <a href="admin.php">Войти снова</a>');
}

if (!isAdminAuthenticated()) {
    header('WWW-Authenticate: Basic realm="Admin Panel"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Требуется авторизация';
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


$app = null;
if ($action === 'edit' && $id > 0) {
    $pdo = getPDO();
    $app = getApplicationById($pdo, $id);
    if (!$app) {
        header('Location: admin.php');
        exit();
    }
    $app['language_ids'] = explode(',', $app['language_ids']);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Панель администратора</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; line-height: 1.6; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #ddd; }
        .message { padding: 10px; margin-bottom: 20px; background: #dff0d8; color: #3c763d; border: 1px solid #d6e9c6; border-radius: 4px; }
        .error { padding: 10px; margin-bottom: 20px; background: #f2dede; color: #a94442; border: 1px solid #ebccd1; border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 8px; border: 1px solid #ddd; text-align: left; }
        th { background-color: #f5f5f5; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        .actions a { margin-right: 10px; color: #337ab7; text-decoration: none; }
        .actions a:hover { text-decoration: underline; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select, textarea { width: 100%; padding: 8px; box-sizing: border-box; }
        select[multiple] { height: 120px; }
        .stats-container { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 30px; }
        .stats-card { flex: 1; min-width: 200px; background: #f5f5f5; padding: 15px; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .stats-card h3 { margin-top: 0; border-bottom: 1px solid #ddd; padding-bottom: 10px; }
        .stats-list { list-style: none; padding: 0; }
        .stats-list li { padding: 5px 0; display: flex; justify-content: space-between; }
        .radio-group { display: flex; gap: 15px; }
        .radio-group label { display: flex; align-items: center; font-weight: normal; }
        .radio-group input { width: auto; margin-right: 5px; }
        .logout-btn { background: #d9534f; color: white; padding: 8px 12px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .logout-btn:hover { background: #c9302c; }
        .form-group input[type="checkbox"] {
            width: auto;
            display: inline-block;
            margin-right: 10px;
        }
        .form-group label[for="contract_agreed"] {
            display: inline;
            font-weight: normal;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Панель администратора</h1>
            <a href="admin.php?action=logout" class="logout-btn">Выйти</a>
        </div>
        
        <?php if (!empty($_SESSION['admin_message'])): ?>
            <div class="message"><?= htmlspecialchars($_SESSION['admin_message']) ?></div>
            <?php unset($_SESSION['admin_message']); ?>
        <?php endif; ?>
        
        <?php if (!empty($_SESSION['admin_error'])): ?>
            <div class="error"><?= htmlspecialchars($_SESSION['admin_error']) ?></div>
            <?php unset($_SESSION['admin_error']); ?>
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
                    <th>Биография</th>
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
                        <td><?= $appItem['gender'] === 'male' ? 'Мужской' : 'Женский' ?></td>
                        <td><?= htmlspecialchars($appItem['languages']) ?></td>
                        <td><?= htmlspecialchars($appItem['biography']) ?></td>
                        <td class="actions">
                            <a href="admin.php?action=edit&id=<?= $appItem['id'] ?>">Редактировать</a>
                            <a href="admin.php?action=delete&id=<?= $appItem['id'] ?>" onclick="return confirm('Вы уверены?')">Удалить</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($action === 'edit' && $app): ?>
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
                    <div class="radio-group">
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
                    <textarea id="biography" name="biography" rows="5"><?= htmlspecialchars($app['biography']) ?></textarea>
                </div>
                
                <div class="form-group">
                    <input type="checkbox" id="contract_agreed" name="contract_agreed" 
                           <?= $app['contract_agreed'] ? 'checked' : '' ?> required>
                    <label for="contract_agreed">Согласие с контрактом*</label>
                </div>
                
                <button type="submit">Сохранить</button>
                <a href="admin.php">Отмена</a>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
