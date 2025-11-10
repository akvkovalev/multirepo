<?php
// Стартуем сессию для сообщений об импорте и пин-кода
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Показываем результат импорта если есть
$importMessage = '';
if (isset($_SESSION['import_result'])) {
    $result = $_SESSION['import_result'];
    if ($result['imported'] > 0) {
        $importMessage .= '<div class="success-message"> Успешно импортировано: ' . $result['imported'] . ' сотрудников</div>';
    }
    if (!empty($result['errors'])) {
        $importMessage .= '<div class="error-message"> Ошибки: ' . implode('<br>', $result['errors']) . '</div>';
    }
    unset($_SESSION['import_result']);
}

// Проверяем авторизацию по пин-коду
$isAuthorized = isset($_SESSION['admin_authorized']) && $_SESSION['admin_authorized'] === true;
$pinError = '';

// Обработка пин-кода
if (isset($_POST['pin_code'])) {
    $enteredPin = $_POST['pin_code'];
    $correctPin = '1943'; // Замените на нужный пин-код
    
    if ($enteredPin === $correctPin) {
        $_SESSION['admin_authorized'] = true;
        $isAuthorized = true;
    } else {
        $pinError = 'Неверный пин-код';
        $isAuthorized = false;
    }
}

// Выход из режима редактирования
if (isset($_POST['logout_admin'])) {
    unset($_SESSION['admin_authorized']);
    $isAuthorized = false;
}

try {
    // Указываем правильный путь к БД
    $dbPath = 'db/t.db';  // относительно корня сайта
    
    // Подключаемся к БД 
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Включаем внешние ключи и настройки для лучшей производительности
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA encoding = "UTF-8"');

    // Обработка действий ДО получения данных (только если авторизован)
    if (($isAuthorized && ($_POST['action'] ?? '')) || ($_POST['pin_code'] ?? '') || ($_POST['logout_admin'] ?? '')) {
        handleAction($db, $_POST, $isAuthorized);
    }

    // Получаем только активных сотрудников
    $stmt = $db->query('SELECT id, name, email, department, title, extension FROM mango WHERE is_active = 1 ORDER BY department, title, name');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    echo '❌ Ошибка подключения: ' . $e->getMessage();
    exit;
} catch (Exception $e) {
    echo '❌ Ошибка: ' . $e->getMessage();
    exit;
}

