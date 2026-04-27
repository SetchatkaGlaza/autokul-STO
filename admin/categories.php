<?php
// admin/categories.php - Управление категориями услуг

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth_check.php';

requireAuth('admin');

$page_title = 'Управление категориями — Панель управления';
$pdo = getDBConnection();

// ============================================================
// AJAX-ОБРАБОТЧИКИ (до любого вывода HTML)
// ============================================================

// AJAX: получение данных категории для редактирования
if (isset($_GET['edit']) && isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    $edit_id = intval($_GET['edit']);
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id");
    $stmt->execute(['id' => $edit_id]);
    $category = $stmt->fetch();
    if ($category) {
        echo json_encode($category, JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Категория не найдена']);
    }
    exit;
}

// ============================================================
// ОБРАБОТКА POST-ЗАПРОСОВ
// ============================================================

$message = '';
$message_type = '';

// Сохранение категории (добавление/редактирование)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_category') {
    $category_id = intval($_POST['category_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    $errors = [];
    
    if (empty($name)) {
        $errors[] = 'Введите название категории';
    } elseif (mb_strlen($name) < 3) {
        $errors[] = 'Название должно содержать минимум 3 символа';
    } elseif (mb_strlen($name) > 100) {
        $errors[] = 'Название не должно превышать 100 символов';
    }
    
    // Проверка уникальности названия
    if (empty($errors)) {
        $check_sql = "SELECT COUNT(*) FROM categories WHERE name = :name";
        $check_params = ['name' => $name];
        if ($category_id > 0) {
            $check_sql .= " AND id != :id";
            $check_params['id'] = $category_id;
        }
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute($check_params);
        if ($check_stmt->fetchColumn() > 0) {
            $errors[] = 'Категория с таким названием уже существует';
        }
    }
    
    if (empty($errors)) {
        try {
            if ($category_id > 0) {
                // Редактирование
                $stmt = $pdo->prepare("UPDATE categories SET name = :name, description = :desc WHERE id = :id");
                $stmt->execute(['name' => $name, 'desc' => $description, 'id' => $category_id]);
                $message = '✅ Категория «' . htmlspecialchars($name) . '» обновлена!';
            } else {
                // Добавление
                $stmt = $pdo->prepare("INSERT INTO categories (name, description) VALUES (:name, :desc)");
                $stmt->execute(['name' => $name, 'desc' => $description]);
                $message = '✅ Категория «' . htmlspecialchars($name) . '» добавлена!';
            }
            $message_type = 'success';
        } catch (PDOException $e) {
            $message = '❌ Ошибка базы данных: ' . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        $message = '⚠️ ' . implode('<br>• ', $errors);
        $message_type = 'error';
    }
    
    // Если это был AJAX-запрос из модалки — возвращаем JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => empty($errors),
            'message' => $message,
            'message_type' => $message_type
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Удаление категории
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_category') {
    $category_id = intval($_POST['category_id'] ?? 0);
    
    try {
        // Проверяем, есть ли услуги в этой категории
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM services WHERE category_id = :id");
        $check_stmt->execute(['id' => $category_id]);
        $services_count = $check_stmt->fetchColumn();
        
        if ($services_count > 0) {
            $message = '⚠️ Нельзя удалить категорию, в которой есть ' . $services_count . ' услуг(а). Сначала переместите или удалите услуги.';
            $message_type = 'warning';
        } else {
            $stmt = $pdo->prepare("DELETE FROM categories WHERE id = :id");
            $stmt->execute(['id' => $category_id]);
            $message = '🗑️ Категория успешно удалена.';
            $message_type = 'success';
        }
    } catch (PDOException $e) {
        $message = '❌ Ошибка при удалении: ' . $e->getMessage();
        $message_type = 'error';
    }
}

// ============================================================
// ПОЛУЧАЕМ КАТЕГОРИИ С ПОДСЧЁТОМ УСЛУГ
// ============================================================

$categories = $pdo->query("
    SELECT c.*, 
           COUNT(s.id) AS services_count,
           SUM(CASE WHEN s.is_active = 1 THEN 1 ELSE 0 END) AS active_count,
           SUM(CASE WHEN s.is_active = 0 THEN 1 ELSE 0 END) AS inactive_count
    FROM categories c
    LEFT JOIN services s ON c.id = s.category_id
    GROUP BY c.id
    ORDER BY c.id
")->fetchAll();

// Общая статистика
$total_categories = count($categories);
$total_services = array_sum(array_column($categories, 'services_count'));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        :root { --sidebar-width: 250px; }

        .admin-wrapper {
            display: flex;
            min-height: calc(100vh - var(--header-height));
        }

        .admin-sidebar {
            width: var(--sidebar-width);
            background: #212121;
            color: white;
            flex-shrink: 0;
            padding: 20px 0;
            position: sticky;
            top: var(--header-height);
            height: calc(100vh - var(--header-height));
            overflow-y: auto;
        }

        .sidebar-title {
            padding: 0 20px 20px;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255,255,255,0.5);
            font-weight: 600;
        }

        .sidebar-nav { display: flex; flex-direction: column; }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            color: rgba(255,255,255,0.75);
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 500;
            border-left: 3px solid transparent;
        }

        .sidebar-nav a:hover { background: rgba(255,255,255,0.05); color: white; }

        .sidebar-nav a.active {
            background: rgba(211, 47, 47, 0.2);
            color: white;
            border-left-color: #d32f2f;
        }

        .sidebar-divider { border: none; border-top: 1px solid rgba(255,255,255,0.1); margin: 10px 0; }

        .admin-content {
            flex: 1;
            padding: 24px;
            background: #f5f5f5;
            min-width: 0;
        }

        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .admin-header h1 { font-size: 24px; font-weight: 700; color: #212121; }

        .btn {
            display: inline-block;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }

        .btn-primary { background: #d32f2f; color: white; }
        .btn-primary:hover { background: #b71c1c; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(211, 47, 47, 0.3); }
        .btn-outline { background: white; color: #d32f2f; border: 1px solid #d32f2f; }
        .btn-outline:hover { background: #d32f2f; color: white; }
        .btn-sm { padding: 6px 14px; font-size: 12px; border-radius: 6px; }
        .btn-danger { background: #dc3545; color: white; border: none; }
        .btn-danger:hover { background: #c82333; }

        /* Сообщения */
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.6;
        }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .alert-warning { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; }

        /* Статистика */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 18px 20px;
            border: 1px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .stat-icon.red { background: #ffebee; }
        .stat-icon.blue { background: #e3f2fd; }
        .stat-icon.green { background: #e8f5e9; }

        .stat-info .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #212121;
        }

        .stat-info .stat-label {
            font-size: 12px;
            color: #9e9e9e;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        /* Таблица категорий */
        .table-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }

        .table-wrapper { overflow-x: auto; }

        .admin-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .admin-table th {
            background: #f5f5f5;
            padding: 14px 18px;
            text-align: left;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #616161;
            border-bottom: 2px solid #e0e0e0;
        }

        .admin-table td {
            padding: 14px 18px;
            border-bottom: 1px solid #f5f5f5;
            vertical-align: middle;
        }

        .admin-table tr:hover { background: #fafafa; }

        .admin-table tr:last-child td { border-bottom: none; }

        .category-name {
            font-weight: 600;
            color: #212121;
            font-size: 15px;
        }

        .category-desc {
            font-size: 13px;
            color: #9e9e9e;
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .count-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 13px;
            background: #e3f2fd;
            color: #1565c0;
        }

        .count-badge.zero { background: #f5f5f5; color: #9e9e9e; }

        .action-btns {
            display: flex;
            gap: 8px;
        }

        .action-btns button {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            background: white;
            cursor: pointer;
            font-size: 15px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .action-btns button:hover { border-color: #d32f2f; background: #fff5f5; }

        .action-btns button.delete-btn:hover { border-color: #dc3545; background: #fff5f5; color: #dc3545; }

        /* Модальное окно */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-overlay.show { display: flex; }

        .modal {
            background: white;
            border-radius: 14px;
            padding: 30px;
            width: 100%;
            max-width: 480px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }

        .modal h2 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
            color: #212121;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #616161;
            margin-bottom: 6px;
        }

        .form-group label .required { color: #d32f2f; }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            outline: none;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            border-color: #d32f2f;
            box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.08);
        }

        .form-group textarea { resize: vertical; min-height: 80px; }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 10px;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #9e9e9e;
        }

        .highlight-row {
            animation: highlight 2s ease;
        }

        @keyframes highlight {
            0% { background: #fff3cd; }
            100% { background: transparent; }
        }

        @media (max-width: 768px) {
            .admin-wrapper { flex-direction: column; }
            .admin-sidebar {
                width: 100%;
                height: auto;
                position: static;
                padding: 10px 0;
            }
            .sidebar-nav { flex-direction: row; overflow-x: auto; gap: 2px; }
            .sidebar-nav a {
                border-left: none;
                border-bottom: 3px solid transparent;
                white-space: nowrap;
                padding: 10px 14px;
                font-size: 13px;
            }
            .sidebar-nav a.active {
                border-left: none;
                border-bottom-color: #d32f2f;
            }
            .admin-content { padding: 14px; }
            .admin-table { font-size: 12px; }
            .admin-table th, .admin-table td { padding: 10px 12px; }
            .category-desc { max-width: 150px; }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../includes/header.php'; ?>

    <div class="admin-wrapper">
        
        <aside class="admin-sidebar">
            <div class="sidebar-title">Панель управления</div>
            <nav class="sidebar-nav">
                <a href="/admin/index.php">📊 Дашборд</a>
                <a href="/admin/appointments.php">📅 Записи</a>
                <a href="/admin/services.php">🔧 Услуги</a>
                <a href="/admin/categories.php" class="active">📂 Категории</a>
                <a href="/admin/reviews.php">⭐ Отзывы</a>
                <a href="/admin/users.php">👥 Клиенты</a>
                <hr class="sidebar-divider">
                <a href="/index.php">🏠 На сайт</a>
                <a href="/logout.php">🚪 Выйти</a>
            </nav>
        </aside>

        <main class="admin-content">
            
            <div class="admin-header">
                <h1>📂 Управление категориями</h1>
                <button class="btn btn-primary" onclick="openModal()">➕ Добавить категорию</button>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Статистика -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-icon red">📂</div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $total_categories; ?></div>
                        <div class="stat-label">Всего категорий</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon blue">🔧</div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $total_services; ?></div>
                        <div class="stat-label">Всего услуг</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green">✅</div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo array_sum(array_column($categories, 'active_count')); ?></div>
                        <div class="stat-label">Активных услуг</div>
                    </div>
                </div>
            </div>

            <!-- Таблица категорий -->
            <div class="table-card">
                <?php if (count($categories) > 0): ?>
                    <div class="table-wrapper">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Название</th>
                                    <th>Описание</th>
                                    <th>Услуг (активных/всего)</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $cat): ?>
                                    <tr id="category-<?php echo $cat['id']; ?>">
                                        <td><strong>#<?php echo $cat['id']; ?></strong></td>
                                        <td>
                                            <div class="category-name"><?php echo htmlspecialchars($cat['name']); ?></div>
                                        </td>
                                        <td>
                                            <div class="category-desc" title="<?php echo htmlspecialchars($cat['description'] ?? ''); ?>">
                                                <?php echo htmlspecialchars($cat['description'] ?: '—'); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($cat['services_count'] > 0): ?>
                                                <span class="count-badge">
                                                    <?php echo $cat['active_count']; ?> / <?php echo $cat['services_count']; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="count-badge zero">Нет услуг</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-btns">
                                                <button onclick="editCategory(<?php echo $cat['id']; ?>)" title="Редактировать">
                                                    ✏️
                                                </button>
                                                <button class="delete-btn" 
                                                        onclick="deleteCategory(<?php echo $cat['id']; ?>, '<?php echo htmlspecialchars($cat['name']); ?>', <?php echo $cat['services_count']; ?>)"
                                                        title="Удалить">
                                                    🗑️
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div style="font-size: 48px; margin-bottom: 12px;">📂</div>
                        <p>Категорий пока нет</p>
                        <small>Добавьте первую категорию, чтобы начать добавлять услуги</small>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>

    <!-- Модальное окно -->
    <div class="modal-overlay" id="categoryModal">
        <div class="modal">
            <h2 id="modalTitle">➕ Добавить категорию</h2>
            <form id="categoryForm">
                <input type="hidden" name="action" value="save_category">
                <input type="hidden" name="category_id" id="categoryId" value="0">
                
                <div class="form-group">
                    <label>Название категории <span class="required">*</span></label>
                    <input type="text" name="name" id="categoryName" 
                           placeholder="Например: Диагностика" 
                           required maxlength="100" autofocus>
                    <small style="color: #9e9e9e; font-size: 12px;">От 3 до 100 символов</small>
                </div>
                
                <div class="form-group">
                    <label>Описание</label>
                    <textarea name="description" id="categoryDescription" 
                              placeholder="Краткое описание категории услуг..." 
                              maxlength="500"></textarea>
                    <small style="color: #9e9e9e; font-size: 12px;">Необязательно, до 500 символов</small>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal()">Отмена</button>
                    <button type="submit" class="btn btn-primary">💾 Сохранить</button>
                </div>
            </form>
        </div>
    </div>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>

    <script>
    // ========== МОДАЛЬНОЕ ОКНО ==========
    function openModal(categoryId = null) {
        const modal = document.getElementById('categoryModal');
        const title = document.getElementById('modalTitle');
        const form = document.getElementById('categoryForm');
        
        // Сбрасываем форму
        form.reset();
        document.getElementById('categoryId').value = '0';
        document.getElementById('categoryName').focus();
        
        if (categoryId) {
            title.textContent = '✏️ Редактировать категорию';
            // Загружаем данные через AJAX
            fetch(`/admin/categories.php?edit=${categoryId}&ajax=1`)
                .then(res => {
                    if (!res.ok) throw new Error('Ошибка загрузки');
                    return res.json();
                })
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        return;
                    }
                    document.getElementById('categoryId').value = data.id;
                    document.getElementById('categoryName').value = data.name;
                    document.getElementById('categoryDescription').value = data.description || '';
                })
                .catch(err => {
                    console.error('Ошибка:', err);
                    alert('Не удалось загрузить данные категории');
                });
        } else {
            title.textContent = '➕ Добавить категорию';
        }
        
        modal.classList.add('show');
        setTimeout(() => {
            document.getElementById('categoryName').focus();
        }, 100);
    }

    function closeModal() {
        document.getElementById('categoryModal').classList.remove('show');
    }

    // Закрытие по клику на оверлей
    document.getElementById('categoryModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    // Закрытие по Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeModal();
    });

    // ========== РЕДАКТИРОВАНИЕ ==========
    function editCategory(categoryId) {
        openModal(categoryId);
    }

    // ========== УДАЛЕНИЕ ==========
    function deleteCategory(categoryId, categoryName, servicesCount) {
        if (servicesCount > 0) {
            alert(`⚠️ Нельзя удалить категорию «${categoryName}».\n\nВ ней находится ${servicesCount} услуг(а).\nСначала переместите или удалите эти услуги.`);
            return;
        }
        
        if (!confirm(`Вы уверены, что хотите удалить категорию «${categoryName}»?\n\nЭто действие нельзя отменить.`)) {
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'delete_category');
        formData.append('category_id', categoryId);
        
        fetch('/admin/categories.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.text())
        .then(() => {
            // Удаляем строку из таблицы с анимацией
            const row = document.getElementById('category-' + categoryId);
            if (row) {
                row.style.transition = 'all 0.3s ease';
                row.style.opacity = '0';
                row.style.transform = 'translateX(-20px)';
                setTimeout(() => row.remove(), 300);
            }
            // Показываем сообщение (можно заменить на более красивое)
            location.reload();
        })
        .catch(err => {
            console.error('Ошибка:', err);
            alert('Ошибка при удалении категории');
        });
    }

    // ========== СОХРАНЕНИЕ ФОРМЫ ЧЕРЕЗ AJAX ==========
    document.getElementById('categoryForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const name = formData.get('name').trim();
        
        // Клиентская валидация
        if (!name) {
            alert('Введите название категории');
            return;
        }
        if (name.length < 3) {
            alert('Название должно содержать минимум 3 символа');
            return;
        }
        
        fetch('/admin/categories.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                closeModal();
                location.reload();
            } else {
                alert(data.message);
            }
        })
        .catch(err => {
            console.error('Ошибка:', err);
            alert('Ошибка при сохранении категории');
        });
    });

    // ========== ГОРЯЧИЕ КЛАВИШИ ==========
    document.addEventListener('keydown', function(e) {
        // Ctrl+N — добавить категорию
        if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            openModal();
        }
    });
    </script>
</body>
</html>