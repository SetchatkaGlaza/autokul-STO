<?php
// admin/appointments.php - Управление записями

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth_check.php';

// Требуем авторизацию (админ или механик)
requireAuth('admin');
// Механикам тоже можно
$is_admin = hasRole('admin');

$page_title = 'Управление записями — Панель управления';
$pdo = getDBConnection();

// Параметры фильтрации
$filter_status = $_GET['status'] ?? 'all';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$search = trim($_GET['search'] ?? '');
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 15;

// Обработка смены статуса (AJAX или обычный POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_status') {
    $appointment_id = intval($_POST['appointment_id'] ?? 0);
    $new_status = $_POST['new_status'] ?? '';
    
    $allowed_statuses = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'];
    
    if (in_array($new_status, $allowed_statuses)) {
        try {
            $stmt = $pdo->prepare("UPDATE appointments SET status = :status WHERE id = :id");
            $stmt->execute(['status' => $new_status, 'id' => $appointment_id]);
            
            // Если это AJAX-запрос — возвращаем JSON
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'new_status' => $new_status]);
                exit;
            }
            
            // Иначе редирект
            header('Location: /admin/appointments.php?' . http_build_query($_GET));
            exit;
        } catch (PDOException $e) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Ошибка при обновлении']);
                exit;
            }
        }
    }
}

// Строим запрос с фильтрацией
$where = [];
$params = [];

if ($filter_status !== 'all') {
    $where[] = "a.status = :status";
    $params['status'] = $filter_status;
}

if (!empty($filter_date_from)) {
    $where[] = "a.appointment_date >= :date_from";
    $params['date_from'] = $filter_date_from;
}

if (!empty($filter_date_to)) {
    $where[] = "a.appointment_date <= :date_to";
    $params['date_to'] = $filter_date_to;
}

if (!empty($search)) {
    $where[] = "(u.full_name LIKE :search_name OR u.email LIKE :search_email OR u.phone LIKE :search_phone OR c.brand LIKE :search_brand OR c.model LIKE :search_model OR c.license_plate LIKE :search_plate)";
    $search_param = '%' . $search . '%';
    $params['search_name'] = $search_param;
    $params['search_email'] = $search_param;
    $params['search_phone'] = $search_param;
    $params['search_brand'] = $search_param;
    $params['search_model'] = $search_param;
    $params['search_plate'] = $search_param;
}

$where_clause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

// Подсчёт общего количества
$count_query = "
    SELECT COUNT(DISTINCT a.id)
    FROM appointments a
    JOIN users u ON a.user_id = u.id
    JOIN cars c ON a.car_id = c.id
    $where_clause
";
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_appointments = $count_stmt->fetchColumn();
$total_pages = ceil($total_appointments / $per_page);
$offset = ($page - 1) * $per_page;

// Получаем записи
$query = "
    SELECT a.*, 
           u.full_name AS client_name, u.email AS client_email, u.phone AS client_phone,
           c.brand, c.model, c.year, c.license_plate, c.vin,
           GROUP_CONCAT(DISTINCT s.name ORDER BY s.id SEPARATOR ', ') AS services_list,
           SUM(s.duration) AS total_duration,
           SUM(s.price) AS total_price
    FROM appointments a
    JOIN users u ON a.user_id = u.id
    JOIN cars c ON a.car_id = c.id
    LEFT JOIN appointment_services aps ON a.id = aps.appointment_id
    LEFT JOIN services s ON aps.service_id = s.id
    $where_clause
    GROUP BY a.id
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$appointments = $stmt->fetchAll();

