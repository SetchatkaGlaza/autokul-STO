<?php
// admin/reviews.php - Модерация отзывов

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth_check.php';

requireAuth('admin');

$page_title = 'Модерация отзывов — Панель управления';
$pdo = getDBConnection();

function getReviewsStats(PDO $pdo): array {
    $stats = $pdo->query("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN is_approved = 1 THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN is_approved = 0 THEN 1 ELSE 0 END) AS pending,
            ROUND(AVG(CASE WHEN is_approved = 1 THEN rating END), 1) AS avg_rating
        FROM reviews
    ")->fetch();
    return [
        'total' => (int)($stats['total'] ?? 0),
        'approved' => (int)($stats['approved'] ?? 0),
        'pending' => (int)($stats['pending'] ?? 0),
        'avg_rating' => $stats['avg_rating'] !== null ? (float)$stats['avg_rating'] : null
    ];
}

// ============================================================
// AJAX-ОБРАБОТЧИКИ
// ============================================================

// Одобрение/отклонение отзыва
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve', 'reject'])) {
    $review_id = intval($_POST['review_id'] ?? 0);
    $is_approved = $_POST['action'] === 'approve' ? 1 : 0;
    
    try {
        $stmt = $pdo->prepare("UPDATE reviews SET is_approved = :approved WHERE id = :id");
        $stmt->execute(['approved' => $is_approved, 'id' => $review_id]);
        
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true, 
                'is_approved' => (bool)$is_approved,
                'message' => $is_approved ? 'Отзыв одобрен' : 'Отзыв отклонён',
                'stats' => getReviewsStats($pdo)
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        header('Location: /admin/reviews.php?' . http_build_query($_GET));
        exit;
    } catch (PDOException $e) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка базы данных']);
            exit;
        }
    }
}

// Удаление отзыва
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $review_id = intval($_POST['review_id'] ?? 0);
    
    try {
        $stmt = $pdo->prepare("DELETE FROM reviews WHERE id = :id");
        $stmt->execute(['id' => $review_id]);
        
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => 'Отзыв удалён',
                'stats' => getReviewsStats($pdo)
            ]);
            exit;
        }
        
        header('Location: /admin/reviews.php?' . http_build_query($_GET));
        exit;
    } catch (PDOException $e) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка при удалении']);
            exit;
        }
    }
}

// ============================================================
// ФИЛЬТРАЦИЯ И ПАГИНАЦИЯ
// ============================================================

$filter_status = $_GET['status'] ?? 'all';
$filter_rating = isset($_GET['rating']) ? intval($_GET['rating']) : 0;
$search = trim($_GET['search'] ?? '');
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;

// Строим WHERE
$where = [];
$params = [];

switch ($filter_status) {
    case 'approved':
        $where[] = "r.is_approved = 1";
        break;
    case 'pending':
        $where[] = "r.is_approved = 0";
        break;
}

if ($filter_rating >= 1 && $filter_rating <= 5) {
    $where[] = "r.rating = :rating";
    $params['rating'] = $filter_rating;
}

