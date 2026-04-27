<?php
// admin/index.php - Панель управления (дашборд)

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth_check.php';

// Требуем авторизацию с ролью admin или mechanic
requireAuth('admin');

// Если механик — пускаем, но с ограниченным функционалом
$is_admin = hasRole('admin');
$is_mechanic = hasRole('mechanic');

$page_title = 'Панель управления — Автокул СТО';
$pdo = getDBConnection();

// ========== ОСНОВНЫЕ МЕТРИКИ ==========

// Количество записей по статусам
$appointments_stats = $pdo->query("
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
        SUM(CASE WHEN appointment_date = CURDATE() AND status != 'cancelled' THEN 1 ELSE 0 END) AS today
    FROM appointments
")->fetch();

// Количество клиентов
$clients_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'client'")->fetchColumn();

// Количество услуг
$services_count = $pdo->query("SELECT COUNT(*) FROM services WHERE is_active = 1")->fetchColumn();

// Количество отзывов на модерации
$reviews_pending = $pdo->query("SELECT COUNT(*) FROM reviews WHERE is_approved = 0")->fetchColumn();

// Средний рейтинг
$avg_rating = $pdo->query("SELECT ROUND(AVG(rating), 1) FROM reviews WHERE is_approved = 1")->fetchColumn();

// Выручка за текущий месяц (по выполненным записям)
$month_revenue = $pdo->query("
    SELECT COALESCE(SUM(s.price), 0)
    FROM appointments a
    JOIN appointment_services aps ON a.id = aps.appointment_id
    JOIN services s ON aps.service_id = s.id
    WHERE a.status = 'completed' 
      AND MONTH(a.appointment_date) = MONTH(CURDATE())
      AND YEAR(a.appointment_date) = YEAR(CURDATE())
")->fetchColumn();

// Записи на сегодня
$today_appointments = $pdo->query("
    SELECT a.*, u.full_name AS client_name, u.phone AS client_phone,
           c.brand, c.model, c.license_plate,
           GROUP_CONCAT(DISTINCT s.name SEPARATOR ', ') AS services_list,
           SUM(s.duration) AS total_duration
    FROM appointments a
    JOIN users u ON a.user_id = u.id
    JOIN cars c ON a.car_id = c.id
    LEFT JOIN appointment_services aps ON a.id = aps.appointment_id
    LEFT JOIN services s ON aps.service_id = s.id
    WHERE a.appointment_date = CURDATE() AND a.status != 'cancelled'
    GROUP BY a.id
    ORDER BY a.appointment_time ASC
")->fetchAll();

// Новые отзывы на модерацию
$pending_reviews = $pdo->query("
    SELECT r.*, u.full_name AS user_name
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    WHERE r.is_approved = 0
    ORDER BY r.created_at DESC
    LIMIT 5
")->fetchAll();

// Статистика за последние 7 дней (для графика)
$week_stats = $pdo->query("
    SELECT 
        DATE(appointment_date) AS date,
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
    FROM appointments
    WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(appointment_date)
    ORDER BY date ASC
")->fetchAll();

// Заполняем пропущенные дни нулями
$week_data = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $week_data[$date] = ['total' => 0, 'completed' => 0, 'cancelled' => 0, 'date' => date('d.m', strtotime($date))];
}
foreach ($week_stats as $row) {
    $week_data[$row['date']] = [
        'total' => (int)$row['total'],
        'completed' => (int)$row['completed'],
        'cancelled' => (int)$row['cancelled'],
        'date' => date('d.m', strtotime($row['date']))
    ];
}

// Топ услуг за месяц
$top_services = $pdo->query("
    SELECT s.name, COUNT(*) AS count, SUM(s.price) AS revenue
    FROM appointments a
    JOIN appointment_services aps ON a.id = aps.appointment_id
    JOIN services s ON aps.service_id = s.id
    WHERE a.status = 'completed'
      AND MONTH(a.appointment_date) = MONTH(CURDATE())
      AND YEAR(a.appointment_date) = YEAR(CURDATE())
    GROUP BY s.id
    ORDER BY count DESC
    LIMIT 5
")->fetchAll();

// Подключаем шапку (без стандартной, создадим свою для админки)
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        /* ========== СТИЛИ АДМИН-ПАНЕЛИ ========== */
        :root {
            --sidebar-width: 250px;
        }

        .admin-wrapper {
            display: flex;
            min-height: calc(100vh - var(--header-height));
        }

        /* ========== САЙДБАР ========== */
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
            transition: var(--transition);
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
            border-left-color: var(--primary);
        }

        .sidebar-nav a .nav-badge {
            margin-left: auto;
            background: var(--primary);
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

        /* ========== ОСНОВНОЙ КОНТЕНТ ========== */
        .admin-content {
            flex: 1;
            padding: 24px;
            background: var(--gray-100);
            min-width: 0;
        }

        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .admin-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--secondary);
        }

        .admin-header .date-info {
            font-size: 14px;
            color: var(--gray-500);
        }

        /* ========== СЕТКА МЕТРИК ========== */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .metric-card {
            background: var(--white);
            border-radius: 12px;
            padding: 20px 24px;
            border: 1px solid var(--gray-200);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .metric-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
            transform: translateY(-1px);
        }

        .metric-card .metric-icon {
            position: absolute;
            top: 16px;
            right: 16px;
            font-size: 28px;
            opacity: 0.2;
        }

        .metric-card .metric-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-500);
            font-weight: 600;
            margin-bottom: 6px;
        }

        .metric-card .metric-value {
            font-size: 30px;
            font-weight: 800;
            color: var(--secondary);
        }

        .metric-card.accent .metric-value {
            color: var(--primary);
        }

        .metric-card.success .metric-value {
            color: #28a745;
        }

        .metric-card.warning .metric-value {
            color: #f57f17;
        }

        .metric-card.info .metric-value {
            color: #1976d2;
        }

        /* ========== ДВЕ КОЛОНКИ ========== */
        .admin-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        .admin-card {
            background: var(--white);
            border-radius: 12px;
            padding: 24px;
            border: 1px solid var(--gray-200);
        }

        .admin-card h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--secondary);
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--gray-100);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ========== ГРАФИК ========== */
        .chart-container {
            display: flex;
            align-items: flex-end;
            gap: 12px;
            height: 180px;
            padding: 10px 0;
            border-bottom: 2px solid var(--gray-200);
            position: relative;
        }

        .chart-bar-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            height: 100%;
        }

        .chart-bars {
            flex: 1;
            display: flex;
            align-items: flex-end;
            gap: 4px;
            width: 100%;
            justify-content: center;
        }

        .chart-bar {
            width: 14px;
            border-radius: 4px 4px 0 0;
            transition: height 0.4s ease;
            min-height: 2px;
            position: relative;
            cursor: pointer;
        }

        .chart-bar.completed {
            background: #28a745;
        }

        .chart-bar.cancelled {
            background: #dc3545;
        }

        .chart-bar:hover {
            opacity: 0.8;
        }

        .chart-bar[data-tooltip]:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: var(--secondary);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            white-space: nowrap;
            margin-bottom: 4px;
        }

        .chart-label {
            font-size: 11px;
            color: var(--gray-500);
            text-align: center;
        }

        .chart-legend {
            display: flex;
            gap: 16px;
            margin-top: 12px;
            font-size: 12px;
            color: var(--gray-500);
        }

        .chart-legend span {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 2px;
        }

        /* ========== ТАБЛИЦА ЗАПИСЕЙ НА СЕГОДНЯ ========== */
        .table-wrapper {
            overflow-x: auto;
        }

        .admin-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .admin-table th {
            background: var(--gray-100);
            padding: 10px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: var(--gray-700);
            border-bottom: 2px solid var(--gray-200);
        }

        .admin-table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--gray-100);
        }

        .admin-table tr:hover {
            background: var(--gray-100);
        }

        /* Статусы */
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
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

        /* ========== ТОП УСЛУГ ========== */
        .top-services-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .top-service-item {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .top-service-rank {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
        }

        .top-service-item:nth-child(1) .top-service-rank { background: #ffd700; color: #333; }
        .top-service-item:nth-child(2) .top-service-rank { background: #c0c0c0; color: #333; }
        .top-service-item:nth-child(3) .top-service-rank { background: #cd7f32; color: white; }

        .top-service-info {
            flex: 1;
            min-width: 0;
        }

        .top-service-name {
            font-size: 14px;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .top-service-count {
            font-size: 12px;
            color: var(--gray-500);
        }

        .top-service-revenue {
            font-weight: 600;
            font-size: 14px;
            color: var(--primary);
        }

        /* ========== ОТЗЫВЫ НА МОДЕРАЦИИ ========== */
        .review-mini {
            padding: 12px 0;
            border-bottom: 1px solid var(--gray-100);
        }

        .review-mini:last-child {
            border-bottom: none;
        }

        .review-mini-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
            font-size: 13px;
        }

        .review-mini-author {
            font-weight: 600;
            color: var(--secondary);
        }

        .review-mini-stars {
            color: #ffc107;
            font-size: 13px;
        }

        .review-mini-text {
            font-size: 13px;
            color: var(--gray-500);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .no-data {
            text-align: center;
            color: var(--gray-400);
            padding: 20px;
            font-size: 14px;
        }

        /* ========== АДАПТИВНОСТЬ ========== */
        @media (max-width: 1024px) {
            .admin-grid {
                grid-template-columns: 1fr;
            }
        }

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
                border-bottom-color: var(--primary);
            }

            .metrics-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .admin-content {
                padding: 14px;
            }

            .chart-container {
                height: 120px;
                gap: 6px;
            }

            .chart-bar {
                width: 8px;
            }
        }
    </style>
</head>
<body>
    <!-- ШАПКА -->
    <?php require_once __DIR__ . '/../includes/header.php'; ?>

    <div class="admin-wrapper">
        
        <!-- САЙДБАР -->
        <aside class="admin-sidebar">
            <div class="sidebar-title">Панель управления</div>
            <nav class="sidebar-nav">
                <a href="/admin/index.php" class="active">
                    📊 Дашборд
                </a>
                <a href="/admin/appointments.php">
                    📅 Записи
                    <?php if ($appointments_stats['pending'] > 0): ?>
                        <span class="nav-badge"><?php echo $appointments_stats['pending']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="/admin/services.php">
                    🔧 Услуги
                </a>
                <a href="/admin/categories.php">
                    📂 Категории
                </a>
                <a href="/admin/reviews.php">
                    ⭐ Отзывы
                    <?php if ($reviews_pending > 0): ?>
                        <span class="nav-badge"><?php echo $reviews_pending; ?></span>
                    <?php endif; ?>
                </a>
                <a href="/admin/users.php">
                    👥 Клиенты
                </a>
                <hr class="sidebar-divider">
                <a href="/index.php">
                    🏠 На сайт
                </a>
                <a href="/logout.php">
                    🚪 Выйти
                </a>
            </nav>
        </aside>

        <!-- ОСНОВНОЙ КОНТЕНТ -->
        <main class="admin-content">
            
            <div class="admin-header">
                <div>
                    <h1>📊 Дашборд</h1>
                    <span class="date-info"><?php echo date('d.m.Y, l'); ?> · Добро пожаловать, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</span>
                </div>
            </div>

            <!-- Метрики -->
            <div class="metrics-grid">
                <div class="metric-card accent">
                    <div class="metric-icon">📅</div>
                    <div class="metric-label">Записи сегодня</div>
                    <div class="metric-value"><?php echo $appointments_stats['today']; ?></div>
                </div>
                <div class="metric-card warning">
                    <div class="metric-icon">⏳</div>
                    <div class="metric-label">Ожидают</div>
                    <div class="metric-value"><?php echo $appointments_stats['pending']; ?></div>
                </div>
                <div class="metric-card success">
                    <div class="metric-icon">✅</div>
                    <div class="metric-label">Выполнено</div>
                    <div class="metric-value"><?php echo $appointments_stats['completed']; ?></div>
                </div>
                <div class="metric-card info">
                    <div class="metric-icon">💰</div>
                    <div class="metric-label">Выручка за месяц</div>
                    <div class="metric-value"><?php echo number_format($month_revenue, 0, ',', ' '); ?> ₽</div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon">👥</div>
                    <div class="metric-label">Клиентов</div>
                    <div class="metric-value"><?php echo $clients_count; ?></div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon">⭐</div>
                    <div class="metric-label">Средний рейтинг</div>
                    <div class="metric-value"><?php echo $avg_rating ?: '—'; ?></div>
                </div>
            </div>

            <!-- Основной контент: график + сайдбар -->
            <div class="admin-grid">
                
                <!-- График за 7 дней -->
                <div class="admin-card">
                    <h3>📈 Активность за 7 дней</h3>
                    
                    <?php 
                    $max_value = max(array_column($week_data, 'total')) ?: 1;
                    ?>
                    <div class="chart-container">
                        <?php foreach ($week_data as $date => $data): 
                            $completed_height = ($data['completed'] / $max_value) * 100;
                            $cancelled_height = ($data['cancelled'] / $max_value) * 100;
                        ?>
                            <div class="chart-bar-wrapper">
                                <div class="chart-bars">
                                    <?php if ($data['completed'] > 0): ?>
                                        <div class="chart-bar completed" 
                                             style="height: <?php echo max($completed_height, 3); ?>%;"
                                             data-tooltip="Выполнено: <?php echo $data['completed']; ?>"></div>
                                    <?php endif; ?>
                                    <?php if ($data['cancelled'] > 0): ?>
                                        <div class="chart-bar cancelled" 
                                             style="height: <?php echo max($cancelled_height, 3); ?>%;"
                                             data-tooltip="Отменено: <?php echo $data['cancelled']; ?>"></div>
                                    <?php endif; ?>
                                    <?php if ($data['completed'] == 0 && $data['cancelled'] == 0): ?>
                                        <div style="width: 14px; height: 2px; background: var(--gray-300); border-radius: 2px;"></div>
                                    <?php endif; ?>
                                </div>
                                <span class="chart-label"><?php echo $data['date']; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="chart-legend">
                        <span><span class="legend-dot" style="background: #28a745;"></span> Выполнено</span>
                        <span><span class="legend-dot" style="background: #dc3545;"></span> Отменено</span>
                    </div>
                </div>

                <!-- Топ услуг -->
                <div class="admin-card">
                    <h3>🔥 Топ услуг за месяц</h3>
                    <?php if (count($top_services) > 0): ?>
                        <div class="top-services-list">
                            <?php foreach ($top_services as $index => $svc): ?>
                                <div class="top-service-item">
                                    <span class="top-service-rank"><?php echo $index + 1; ?></span>
                                    <div class="top-service-info">
                                        <div class="top-service-name"><?php echo htmlspecialchars($svc['name']); ?></div>
                                        <div class="top-service-count"><?php echo $svc['count']; ?> заказ(ов)</div>
                                    </div>
                                    <span class="top-service-revenue"><?php echo number_format($svc['revenue'], 0, ',', ' '); ?> ₽</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-data">Нет данных за этот месяц</p>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Таблица: Записи на сегодня -->
            <div class="admin-card" style="margin-bottom: 20px;">
                <h3>📋 Записи на сегодня (<?php echo date('d.m.Y'); ?>)</h3>
                
                <?php if (count($today_appointments) > 0): ?>
                    <div class="table-wrapper">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Время</th>
                                    <th>Клиент</th>
                                    <th>Автомобиль</th>
                                    <th>Услуги</th>
                                    <th>Длит.</th>
                                    <th>Статус</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($today_appointments as $appt): ?>
                                    <tr>
                                        <td><strong><?php echo date('H:i', strtotime($appt['appointment_time'])); ?></strong></td>
                                        <td>
                                            <?php echo htmlspecialchars($appt['client_name']); ?>
                                            <?php if ($appt['client_phone']): ?>
                                                <br><small style="color: var(--gray-500);"><?php echo htmlspecialchars($appt['client_phone']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($appt['brand'] . ' ' . $appt['model']); ?>
                                            <?php if ($appt['license_plate']): ?>
                                                <br><small style="color: var(--gray-500);"><?php echo htmlspecialchars($appt['license_plate']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars(mb_substr($appt['services_list'] ?? '—', 0, 40)); ?></td>
                                        <td><?php echo $appt['total_duration'] ?? '—'; ?> мин.</td>
                                        <td>
                                            <?php 
                                            $status_labels = [
                                                'pending' => 'Ожидает',
                                                'confirmed' => 'Подтверждена',
                                                'in_progress' => 'В работе',
                                                'completed' => 'Выполнена',
                                                'cancelled' => 'Отменена'
                                            ];
                                            ?>
                                            <span class="status-badge status-<?php echo $appt['status']; ?>">
                                                <?php echo $status_labels[$appt['status']] ?? $appt['status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="no-data">📭 На сегодня записей нет</p>
                <?php endif; ?>
            </div>

            <!-- Отзывы на модерации -->
            <div class="admin-card">
                <h3>💬 Отзывы на модерации (<?php echo $reviews_pending; ?>)</h3>
                
                <?php if (count($pending_reviews) > 0): ?>
                    <?php foreach ($pending_reviews as $review): ?>
                        <div class="review-mini">
                            <div class="review-mini-header">
                                <span class="review-mini-author"><?php echo htmlspecialchars($review['user_name']); ?></span>
                                <span class="review-mini-stars"><?php echo str_repeat('★', $review['rating']) . str_repeat('☆', 5 - $review['rating']); ?></span>
                            </div>
                            <div class="review-mini-text"><?php echo htmlspecialchars(mb_substr($review['text'], 0, 80)); ?>...</div>
                        </div>
                    <?php endforeach; ?>
                    <a href="/admin/reviews.php" style="display: block; margin-top: 12px; color: var(--primary); font-size: 14px; font-weight: 500;">Перейти к модерации →</a>
                <?php else: ?>
                    <p class="no-data">✅ Все отзывы обработаны</p>
                <?php endif; ?>
            </div>

        </main>
    </div>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>