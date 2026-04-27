<?php
// admin/users.php - Управление клиентами

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/avatar.php';

requireAuth('admin');

$page_title = 'Клиенты — Панель управления';
$pdo = getDBConnection();

// ============================================================
// AJAX: получение данных клиента
// ============================================================
if (isset($_GET['view']) && isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    $user_id = intval($_GET['view']);
    
    // Данные клиента
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND role = 'client'");
    $stmt->execute(['id' => $user_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'Клиент не найден']);
        exit;
    }
    
    // Автомобили клиента
    $cars = $pdo->prepare("SELECT * FROM cars WHERE user_id = :uid ORDER BY id")->execute(['uid' => $user_id]) 
            ? $pdo->prepare("SELECT * FROM cars WHERE user_id = :uid ORDER BY id")->fetchAll() : [];
    $stmt_cars = $pdo->prepare("SELECT * FROM cars WHERE user_id = :uid ORDER BY id");
    $stmt_cars->execute(['uid' => $user_id]);
    $cars = $stmt_cars->fetchAll();
    
    // Статистика записей
    $stats = $pdo->prepare("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN 
                (SELECT SUM(s2.price) FROM appointment_services aps2 JOIN services s2 ON aps2.service_id = s2.id WHERE aps2.appointment_id = appointments.id)
            END), 0) AS total_spent
        FROM appointments 
        WHERE user_id = :uid
    ");
    $stats->execute(['uid' => $user_id]);
    $user_stats = $stats->fetch();
    
    // Последние 5 записей
    $recent = $pdo->prepare("
        SELECT a.*, c.brand, c.model,
               GROUP_CONCAT(DISTINCT s.name SEPARATOR ', ') AS services_list,
               SUM(s.price) AS total_price
        FROM appointments a
        JOIN cars c ON a.car_id = c.id
        LEFT JOIN appointment_services aps ON a.id = aps.appointment_id
        LEFT JOIN services s ON aps.service_id = s.id
        WHERE a.user_id = :uid
        GROUP BY a.id
        ORDER BY a.appointment_date DESC
        LIMIT 5
    ");
    $recent->execute(['uid' => $user_id]);
    $recent_appointments = $recent->fetchAll();
    
    echo json_encode([
        'user' => $user,
        'cars' => $cars,
        'stats' => $user_stats,
        'appointments' => $recent_appointments
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// ФИЛЬТРАЦИЯ И ПАГИНАЦИЯ
// ============================================================

$search = trim($_GET['search'] ?? '');
$sort = $_GET['sort'] ?? 'newest';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 12;

$where = ["u.role = 'client'"];
$params = [];

if (!empty($search)) {
    $where[] = "(u.full_name LIKE :search1 OR u.email LIKE :search2 OR u.phone LIKE :search3)";
    $params['search1'] = '%' . $search . '%';
    $params['search2'] = '%' . $search . '%';
    $params['search3'] = '%' . $search . '%';
}

$where_clause = 'WHERE ' . implode(' AND ', $where);

// Подсчёт
$count_sql = "SELECT COUNT(*) FROM users u $where_clause";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_users = $count_stmt->fetchColumn();
$total_pages = ceil($total_users / $per_page);
$offset = ($page - 1) * $per_page;

// Сортировка
$order_by = "ORDER BY u.created_at DESC";
switch ($sort) {
    case 'oldest': $order_by = "ORDER BY u.created_at ASC"; break;
    case 'name': $order_by = "ORDER BY u.full_name ASC"; break;
    case 'appointments': $order_by = "ORDER BY appointments_count DESC"; break;
}

// Получаем клиентов со статистикой
$query = "
    SELECT u.*, 
           COUNT(a.id) AS appointments_count,
           COALESCE(SUM(CASE WHEN a.status = 'completed' THEN 
               (SELECT SUM(s2.price) FROM appointment_services aps2 JOIN services s2 ON aps2.service_id = s2.id WHERE aps2.appointment_id = a.id)
           END), 0) AS total_spent,
           MAX(a.appointment_date) AS last_visit
    FROM users u
    LEFT JOIN appointments a ON u.id = a.user_id
    $where_clause
    GROUP BY u.id
    $order_by
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

// Статистика
$total_clients = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'client'")->fetchColumn();
$active_clients = $pdo->query("
    SELECT COUNT(DISTINCT user_id) FROM appointments 
    WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
")->fetchColumn();

function buildUrl($params = []) {
    $get = $_GET;
    foreach ($params as $key => $value) {
        if ($value === null || $value === '' || $value === 'newest') {
            unset($get[$key]);
        } else {
            $get[$key] = $value;
        }
    }
    $get = array_filter($get, function($v) { return $v !== '' && $v !== 'newest'; });
    $url = '/admin/users.php';
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

        .btn-sm { padding: 6px 14px; font-size: 12px; border-radius: 6px; }

        /* Статистика */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 16px 20px;
            border: 1px solid #e0e0e0;
            text-align: center;
        }

        .stat-value { font-size: 28px; font-weight: 700; color: #212121; }
        .stat-label { font-size: 12px; color: #9e9e9e; text-transform: uppercase; letter-spacing: 0.3px; margin-top: 2px; }
        .stat-card.primary .stat-value { color: #d32f2f; }
        .stat-card.success .stat-value { color: #28a745; }

        /* Фильтры */
        .filters-bar {
            background: white;
            border-radius: 12px;
            padding: 16px 20px;
            border: 1px solid #e0e0e0;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label {
            font-size: 11px;
            font-weight: 600;
            color: #616161;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .filter-group input,
        .filter-group select {
            padding: 8px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 13px;
            outline: none;
        }
        .filter-group input:focus,
        .filter-group select:focus {
            border-color: #d32f2f;
            box-shadow: 0 0 0 3px rgba(211,47,47,0.08);
        }

        /* Сетка клиентов */
        .clients-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .client-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e0e0e0;
            padding: 20px;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
        }

        .client-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
            border-color: #d32f2f;
            transform: translateY(-2px);
        }

        .client-card-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 14px;
        }

        .client-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #d32f2f;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 20px;
            flex-shrink: 0;
        }

        .client-info h3 {
            font-size: 16px;
            font-weight: 600;
            color: #212121;
            margin-bottom: 2px;
        }

        .client-info .client-email {
            font-size: 13px;
            color: #616161;
        }

        .client-stats {
            display: flex;
            gap: 20px;
            padding-top: 12px;
            border-top: 1px solid #f5f5f5;
        }

        .client-stat {
            text-align: center;
            flex: 1;
        }

        .client-stat .value {
            font-size: 18px;
            font-weight: 700;
            color: #212121;
        }

        .client-stat .label {
            font-size: 11px;
            color: #9e9e9e;
            text-transform: uppercase;
            letter-spacing: 0.2px;
        }

        .client-badge {
            position: absolute;
            top: 16px;
            right: 16px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }

        .badge-active { background: #d4edda; color: #155724; }
        .badge-new { background: #cce5ff; color: #004085; }

        /* Пагинация */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 6px;
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
        .pagination a { background: white; border: 1px solid #e0e0e0; color: #616161; }
        .pagination a:hover { border-color: #d32f2f; color: #d32f2f; }
        .pagination .active { background: #d32f2f; color: white; border-color: #d32f2f; }
        .pagination .disabled { opacity: 0.4; pointer-events: none; }

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
            max-width: 640px;
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
            gap: 10px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f5f5f5;
            font-size: 14px;
        }
        .detail-row .label { color: #9e9e9e; }
        .detail-row .value { font-weight: 500; color: #212121; }

        .mini-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 10px;
        }

        .mini-table th {
            background: #f5f5f5;
            padding: 8px 10px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            color: #616161;
        }

        .mini-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #f5f5f5;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }

        .status-pending { background: #fff3cd; color: #856404; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        .status-confirmed { background: #cce5ff; color: #004085; }
        .status-in_progress { background: #d1ecf1; color: #0c5460; }

        .car-tag {
            display: inline-block;
            background: #e3f2fd;
            color: #1565c0;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            margin: 2px;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #9e9e9e;
            background: white;
            border-radius: 12px;
        }

        @media (max-width: 768px) {
            .admin-wrapper { flex-direction: column; }
            .admin-sidebar {
                width: 100%;
                height: auto;
                position: static;
                padding: 10px 0;
            }
            .sidebar-nav { flex-direction: row; overflow-x: auto; }
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
            .clients-grid { grid-template-columns: 1fr; }
            .filters-bar { flex-direction: column; }
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
                <a href="/admin/categories.php">📂 Категории</a>
                <a href="/admin/reviews.php">⭐ Отзывы</a>
                <a href="/admin/users.php" class="active">👥 Клиенты</a>
                <hr class="sidebar-divider">
                <a href="/index.php">🏠 На сайт</a>
                <a href="/logout.php">🚪 Выйти</a>
            </nav>
        </aside>

        <main class="admin-content">
            
            <div class="admin-header">
                <h1>👥 Клиенты</h1>
            </div>

            <!-- Статистика -->
            <div class="stats-row">
                <div class="stat-card primary">
                    <div class="stat-value"><?php echo $total_clients; ?></div>
                    <div class="stat-label">Всего клиентов</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-value"><?php echo $active_clients; ?></div>
                    <div class="stat-label">Активных за 30 дней</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $total_users; ?></div>
                    <div class="stat-label">Найдено</div>
                </div>
            </div>

            <!-- Фильтры -->
            <form method="GET" action="/admin/users.php" class="filters-bar">
                <div class="filter-group" style="flex:1; min-width:200px;">
                    <label>🔍 Поиск</label>
                    <input type="text" name="search" placeholder="Имя, email или телефон..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-group" style="min-width:140px;">
                    <label>📋 Сортировка</label>
                    <select name="sort" onchange="this.form.submit()">
                        <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Новые сначала</option>
                        <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Старые сначала</option>
                        <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>По алфавиту</option>
                        <option value="appointments" <?php echo $sort === 'appointments' ? 'selected' : ''; ?>>По числу визитов</option>
                    </select>
                </div>
                <div class="filter-group" style="align-self:flex-end;">
                    <button type="submit" class="btn btn-sm" style="background:#d32f2f; color:white;">Применить</button>
                    <a href="/admin/users.php" class="btn btn-sm" style="border:1px solid #d32f2f; color:#d32f2f;">Сбросить</a>
                </div>
            </form>

            <!-- Сетка клиентов -->
            <?php if (count($users) > 0): ?>
                <div class="clients-grid">
                    <?php foreach ($users as $user): 
                        $is_active = $user['last_visit'] && strtotime($user['last_visit']) > strtotime('-30 days');
                        $badge_class = $is_active ? 'badge-active' : 'badge-new';
                        $badge_text = $is_active ? 'Активный' : ($user['appointments_count'] > 0 ? 'Был давно' : 'Новый');
                    ?>
                        <div class="client-card" onclick="viewClient(<?php echo $user['id']; ?>)">
                            <?php if ($user['appointments_count'] == 0 || $is_active): ?>
                                <span class="client-badge <?php echo $badge_class; ?>"><?php echo $badge_text; ?></span>
                            <?php endif; ?>
                            
                            <div class="client-card-header">
                                <img src="<?php echo htmlspecialchars(getAvatarUrl($user['avatar'])); ?>" 
     alt="<?php echo htmlspecialchars($user['full_name']); ?>"
     style="width: 50px; height: 50px; object-fit: cover; border-radius: 50%; flex-shrink: 0;">
                                <div class="client-info">
                                    <h3><?php echo htmlspecialchars($user['full_name']); ?></h3>
                                    <div class="client-email"><?php echo htmlspecialchars($user['email']); ?></div>
                                </div>
                            </div>

                            <div class="client-stats">
                                <div class="client-stat">
                                    <div class="value"><?php echo $user['appointments_count']; ?></div>
                                    <div class="label">Визитов</div>
                                </div>
                                <div class="client-stat">
                                    <div class="value"><?php echo number_format($user['total_spent'], 0, ',', ' '); ?> ₽</div>
                                    <div class="label">Потрачено</div>
                                </div>
                                <div class="client-stat">
                                    <div class="value">
                                        <?php echo $user['last_visit'] ? date('d.m.y', strtotime($user['last_visit'])) : '—'; ?>
                                    </div>
                                    <div class="label">Последний визит</div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Пагинация -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="<?php echo buildUrl(['page' => $page - 1]); ?>">←</a>
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
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="empty-state">
                    <div style="font-size: 48px; margin-bottom: 12px;">👥</div>
                    <p>Клиентов не найдено</p>
                    <small>Измените параметры поиска</small>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <!-- Модальное окно с деталями клиента -->
    <div class="modal-overlay" id="clientModal">
        <div class="modal" id="clientModalContent">
            <div style="text-align:center; padding:20px;">Загрузка...</div>
        </div>
    </div>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>

    <script>
    // ========== ПРОСМОТР КЛИЕНТА ==========
    async function viewClient(userId) {
        const modal = document.getElementById('clientModal');
        const content = document.getElementById('clientModalContent');
        
        modal.classList.add('show');
        content.innerHTML = '<div style="text-align:center; padding:30px;">⏳ Загрузка данных клиента...</div>';
        
        try {
            const response = await fetch(`/admin/users.php?view=${userId}&ajax=1`);
            const data = await response.json();
            
            if (data.error) {
                content.innerHTML = `<div style="text-align:center; padding:20px; color:#dc3545;">❌ ${data.error}</div>`;
                return;
            }
            
            const user = data.user;
            const cars = data.cars;
            const stats = data.stats;
            const appointments = data.appointments;
            
            const statusLabels = {
                'pending': 'Ожидает',
                'confirmed': 'Подтверждена',
                'in_progress': 'В работе',
                'completed': 'Выполнена',
                'cancelled': 'Отменена'
            };
            
            let carsHTML = cars.length > 0 
                ? cars.map(c => `<span class="car-tag">🚗 ${c.brand} ${c.model} ${c.year || ''} ${c.license_plate ? '· ' + c.license_plate : ''}</span>`).join(' ')
                : '<span style="color:#9e9e9e;">Нет автомобилей</span>';
            
            let appointmentsHTML = appointments.length > 0
                ? `
                    <table class="mini-table">
                        <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Авто</th>
                                <th>Услуги</th>
                                <th>Сумма</th>
                                <th>Статус</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${appointments.map(a => `
                                <tr>
                                    <td>${new Date(a.appointment_date).toLocaleDateString('ru-RU')}</td>
                                    <td>${a.brand} ${a.model}</td>
                                    <td>${(a.services_list || '—').substring(0, 40)}</td>
                                    <td><strong>${Number(a.total_price || 0).toLocaleString('ru-RU')} ₽</strong></td>
                                    <td><span class="status-badge status-${a.status}">${statusLabels[a.status] || a.status}</span></td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `
                : '<p style="color:#9e9e9e; text-align:center;">Нет записей</p>';
            
            content.innerHTML = `
                <h2>👤 ${user.full_name}</h2>
                
                <div class="detail-row"><span class="label">Email</span><span class="value">${user.email}</span></div>
                <div class="detail-row"><span class="label">Телефон</span><span class="value">${user.phone || 'Не указан'}</span></div>
                <div class="detail-row"><span class="label">Дата регистрации</span><span class="value">${new Date(user.created_at).toLocaleDateString('ru-RU')}</span></div>
                
                <h3 style="margin-top:20px; margin-bottom:10px; font-size:16px;">🚗 Автомобили (${cars.length})</h3>
                <div style="margin-bottom:16px;">${carsHTML}</div>
                
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-value">${stats.total}</div>
                        <div class="stat-label">Всего записей</div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-value">${stats.completed}</div>
                        <div class="stat-label">Выполнено</div>
                    </div>
                    <div class="stat-card" style="background:#fff3cd;">
                        <div class="stat-value">${stats.pending}</div>
                        <div class="stat-label">Ожидают</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">${Number(stats.total_spent).toLocaleString('ru-RU')} ₽</div>
                        <div class="stat-label">Всего потрачено</div>
                    </div>
                </div>
                
                <h3 style="margin-top:20px; margin-bottom:10px; font-size:16px;">📋 Последние записи</h3>
                ${appointmentsHTML}
                
                <div class="modal-actions" style="margin-top:20px; text-align:right;">
                    <button class="btn btn-outline btn-sm" onclick="closeClientModal()">Закрыть</button>
                </div>
            `;
            
        } catch (err) {
            console.error('Ошибка:', err);
            content.innerHTML = '<div style="text-align:center; padding:20px; color:#dc3545;">❌ Ошибка загрузки данных</div>';
        }
    }

    function closeClientModal() {
        document.getElementById('clientModal').classList.remove('show');
    }

    // Закрытие по клику на оверлей
    document.getElementById('clientModal').addEventListener('click', function(e) {
        if (e.target === this) closeClientModal();
    });

    // Закрытие по Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeClientModal();
    });
    </script>
</body>
</html>