if (!empty($search)) {
    $where[] = "(u.full_name LIKE :search1 OR r.text LIKE :search2)";
    $params['search1'] = '%' . $search . '%';
    $params['search2'] = '%' . $search . '%';
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Подсчёт
$count_sql = "SELECT COUNT(*) FROM reviews r JOIN users u ON r.user_id = u.id $where_clause";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_reviews = $count_stmt->fetchColumn();
$total_pages = ceil($total_reviews / $per_page);
$offset = ($page - 1) * $per_page;

// Статистика
$stats = $pdo->query("
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN is_approved = 1 THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN is_approved = 0 THEN 1 ELSE 0 END) AS pending,
        ROUND(AVG(CASE WHEN is_approved = 1 THEN rating END), 1) AS avg_rating
    FROM reviews
")->fetch();

// Получаем отзывы
$query = "
    SELECT r.*, u.full_name AS user_name, u.email AS user_email,
           a.appointment_date,
           GROUP_CONCAT(DISTINCT s.name ORDER BY s.id SEPARATOR ', ') AS services_list
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN appointments a ON r.appointment_id = a.id
    LEFT JOIN appointment_services aps ON a.id = aps.appointment_id
    LEFT JOIN services s ON aps.service_id = s.id
    $where_clause
    GROUP BY r.id
    ORDER BY r.is_approved ASC, r.created_at DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$reviews = $stmt->fetchAll();

// Функция для URL
function buildUrl($params = []) {
    $get = $_GET;
    foreach ($params as $key => $value) {
        if ($value === null || $value === '' || $value === 0 || $value === '0' || $value === 'all') {
            unset($get[$key]);
        } else {
            $get[$key] = $value;
        }
    }
    $get = array_filter($get, function($v) { return $v !== '' && $v !== 'all'; });
    $url = '/admin/reviews.php';
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

        .sidebar-nav a .nav-badge {
            margin-left: auto;
            background: #d32f2f;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
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
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
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

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #212121;
        }

        .stat-label {
            font-size: 12px;
            color: #9e9e9e;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-top: 2px;
        }

        .stat-card.warning .stat-value { color: #f57f17; }
        .stat-card.success .stat-value { color: #28a745; }
        .stat-card.primary .stat-value { color: #d32f2f; }

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

        .status-tab:hover { border-color: #d32f2f; color: #d32f2f; }
        .status-tab.active { background: #d32f2f; color: white; border-color: #d32f2f; }
        .status-tab .count {
            background: rgba(0,0,0,0.1);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
        }
        .status-tab.active .count { background: rgba(255,255,255,0.25); }

        /* Список отзывов */
        .reviews-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
            margin-bottom: 20px;
        }

        .review-card-admin {
            background: white;
            border-radius: 12px;
            border: 1px solid #e0e0e0;
            padding: 20px 24px;
            transition: all 0.3s ease;
            position: relative;
        }

        .review-card-admin:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.06);
        }

        .review-card-admin.pending {
            border-left: 4px solid #ffc107;
            background: #fffdf5;
        }

        .review-card-admin.approved {
            border-left: 4px solid #28a745;
        }

        .review-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .review-author {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .review-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #d32f2f;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            flex-shrink: 0;
        }

        .review-author-info h3 {
            font-size: 15px;
            font-weight: 600;
            color: #212121;
            margin-bottom: 2px;
        }

        .review-author-info span {
            font-size: 12px;
            color: #9e9e9e;
        }

        .review-rating {
            font-size: 18px;
            color: #ffc107;
            letter-spacing: 2px;
        }

        .review-text {
            font-size: 14px;
            line-height: 1.7;
            color: #424242;
            margin: 10px 0;
        }

        .review-meta {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            font-size: 12px;
            color: #9e9e9e;
            padding-top: 10px;
            border-top: 1px solid #f5f5f5;
        }

        .review-service-tag {
            background: #e3f2fd;
            color: #1565c0;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }

        .review-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-approve { background: #28a745; color: white; }
        .btn-approve:hover { background: #218838; }
        .btn-reject { background: #ffc107; color: #333; }
        .btn-reject:hover { background: #e0a800; }
        .btn-delete { background: #dc3545; color: white; }
        .btn-delete:hover { background: #c82333; }

        .status-badge-mini {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-approved { background: #d4edda; color: #155724; }
        .badge-pending { background: #fff3cd; color: #856404; }

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

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #9e9e9e;
            background: white;
            border-radius: 12px;
        }

        @keyframes fadeOut {
            to { opacity: 0; transform: translateX(20px); }
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
            .review-card-admin { padding: 14px; }
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
                <a href="/admin/reviews.php" class="active">
                    ⭐ Отзывы
                    <span class="nav-badge" id="pendingNavBadge" style="<?php echo $stats['pending'] > 0 ? '' : 'display:none;'; ?>">
                        <?php echo $stats['pending']; ?>
                    </span>
                </a>
                <a href="/admin/users.php">👥 Клиенты</a>
                <hr class="sidebar-divider">
                <a href="/index.php">🏠 На сайт</a>
                <a href="/logout.php">🚪 Выйти</a>
            </nav>
        </aside>

        <main class="admin-content">
            
            <div class="admin-header">
                <h1>⭐ Модерация отзывов</h1>
            </div>

            <!-- Статистика -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-value" id="statTotal"><?php echo $stats['total']; ?></div>
                    <div class="stat-label">Всего отзывов</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-value" id="statPending"><?php echo $stats['pending']; ?></div>
                    <div class="stat-label">На модерации</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-value" id="statApproved"><?php echo $stats['approved']; ?></div>
                    <div class="stat-label">Одобрено</div>
                </div>
                <div class="stat-card primary">
                    <div class="stat-value" id="statAvgRating"><?php echo $stats['avg_rating'] ? number_format($stats['avg_rating'], 1) : '—'; ?></div>
                    <div class="stat-label">Средний рейтинг</div>
                </div>
            </div>

            <!-- Вкладки -->
            <div class="status-tabs">
                <a href="<?php echo buildUrl(['status' => 'all']); ?>" class="status-tab <?php echo $filter_status === 'all' ? 'active' : ''; ?>">
                    Все <span class="count" id="tabCountAll"><?php echo $stats['total']; ?></span>
                </a>
                <a href="<?php echo buildUrl(['status' => 'pending']); ?>" class="status-tab <?php echo $filter_status === 'pending' ? 'active' : ''; ?>">
                    ⏳ На модерации <span class="count" id="tabCountPending"><?php echo $stats['pending']; ?></span>
                </a>
                <a href="<?php echo buildUrl(['status' => 'approved']); ?>" class="status-tab <?php echo $filter_status === 'approved' ? 'active' : ''; ?>">
                    ✅ Одобрены <span class="count" id="tabCountApproved"><?php echo $stats['approved']; ?></span>
                </a>
            </div>

            <!-- Фильтры -->
            <form method="GET" action="/admin/reviews.php" class="filters-bar">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
                <div class="filter-group" style="flex:1; min-width:180px;">
                    <label>🔍 Поиск</label>
                    <input type="text" name="search" placeholder="Имя клиента или текст отзыва..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-group" style="min-width:120px;">
                    <label>⭐ Рейтинг</label>
                    <select name="rating">
                        <option value="0">Любой</option>
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                            <option value="<?php echo $i; ?>" <?php echo $filter_rating === $i ? 'selected' : ''; ?>>
                                <?php echo $i; ?> звёзд
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filter-group" style="align-self:flex-end;">
                    <button type="submit" class="btn btn-sm" style="background:#d32f2f; color:white;">Применить</button>
                    <a href="/admin/reviews.php" class="btn btn-sm" style="border:1px solid #d32f2f; color:#d32f2f;">Сбросить</a>
                </div>
            </form>

            <!-- Список отзывов -->
            <?php if (count($reviews) > 0): ?>
                <div class="reviews-list">
                    <?php foreach ($reviews as $review): 
                        $is_pending = !$review['is_approved'];
                        $card_class = $is_pending ? 'pending' : 'approved';
                    ?>
                        <div class="review-card-admin <?php echo $card_class; ?>" id="review-<?php echo $review['id']; ?>">
                            
                            <div class="review-card-header">
                                <div class="review-author">
                                    <div class="review-avatar">
                                        <?php echo mb_substr($review['user_name'], 0, 1); ?>
                                    </div>
                                    <div class="review-author-info">
                                        <h3><?php echo htmlspecialchars($review['user_name']); ?></h3>
                                        <span>
                                            <?php echo date('d.m.Y H:i', strtotime($review['created_at'])); ?>
                                            <?php if ($review['user_email']): ?>
                                                · <?php echo htmlspecialchars($review['user_email']); ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <span class="status-badge-mini <?php echo $is_pending ? 'badge-pending' : 'badge-approved'; ?>" 
                                          id="status-badge-<?php echo $review['id']; ?>">
                                        <?php echo $is_pending ? '⏳ На модерации' : '✅ Одобрен'; ?>
                                    </span>
                                    <span class="review-rating">
                                        <?php echo str_repeat('★', $review['rating']) . str_repeat('☆', 5 - $review['rating']); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="review-text">
                                <?php echo nl2br(htmlspecialchars($review['text'])); ?>
                            </div>

                            <div class="review-meta">
                                <?php if ($review['appointment_date']): ?>
                                    <span>📅 Визит: <?php echo date('d.m.Y', strtotime($review['appointment_date'])); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($review['services_list'])): ?>
                                    <span class="review-service-tag">
                                        🔧 <?php echo htmlspecialchars(mb_substr($review['services_list'], 0, 50)); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="review-actions" style="margin-top: 12px;" id="actions-<?php echo $review['id']; ?>">
                                <?php if ($is_pending): ?>
                                    <button class="btn btn-sm btn-approve" onclick="moderateReview(<?php echo $review['id']; ?>, 'approve')">
                                        ✅ Одобрить
                                    </button>
                                    <button class="btn btn-sm btn-reject" onclick="moderateReview(<?php echo $review['id']; ?>, 'reject')">
                                        ⏳ Отклонить
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-reject" onclick="moderateReview(<?php echo $review['id']; ?>, 'reject')">
                                        ↩️ Вернуть на модерацию
                                    </button>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-delete" onclick="deleteReview(<?php echo $review['id']; ?>)">
                                    🗑️ Удалить
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
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
                    <div style="font-size: 48px; margin-bottom: 12px;">⭐</div>
                    <p>Отзывов не найдено</p>
                    <small>Измените параметры фильтрации</small>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>

    <script>
    function refreshReviewCounters(stats) {
        if (!stats) return;
        const setText = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        };

        setText('statTotal', stats.total);
        setText('statPending', stats.pending);
        setText('statApproved', stats.approved);
        setText('tabCountAll', stats.total);
        setText('tabCountPending', stats.pending);
        setText('tabCountApproved', stats.approved);
        setText('statAvgRating', stats.avg_rating !== null ? Number(stats.avg_rating).toFixed(1) : '—');

        const pendingBadge = document.getElementById('pendingNavBadge');
        if (pendingBadge) {
            pendingBadge.textContent = stats.pending;
            pendingBadge.style.display = stats.pending > 0 ? 'inline-flex' : 'none';
        }
    }

    // ========== МОДЕРАЦИЯ (одобрить/отклонить) ==========
    async function moderateReview(reviewId, action) {
        const actionLabels = {
            'approve': 'одобрить',
            'reject': 'отклонить'
        };
        
        if (!confirm(`Вы уверены, что хотите ${actionLabels[action]} этот отзыв?`)) {
            return;
        }

        const formData = new FormData();
        formData.append('action', action);
        formData.append('review_id', reviewId);

        try {
            const response = await fetch('/admin/reviews.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            const data = await response.json();

            if (data.success) {
                // Обновляем бейдж статуса
                const badge = document.getElementById('status-badge-' + reviewId);
                const card = document.getElementById('review-' + reviewId);
                const actions = document.getElementById('actions-' + reviewId);

                if (data.is_approved) {
                    badge.className = 'status-badge-mini badge-approved';
                    badge.textContent = '✅ Одобрен';
                    card.classList.remove('pending');
                    card.classList.add('approved');
                    actions.innerHTML = `
                        <button class="btn btn-sm btn-reject" onclick="moderateReview(${reviewId}, 'reject')">
                            ↩️ Вернуть на модерацию
                        </button>
                        <button class="btn btn-sm btn-delete" onclick="deleteReview(${reviewId})">
                            🗑️ Удалить
                        </button>
                    `;
                } else {
                    badge.className = 'status-badge-mini badge-pending';
                    badge.textContent = '⏳ На модерации';
                    card.classList.add('pending');
                    card.classList.remove('approved');
                    actions.innerHTML = `
                        <button class="btn btn-sm btn-approve" onclick="moderateReview(${reviewId}, 'approve')">
                            ✅ Одобрить
                        </button>
                        <button class="btn btn-sm btn-reject" onclick="moderateReview(${reviewId}, 'reject')">
                            ⏳ Отклонить
                        </button>
                        <button class="btn btn-sm btn-delete" onclick="deleteReview(${reviewId})">
                            🗑️ Удалить
                        </button>
                    `;
                }

                // Подсветка
                card.style.background = '#e8f5e9';
                setTimeout(() => { card.style.background = ''; }, 1500);
                refreshReviewCounters(data.stats);

            } else {
                alert(data.error || 'Ошибка при обновлении');
            }
        } catch (err) {
            console.error('Ошибка:', err);
            alert('Ошибка соединения с сервером');
        }
    }

    // ========== УДАЛЕНИЕ ==========
    async function deleteReview(reviewId) {
        if (!confirm('Вы уверены, что хотите безвозвратно удалить этот отзыв?')) {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('review_id', reviewId);

        try {
            const response = await fetch('/admin/reviews.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            const data = await response.json();

            if (data.success) {
                const card = document.getElementById('review-' + reviewId);
                card.style.animation = 'fadeOut 0.3s ease forwards';
                setTimeout(() => card.remove(), 300);
                refreshReviewCounters(data.stats);
            } else {
                alert(data.error || 'Ошибка при удалении');
            }
        } catch (err) {
            console.error('Ошибка:', err);
            alert('Ошибка соединения с сервером');
        }
    }
    </script>
</body>
</html>