function handleAction($db, $post, $isAuthorized) {
    // Проверяем авторизацию для критических действий
    if (!$isAuthorized && in_array($post['action'] ?? '', ['edit', 'delete', 'add', 'import'])) {
        $_SESSION['import_result'] = [
            'imported' => 0,
            'errors' => ['Недостаточно прав для выполнения этого действия']
        ];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    switch ($post['action'] ?? '') {
        case 'edit':
            $stmt = $db->prepare('UPDATE mango SET name = ?, email = ?, department = ?, title = ?, extension = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([$post['name'], $post['email'], $post['department'], $post['title'], $post['extension'], $post['id']]);
            break;
            
        case 'delete':
            $stmt = $db->prepare('UPDATE mango SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([$post['id']]);
            break;
            
        case 'add':
            $stmt = $db->prepare('INSERT INTO mango (name, email, department, title, extension) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$post['name'], $post['email'], $post['department'], $post['title'], $post['extension']]);
            break;
            
        case 'import':
            if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
                handleImport($db, $_FILES['import_file']);
            }
            break;
    }
    
    // Перенаправляем чтобы избежать повторной отправки формы
    if ($post['action'] ?? '') {
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// ФУНКЦИЯ ДЛЯ ИМПОРТА С ПРАВИЛЬНОЙ КОДИРОВКОЙ
function handleImport($db, $file) {
    $fileName = $file['tmp_name'];
    $fileType = pathinfo($file['name'], PATHINFO_EXTENSION);
    
    $imported = 0;
    $errors = [];
    
    if (strtolower($fileType) === 'csv') {
        // Читаем весь файл и конвертируем кодировку
        $content = file_get_contents($fileName);
        
        // Убираем BOM если есть
        $bom = pack('H*','EFBBBF');
        $content = preg_replace("/^$bom/", '', $content);
        
        // Конвертируем в UTF-8 если нужно
        if (!mb_detect_encoding($content, 'UTF-8', true)) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }
        
        // Сохраняем во временный файл с правильной кодировкой
        $tempFile = tempnam(sys_get_temp_dir(), 'import_');
        file_put_contents($tempFile, $content);
        
        if (($handle = fopen($tempFile, 'r')) !== FALSE) {
            $firstRow = true;
            while (($data = fgetcsv($handle, 1000, ';')) !== FALSE) {
                if ($firstRow) {
                    $firstRow = false;
                    continue; 
                }
                
                // Проверяем что есть достаточно данных (минимум имя и email)
                if (count($data) >= 2 && !empty(trim($data[0])) && !empty(trim($data[1]))) {
                    try {
                        // Очищаем и нормализуем данные
                        $name = trim($data[0] ?? '');
                        $email = trim($data[1] ?? '');
                        $department = trim($data[2] ?? '');
                        $title = trim($data[3] ?? '');
                        $extension = trim($data[4] ?? '');
                        
                        // Проверяем, нет ли уже такого email
                        $checkStmt = $db->prepare('SELECT id FROM mango WHERE email = ? AND is_active = 1');
                        $checkStmt->execute([$email]);
                        
                        if ($checkStmt->fetch()) {
                            $errors[] = "Пропущено: $name - email уже существует";
                            continue;
                        }
                        
                        $stmt = $db->prepare('INSERT INTO mango (name, email, department, title, extension) VALUES (?, ?, ?, ?, ?)');
                        $stmt->execute([$name, $email, $department, $title, $extension]);
                        $imported++;
                    } catch (Exception $e) {
                        $errors[] = "Ошибка при импорте: " . ($data[0] ?? 'unknown') . " - " . $e->getMessage();
                    }
                }
            }
            fclose($handle);
            unlink($tempFile); 
        } else {
            $errors[] = "Не удалось открыть файл";
        }
    } else {
        $errors[] = "Поддерживаются только CSV файлы";
    }
    
    // Сохраняем результат импорта в сессию для отображения
    $_SESSION['import_result'] = [
        'imported' => $imported,
        'errors' => $errors
    ];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Справочник сотрудников</title>
    <link rel="stylesheet" href="css/style.css">
</head>

<body>
<div class="menu-bar">
    <div class="search-container">
        <input type="text" id="searchInput" class="search-input" placeholder="ПОИСК. Введите имя, email, должность или телефон и тд." onkeyup="filterContacts()">
        <button type="button" class="clear-search" onclick="clearSearch()" title="Очистить поиск">✕</button>
    </div>
    <div class="button-group">
        <button class="theme-toggle" onclick="toggleTheme()" id="themeBtn">🌞 Тема</button>
        <button onclick="exportToExcel()">📁 Экспорт</button>
        
        <?php if (!$isAuthorized): ?>
            <button onclick="showPinForm()">🔐 Редактировать</button>
        <?php else: ?>
            <button onclick="showAddForm()">➕ Добавить</button>
            <button onclick="showImportForm()">📥 Импорт</button>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="logout_admin" value="1">
                <button type="submit">🚪 Выход</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<h1>Справочник сотрудников</h1>
<!-- Сообщения об импорте -->
<?php if (!empty($importMessage)): ?>
<div class="import-messages">
    <?= $importMessage ?>
</div>
<?php endif; ?>

<!-- Форма ввода пин-кода -->
<div id="pinForm" class="modal" style="display: none;">
    <div class="modal-content">
        <h3>Введите пин-код для редактирования</h3>
        <?php if (!empty($pinError)): ?>
            <div class="error-message"><?= $pinError ?></div>
        <?php endif; ?>
        <form method="POST" id="pinFormData">
            <input type="password" name="pin_code" placeholder="Пин-код" required maxlength="4" pattern="[0-9]{4}" style="text-align: center; font-size: 18px; letter-spacing: 5px;">
            <div class="form-buttons">
                <button type="submit">🔓 Войти</button>
                <button type="button" onclick="hidePinForm()">❌ Отмена</button>
            </div>
        </form>
    </div>
</div>

<!-- Форма добавления/редактирования -->
<div id="employeeForm" class="modal" style="display: none;">
    <div class="modal-content">
        <h3 id="formTitle">Добавить сотрудника</h3>
        <form method="POST" id="employeeFormData">
            <input type="hidden" name="id" id="formId">
            <input type="hidden" name="action" id="formAction">
            
            <input type="text" name="name" id="formName" placeholder="ФИО" required>
            <input type="email" name="email" id="formEmail" placeholder="Email" required>
            <input type="text" name="department" id="formDepartment" placeholder="Отдел">
            <input type="text" name="title" id="formTitleInput" placeholder="Должность">
            <input type="text" name="extension" id="formExtension" placeholder="Добавочный">
            
            <div class="form-buttons">
                <button type="submit">💾 Сохранить</button>
                <button type="button" onclick="hideForm()">❌ Отмена</button>
            </div>
        </form>
    </div>
</div>

<!-- Форма импорта -->
<div id="importForm" class="modal" style="display: none;">
    <div class="modal-content">
        <h3>Импорт сотрудников</h3>
        <form method="POST" enctype="multipart/form-data" id="importFormData">
            <input type="hidden" name="action" value="import">
            
            <div style="margin-bottom: 15px;">
                <label>Выберите CSV файл:</label>
                <input type="file" name="import_file" accept=".csv" required style="width: 100%; padding: 8px;">
            </div>
            
            <div style="background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 15px;">
                <strong>Формат CSV файла (разделитель - точка с запятой):</strong>
                <p>Имя;Email;Отдел;Должность;Добавочный</p>
                <p><small>Первая строка - заголовки, данные со второй строки</small></p>
                <textarea readonly style="width: 100%; height: 80px; font-family: monospace; font-size: 12px;">
Иванов Иван;ivanov@example.com;IT;Разработчик;123
Петрова Мария;petrova@example.com;HR;Менеджер;456
                </textarea>
            </div>
            
            <div class="form-buttons">
                <button type="submit">📥 Импортировать</button>
                <button type="button" onclick="hideImportForm()">❌ Отмена</button>
            </div>
        </form>
    </div>
</div>

<table id="contactsTable">
    <tr>
        <th data-title="Имя">Имя</th>
        <th data-title="Отдел">Отдел</th>
        <th data-title="Должность">Должность</th>
        <th data-title="Email">Email</th>
        <th data-title="Добавочный">Добавочный</th>
        <?php if ($isAuthorized): ?>
            <th>Действия</th>
        <?php endif; ?>
    </tr>
    <?php foreach ($rows as $emp): ?>
        <tr data-id="<?= $emp['id'] ?>">
            <td><?= htmlspecialchars($emp['name']) ?></td>            
            <td><?= htmlspecialchars($emp['department']) ?></td>
            <td><?= htmlspecialchars($emp['title']) ?></td>
            <td><a href="mailto:<?= htmlspecialchars($emp['email']) ?>"><?= htmlspecialchars($emp['email']) ?></a></td>
            <td><?= htmlspecialchars($emp['extension']) ?></td>
            <?php if ($isAuthorized): ?>
                <td>
                    <button onclick="editEmployee(<?= $emp['id'] ?>, '<?= htmlspecialchars($emp['name']) ?>', '<?= htmlspecialchars($emp['email']) ?>', '<?= htmlspecialchars($emp['department']) ?>', '<?= htmlspecialchars($emp['title']) ?>', '<?= htmlspecialchars($emp['extension']) ?>')">✏️</button>
                    <button onclick="deleteEmployee(<?= $emp['id'] ?>, '<?= htmlspecialchars($emp['name']) ?>')">🗑️</button>
                </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
</table>

<script src="js/script.js"></script>
</body>
</html>