// Статистика по статусам для вкладок
$status_counts = $pdo->query("
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
    FROM appointments
")->fetch();

// Формируем URL для сохранения фильтров
function buildUrl($params = []) {
    $get = $_GET;
    foreach ($params as $key => $value) {
        $get[$key] = $value;
    }
    // Убираем пустые параметры
    $get = array_filter($get, function($v) { return $v !== '' && $v !== 'all'; });
    $url = '/admin/appointments.php';
    if (!empty($get)) {
        $url .= '?' . http_build_query($get);
    }
    return $url;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        :root {
            --sidebar-width: 250px;
        }

        .admin-wrapper {
            display: flex;
            min-height: calc(100vh - var(--header-height));
        }

        .admin-sidebar {
            width: var(--sidebar-width);
            background: var(--secondary);
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

        .sidebar-nav {
            display: flex;
            flex-direction: column;
        }

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

        .sidebar-nav a:hover {
            background: rgba(255,255,255,0.05);
            color: white;
        }

        .sidebar-nav a.active {
            background: rgba(211, 47, 47, 0.2);
            color: white;
            border-left-color: #d32f2f;
        }

        .sidebar-nav a .nav-badge {
            margin-left: auto;
            background: #d32f2f;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
        }

        .sidebar-divider {
            border: none;
            border-top: 1px solid rgba(255,255,255,0.1);
            margin: 10px 0;
        }

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

        .admin-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: #212121;
        }

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

        .btn-primary {
            background: #d32f2f;
            color: white;
        }

        .btn-primary:hover {
            background: #b71c1c;
        }

        .btn-outline {
            background: white;
            color: #d32f2f;
            border: 1px solid #d32f2f;
        }

        .btn-outline:hover {
            background: #d32f2f;
            color: white;
        }

        .btn-sm {
            padding: 5px 12px;
            font-size: 12px;
            border-radius: 6px;
        }

        /* Фильтры */
        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 20px 24px;
            border: 1px solid #e0e0e0;
            margin-bottom: 20px;
        }

        .filters-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .filters-row .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .filters-row label {
            font-size: 12px;
            font-weight: 600;
            color: #616161;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .filters-row input,
        .filters-row select {
            padding: 8px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: all 0.3s ease;
            min-width: 130px;
        }

        .filters-row input:focus,
        .filters-row select:focus {
            border-color: #d32f2f;
            box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.08);
        }

        /* Вкладки статусов */
        .status-tabs {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .status-tab {
            padding: 8px 18px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            border: 2px solid #e0e0e0;
            background: white;
            color: #616161;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .status-tab:hover {
            border-color: #d32f2f;
            color: #d32f2f;
        }

        .status-tab.active {
            background: #d32f2f;
            color: white;
            border-color: #d32f2f;
        }

        .status-tab .count {
            background: rgba(0,0,0,0.1);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-tab.active .count {
            background: rgba(255,255,255,0.25);
        }

        /* Таблица */
        .table-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .admin-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .admin-table th {
            background: #f5f5f5;
            padding: 12px 14px;
            text-align: left;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #616161;
            border-bottom: 2px solid #e0e0e0;
            white-space: nowrap;
        }

        .admin-table td {
            padding: 14px;
            border-bottom: 1px solid #f5f5f5;
            vertical-align: middle;
        }

        .admin-table tr:hover {
            background: #fafafa;
        }

        .admin-table tr:last-child td {
            border-bottom: none;
        }

        /* Статусы */
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }

        .status-pending { background: #fff3cd; color: #856404; }
        .status-confirmed { background: #cce5ff; color: #004085; }
        .status-in_progress { background: #d1ecf1; color: #0c5460; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }

        /* Выпадающее меню статуса */
        .status-dropdown {
            position: relative;
            display: inline-block;
        }

        .status-dropdown-btn {
            padding: 6px 10px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            background: white;
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
        }

        .status-dropdown-btn:hover {
            border-color: #d32f2f;
        }

        .status-dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
            z-index: 100;
            min-width: 180px;
            padding: 6px;
        }

        .status-dropdown-menu.show {
            display: block;
        }

        .status-dropdown-menu button {
            display: block;
            width: 100%;
            padding: 8px 12px;
            border: none;
            background: none;
            cursor: pointer;
            text-align: left;
            font-size: 13px;
            border-radius: 6px;
            transition: all 0.2s ease;
        }

        .status-dropdown-menu button:hover {
            background: #f5f5f5;
        }

        .client-info small {
            color: #9e9e9e;
            display: block;
        }

        .car-info small {
            color: #9e9e9e;
            font-size: 11px;
        }

        /* Пагинация */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 6px;
            padding: 20px;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 13px;
            text-decoration: none;
        }

        .pagination a {
            background: white;
            border: 1px solid #e0e0e0;
            color: #616161;
        }

        .pagination a:hover {
            border-color: #d32f2f;
            color: #d32f2f;
        }

        .pagination .active {
            background: #d32f2f;
            color: white;
            border-color: #d32f2f;
        }

        .pagination .disabled {
            opacity: 0.4;
            pointer-events: none;
        }

        /* Пустое состояние */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #9e9e9e;
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 12px;
        }

        /* Адаптивность */
        @media (max-width: 768px) {
            .admin-wrapper {
                flex-direction: column;
            }

            .admin-sidebar {
                width: 100%;
                height: auto;
                position: static;
                padding: 10px 0;
            }

            .sidebar-nav {
                flex-direction: row;
                overflow-x: auto;
                gap: 2px;
            }

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

            .admin-content {
                padding: 14px;
            }

            .filters-row {
                flex-direction: column;
            }

            .filters-row input,
            .filters-row select {
                width: 100%;
            }

            .admin-table {
                font-size: 11px;
            }

            .admin-table th,
            .admin-table td {
                padding: 8px 6px;
            }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../includes/header.php'; ?>

    <div class="admin-wrapper">
        
        <!-- Сайдбар -->
        <aside class="admin-sidebar">
            <div class="sidebar-title">Панель управления</div>
            <nav class="sidebar-nav">
                <a href="/admin/index.php">📊 Дашборд</a>
                <a href="/admin/appointments.php" class="active">
                    📅 Записи
                    <?php if ($status_counts['pending'] > 0): ?>
                        <span class="nav-badge"><?php echo $status_counts['pending']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="/admin/services.php">🔧 Услуги</a>
                <a href="/admin/categories.php">📂 Категории</a>
                <a href="/admin/reviews.php">⭐ Отзывы</a>
                <a href="/admin/users.php">👥 Клиенты</a>
                <hr class="sidebar-divider">
                <a href="/index.php">🏠 На сайт</a>
                <a href="/logout.php">🚪 Выйти</a>
            </nav>
        </aside>

        <!-- Контент -->
        <main class="admin-content">
            
            <div class="admin-header">
                <h1>📅 Управление записями</h1>
                <span style="color: #9e9e9e; font-size: 14px;">
                    Всего: <?php echo $status_counts['total']; ?> записей
                </span>
            </div>

            <!-- Вкладки статусов -->
            <div class="status-tabs">
                <a href="<?php echo buildUrl(['status' => 'all', 'page' => 1]); ?>" 
                   class="status-tab <?php echo $filter_status === 'all' ? 'active' : ''; ?>">
                    Все <span class="count"><?php echo $status_counts['total']; ?></span>
                </a>
                <a href="<?php echo buildUrl(['status' => 'pending', 'page' => 1]); ?>" 
                   class="status-tab <?php echo $filter_status === 'pending' ? 'active' : ''; ?>">
                    ⏳ Ожидают <span class="count"><?php echo $status_counts['pending']; ?></span>
                </a>
                <a href="<?php echo buildUrl(['status' => 'confirmed', 'page' => 1]); ?>" 
                   class="status-tab <?php echo $filter_status === 'confirmed' ? 'active' : ''; ?>">
                    ✅ Подтверждены <span class="count"><?php echo $status_counts['confirmed']; ?></span>
                </a>
                <a href="<?php echo buildUrl(['status' => 'in_progress', 'page' => 1]); ?>" 
                   class="status-tab <?php echo $filter_status === 'in_progress' ? 'active' : ''; ?>">
                    🔧 В работе <span class="count"><?php echo $status_counts['in_progress']; ?></span>
                </a>
                <a href="<?php echo buildUrl(['status' => 'completed', 'page' => 1]); ?>" 
                   class="status-tab <?php echo $filter_status === 'completed' ? 'active' : ''; ?>">
                    ✔️ Выполнены <span class="count"><?php echo $status_counts['completed']; ?></span>
                </a>
                <a href="<?php echo buildUrl(['status' => 'cancelled', 'page' => 1]); ?>" 
                   class="status-tab <?php echo $filter_status === 'cancelled' ? 'active' : ''; ?>">
                    ❌ Отменены <span class="count"><?php echo $status_counts['cancelled']; ?></span>
                </a>
            </div>

            <!-- Фильтры -->
            <form method="GET" action="/admin/appointments.php" class="filters-card" id="filterForm">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
                <div class="filters-row">
                    <div class="filter-group" style="flex: 1; min-width: 200px;">
                        <label>🔍 Поиск</label>
                        <input type="text" name="search" placeholder="Имя, email, телефон, авто..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="filter-group">
                        <label>📅 Дата с</label>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                    </div>
                    <div class="filter-group">
                        <label>📅 Дата по</label>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                    </div>
                    <div class="filter-group" style="align-self: flex-end;">
                        <button type="submit" class="btn btn-primary" style="padding: 8px 16px;">Применить</button>
                        <a href="/admin/appointments.php" class="btn btn-outline" style="padding: 8px 16px;">Сбросить</a>
                    </div>
                </div>
            </form>

            <!-- Таблица записей -->
            <div class="table-card">
                <?php if (count($appointments) > 0): ?>
                    <div class="table-wrapper">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Дата</th>
                                    <th>Время</th>
                                    <th>Клиент</th>
                                    <th>Автомобиль</th>
                                    <th>Услуги</th>
                                    <th>Длит.</th>
                                    <th>Сумма</th>
                                    <th>Статус</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($appointments as $appt): ?>
                                    <tr id="row-<?php echo $appt['id']; ?>">
                                        <td><strong>#<?php echo $appt['id']; ?></strong></td>
                                        <td><?php echo date('d.m.Y', strtotime($appt['appointment_date'])); ?></td>
                                        <td><?php echo date('H:i', strtotime($appt['appointment_time'])); ?></td>
                                        <td class="client-info">
                                            <?php echo htmlspecialchars($appt['client_name']); ?>
                                            <?php if ($appt['client_phone']): ?>
                                                <small><?php echo htmlspecialchars($appt['client_phone']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="car-info">
                                            <?php echo htmlspecialchars($appt['brand'] . ' ' . $appt['model']); ?>
                                            <?php if ($appt['year']): ?>
                                                <small><?php echo $appt['year']; ?> г.</small>
                                            <?php endif; ?>
                                            <?php if ($appt['license_plate']): ?>
                                                <small>🚘 <?php echo htmlspecialchars($appt['license_plate']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars(mb_substr($appt['services_list'] ?? '—', 0, 50)); ?>
                                            <?php if (mb_strlen($appt['services_list'] ?? '') > 50): ?>...<?php endif; ?>
                                        </td>
                                        <td><?php echo $appt['total_duration'] ?? '—'; ?> мин.</td>
                                        <td>
                                            <strong style="color: #d32f2f;">
                                                <?php echo number_format($appt['total_price'] ?? 0, 0, ',', ' '); ?> ₽
                                            </strong>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $appt['status']; ?>" id="status-badge-<?php echo $appt['id']; ?>">
                                                <?php 
                                                $status_labels = [
                                                    'pending' => '⏳ Ожидает',
                                                    'confirmed' => '✅ Подтверждена',
                                                    'in_progress' => '🔧 В работе',
                                                    'completed' => '✔️ Выполнена',
                                                    'cancelled' => '❌ Отменена'
                                                ];
                                                echo $status_labels[$appt['status']] ?? $appt['status'];
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="status-dropdown">
                                                <button class="status-dropdown-btn" onclick="toggleDropdown(<?php echo $appt['id']; ?>)">
                                                    ⚙️
                                                </button>
                                                <div class="status-dropdown-menu" id="dropdown-<?php echo $appt['id']; ?>">
                                                    <?php 
                                                    $all_statuses = [
                                                        'pending' => '⏳ Ожидает',
                                                        'confirmed' => '✅ Подтверждена',
                                                        'in_progress' => '🔧 В работе',
                                                        'completed' => '✔️ Выполнена',
                                                        'cancelled' => '❌ Отменена'
                                                    ];
                                                    foreach ($all_statuses as $value => $label): 
                                                        if ($value === $appt['status']) continue;
                                                    ?>
                                                        <button onclick="changeStatus(<?php echo $appt['id']; ?>, '<?php echo $value; ?>')">
                                                            <?php echo $label; ?>
                                                        </button>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <?php if (!empty($appt['notes'])): ?>
                                                <span style="cursor: help; font-size: 14px;" 
                                                      title="Примечание: <?php echo htmlspecialchars($appt['notes']); ?>">💬</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Пагинация -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="<?php echo buildUrl(['page' => $page - 1]); ?>">←</a>
                            <?php else: ?>
                                <span class="disabled">←</span>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $total_pages; $i++): 
                                if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)):
                            ?>
                                <?php if ($i == $page): ?>
                                    <span class="active"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="<?php echo buildUrl(['page' => $i]); ?>"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                                <span>...</span>
                            <?php endif; endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="<?php echo buildUrl(['page' => $page + 1]); ?>">→</a>
                            <?php else: ?>
                                <span class="disabled">→</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <p>Записей не найдено</p>
                        <small>Попробуйте изменить параметры фильтрации</small>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>

    <script>
    // Переключение выпадающего меню
    function toggleDropdown(id) {
        // Закрываем все другие меню
        document.querySelectorAll('.status-dropdown-menu.show').forEach(menu => {
            if (menu.id !== 'dropdown-' + id) {
                menu.classList.remove('show');
            }
        });
        document.getElementById('dropdown-' + id).classList.toggle('show');
    }

    // Закрытие меню при клике вне его
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.status-dropdown')) {
            document.querySelectorAll('.status-dropdown-menu.show').forEach(menu => {
                menu.classList.remove('show');
            });
        }
    });

    // Смена статуса через AJAX
    async function changeStatus(appointmentId, newStatus) {
        if (!confirm('Изменить статус записи #' + appointmentId + '?')) {
            return;
        }

        // Закрываем меню
        document.getElementById('dropdown-' + appointmentId).classList.remove('show');

        try {
            const formData = new FormData();
            formData.append('action', 'change_status');
            formData.append('appointment_id', appointmentId);
            formData.append('new_status', newStatus);

            const response = await fetch('/admin/appointments.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (data.success) {
                // Обновляем бейдж статуса
                const badge = document.getElementById('status-badge-' + appointmentId);
                const statusLabels = {
                    'pending': '⏳ Ожидает',
                    'confirmed': '✅ Подтверждена',
                    'in_progress': '🔧 В работе',
                    'completed': '✔️ Выполнена',
                    'cancelled': '❌ Отменена'
                };
                
                badge.className = 'status-badge status-' + newStatus;
                badge.textContent = statusLabels[newStatus] || newStatus;

                // Подсвечиваем строку
                const row = document.getElementById('row-' + appointmentId);
                row.style.transition = 'background 0.4s ease';
                row.style.background = '#e8f5e9';
                setTimeout(() => {
                    row.style.background = '';
                }, 1500);

            } else {
                alert('Ошибка при изменении статуса');
            }
        } catch (err) {
            console.error('Ошибка:', err);
            alert('Ошибка соединения');
        }
    }
    </script>
</body>
</html>