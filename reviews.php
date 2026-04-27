<?php
// reviews.php - Отзывы клиентов

require_once 'includes/config.php';
require_once 'includes/auth_check.php';
require_once 'includes/avatar.php';

$page_title = 'Отзывы клиентов — Автокул СТО';
$pdo = getDBConnection();

// Параметры пагинации
$per_page = 6; // Отзывов на странице
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $per_page;

// Фильтр по рейтингу
$rating_filter = isset($_GET['rating']) ? intval($_GET['rating']) : 0;
$sort = $_GET['sort'] ?? 'newest';

// Базовый запрос (только одобренные отзывы)
$where = "WHERE r.is_approved = 1";
$params = [];

if ($rating_filter >= 1 && $rating_filter <= 5) {
    $where .= " AND r.rating = :rating";
    $params['rating'] = $rating_filter;
}

// Сортировка
$order_by = "ORDER BY r.created_at DESC";
switch ($sort) {
    case 'oldest':
        $order_by = "ORDER BY r.created_at ASC";
        break;
    case 'highest':
        $order_by = "ORDER BY r.rating DESC, r.created_at DESC";
        break;
    case 'lowest':
        $order_by = "ORDER BY r.rating ASC, r.created_at DESC";
        break;
    default:
        $order_by = "ORDER BY r.created_at DESC";
}

// Получаем общее количество отзывов
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM reviews r $where");
$count_stmt->execute($params);
$total_reviews = $count_stmt->fetchColumn();
$total_pages = ceil($total_reviews / $per_page);

// Получаем отзывы для текущей страницы
$query = "
    SELECT r.*, u.full_name AS user_name, u.created_at AS user_since,
           a.appointment_date,
           GROUP_CONCAT(DISTINCT s.name SEPARATOR ', ') AS services_list
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN appointments a ON r.appointment_id = a.id
    LEFT JOIN appointment_services aps ON a.id = aps.appointment_id
    LEFT JOIN services s ON aps.service_id = s.id
    $where
    GROUP BY r.id
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
$reviews = $stmt->fetchAll();

// Получаем статистику по рейтингам
$stats_stmt = $pdo->query("
    SELECT 
        COUNT(*) AS total,
        ROUND(AVG(rating), 1) AS avg_rating,
        SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) AS five_stars,
        SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) AS four_stars,
        SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) AS three_stars,
        SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) AS two_stars,
        SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) AS one_star
    FROM reviews 
    WHERE is_approved = 1
");
$stats = $stats_stmt->fetch();

require_once 'includes/header.php';
?>

<style>
    /* ========== ОСНОВНОЙ КОНТЕЙНЕР ========== */
    .reviews-page {
        max-width: var(--max-width);
        margin: 0 auto;
        padding: 0 20px;
    }

    /* ========== ЗАГОЛОВОК СТРАНИЦЫ ========== */
    .page-hero {
        text-align: center;
        padding: 50px 20px 40px;
        background: linear-gradient(135deg, var(--secondary) 0%, #373737 100%);
        color: white;
        margin-bottom: 0;
    }

    .page-hero h1 {
        font-size: 36px;
        font-weight: 800;
        margin-bottom: 10px;
    }

    .page-hero h1 span {
        color: var(--primary);
    }

    .page-hero p {
        font-size: 16px;
        color: #ccc;
        max-width: 600px;
        margin: 0 auto;
    }

    /* ========== СТАТИСТИКА ========== */
    .rating-summary {
        background: var(--white);
        border-radius: 14px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        padding: 28px 30px;
        margin-top: -30px;
        border: 1px solid var(--gray-200);
        margin-bottom: 30px;
        display: flex;
        align-items: center;
        gap: 40px;
        flex-wrap: wrap;
    }

    .rating-big {
        text-align: center;
        flex-shrink: 0;
    }

    .rating-big-number {
        font-size: 56px;
        font-weight: 800;
        color: var(--secondary);
        line-height: 1;
    }

    .rating-big-stars {
        color: #ffc107;
        font-size: 22px;
        letter-spacing: 3px;
        margin: 8px 0;
    }

    .rating-big-count {
        font-size: 14px;
        color: var(--gray-500);
    }

    .rating-breakdown {
        flex: 1;
        min-width: 200px;
    }

    .rating-bar-row {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 6px;
        font-size: 14px;
    }

    .rating-bar-label {
        width: 60px;
        text-align: right;
        color: var(--gray-700);
        font-weight: 500;
    }

    .rating-bar-fill {
        flex: 1;
        height: 10px;
        background: var(--gray-200);
        border-radius: 5px;
        overflow: hidden;
    }

    .rating-bar-fill-inner {
        height: 100%;
        background: #ffc107;
        border-radius: 5px;
        transition: width 0.6s ease;
    }

    .rating-bar-count {
        width: 30px;
        font-size: 13px;
        color: var(--gray-500);
    }

    /* ========== ПАНЕЛЬ СОРТИРОВКИ ========== */
    .controls-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 24px;
    }

    .filter-tabs {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }

    .filter-tab {
        padding: 7px 16px;
        border-radius: 20px;
        border: 2px solid var(--gray-200);
        background: var(--white);
        cursor: pointer;
        font-size: 13px;
        font-weight: 500;
        transition: var(--transition);
        text-decoration: none;
        color: var(--gray-700);
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .filter-tab:hover {
        border-color: var(--primary);
        color: var(--primary);
    }

    .filter-tab.active {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
    }

    .sort-select-inline {
        padding: 8px 32px 8px 12px;
        border: 1px solid var(--gray-300);
        border-radius: 8px;
        font-size: 13px;
        background: var(--white);
        cursor: pointer;
        outline: none;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M6 8L1 3h10z' fill='%239e9e9e'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 10px center;
    }

    /* ========== КАРТОЧКИ ОТЗЫВОВ ========== */
    .reviews-grid {
        display: grid;
        gap: 20px;
        margin-bottom: 30px;
    }

    .review-card {
        background: var(--white);
        border-radius: 14px;
        border: 1px solid var(--gray-200);
        padding: 24px 28px;
        transition: var(--transition);
        position: relative;
    }

    .review-card:hover {
        box-shadow: 0 4px 16px rgba(0,0,0,0.06);
        border-color: var(--gray-300);
    }

    .review-card-header {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        margin-bottom: 14px;
    }

    .review-avatar {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: var(--primary);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        font-weight: 700;
        flex-shrink: 0;
    }

    .review-user-info {
        flex: 1;
    }

    .review-user-info h3 {
        font-size: 16px;
        font-weight: 600;
        color: var(--secondary);
        margin-bottom: 2px;
    }

    .review-user-info .review-date {
        font-size: 13px;
        color: var(--gray-500);
    }

    .review-rating {
        color: #ffc107;
        font-size: 16px;
        letter-spacing: 2px;
        flex-shrink: 0;
    }

    .review-card-body {
        font-size: 15px;
        line-height: 1.7;
        color: var(--gray-700);
        margin-bottom: 12px;
    }

    .review-card-body p {
        margin: 0;
    }

    .review-card-footer {
        display: flex;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
        font-size: 13px;
        color: var(--gray-500);
        padding-top: 12px;
        border-top: 1px solid var(--gray-100);
    }

    .review-service-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        background: var(--gray-100);
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
    }

    .review-verified {
        color: #28a745;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    /* ========== ПАГИНАЦИЯ ========== */
    .pagination {
        display: flex;
        justify-content: center;
        gap: 6px;
        margin-bottom: 50px;
        flex-wrap: wrap;
    }

    .pagination a,
    .pagination span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 40px;
        height: 40px;
        border-radius: 10px;
        font-weight: 500;
        font-size: 14px;
        transition: var(--transition);
        text-decoration: none;
    }

    .pagination a {
        background: var(--white);
        border: 1px solid var(--gray-200);
        color: var(--gray-700);
    }

    .pagination a:hover {
        border-color: var(--primary);
        color: var(--primary);
    }

    .pagination .active {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
    }

    .pagination .disabled {
        opacity: 0.4;
        pointer-events: none;
    }

    /* ========== ОСТАВИТЬ ОТЗЫВ CTA ========== */
    .cta-banner {
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        color: white;
        border-radius: 14px;
        padding: 30px;
        text-align: center;
        margin-bottom: 40px;
    }

    .cta-banner h2 {
        font-size: 22px;
        margin-bottom: 8px;
    }

    .cta-banner p {
        font-size: 15px;
        opacity: 0.9;
        margin-bottom: 16px;
    }

    .btn-light-lg {
        display: inline-block;
        padding: 12px 28px;
        border-radius: 8px;
        font-weight: 600;
        background: white;
        color: var(--primary);
        text-decoration: none;
        transition: var(--transition);
    }

    .btn-light-lg:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }

    /* ========== АДАПТИВНОСТЬ ========== */
    @media (max-width: 768px) {
        .page-hero h1 {
            font-size: 26px;
        }

        .rating-summary {
            flex-direction: column;
            text-align: center;
            padding: 20px;
        }

        .rating-big-number {
            font-size: 44px;
        }

        .controls-bar {
            flex-direction: column;
            align-items: flex-start;
        }

        .review-card {
            padding: 16px;
        }

        .review-card-header {
            flex-wrap: wrap;
        }
    }
</style>

<!-- ========== ЗАГОЛОВОК ========== -->
<section class="page-hero">
    <div class="container">
        <h1>Отзывы <span>наших клиентов</span></h1>
        <p>Реальные отзывы реальных людей о работе автосервиса «Автокул СТО». Дорожим каждым клиентом!</p>
    </div>
</section>

<!-- ========== ОСНОВНОЙ КОНТЕНТ ========== -->
<section class="reviews-page">

    <!-- Статистика -->
    <div class="rating-summary">
        <div class="rating-big">
            <div class="rating-big-number"><?php echo $stats['avg_rating'] ? number_format($stats['avg_rating'], 1) : '0.0'; ?></div>
            <div class="rating-big-stars">
                <?php 
                $avg = round($stats['avg_rating'] ?? 0);
                echo str_repeat('★', $avg) . str_repeat('☆', 5 - $avg);
                ?>
            </div>
            <div class="rating-big-count">На основе <?php echo $stats['total']; ?> отзывов</div>
        </div>

        <div class="rating-breakdown">
            <?php 
            $star_levels = [5 => 'five_stars', 4 => 'four_stars', 3 => 'three_stars', 2 => 'two_stars', 1 => 'one_star'];
            foreach ($star_levels as $star => $key): 
                $count = $stats[$key] ?? 0;
                $percent = $stats['total'] > 0 ? ($count / $stats['total']) * 100 : 0;
            ?>
                <div class="rating-bar-row">
                    <a href="/reviews.php?rating=<?php echo $star; ?>" class="rating-bar-label" style="text-decoration:none; color:inherit;">
                        <?php echo $star; ?> ★
                    </a>
                    <div class="rating-bar-fill">
                        <div class="rating-bar-fill-inner" style="width: <?php echo $percent; ?>%;"></div>
                    </div>
                    <span class="rating-bar-count"><?php echo $count; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Панель управления: фильтры и сортировка -->
    <div class="controls-bar">
        <div class="filter-tabs">
            <a href="/reviews.php<?php echo $sort !== 'newest' ? '?sort=' . $sort : ''; ?>" 
               class="filter-tab <?php echo $rating_filter === 0 ? 'active' : ''; ?>">
                Все отзывы
            </a>
            <?php for ($i = 5; $i >= 1; $i--): 
                $url = '/reviews.php?rating=' . $i;
                if ($sort !== 'newest') $url .= '&sort=' . $sort;
            ?>
                <a href="<?php echo $url; ?>" class="filter-tab <?php echo $rating_filter === $i ? 'active' : ''; ?>">
                    <?php echo $i; ?> ★
                </a>
            <?php endfor; ?>
        </div>

        <select class="sort-select-inline" onchange="location.href = this.value">
            <?php
            $sort_options = [
                'newest' => 'Сначала новые',
                'oldest' => 'Сначала старые',
                'highest' => 'Сначала лучшие',
                'lowest' => 'Сначала худшие'
            ];
            foreach ($sort_options as $value => $label):
                $url = '/reviews.php?sort=' . $value;
                if ($rating_filter > 0) $url .= '&rating=' . $rating_filter;
            ?>
                <option value="<?php echo $url; ?>" <?php echo $sort === $value ? 'selected' : ''; ?>>
                    <?php echo $label; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Список отзывов -->
    <?php if (count($reviews) > 0): ?>
        <div class="reviews-grid">
            <?php foreach ($reviews as $review): 
                $initials = mb_substr($review['user_name'], 0, 1);
                $review_date = date('d.m.Y', strtotime($review['created_at']));
                $has_appointment = !is_null($review['appointment_date']);
            ?>
                <div class="review-card">
                    <div class="review-card-header">
                        <<?php 
// Получаем аватар автора отзыва
$review_avatar = null;
$review_author_name = $review['user_name'];
$stmt_av = $pdo->prepare("SELECT avatar FROM users WHERE id = :uid");
$stmt_av->execute(['uid' => $review['user_id']]);
$review_avatar = $stmt_av->fetchColumn();
?>
<div style="width: 48px; height: 48px; flex-shrink: 0;">
    <img src="<?php echo htmlspecialchars(getAvatarUrl($review_avatar)); ?>" 
         alt="<?php echo htmlspecialchars($review_author_name); ?>"
         style="width: 48px; height: 48px; object-fit: cover; border-radius: 50%;">
</div>
                        <div class="review-user-info">
                            <h3><?php echo htmlspecialchars($review['user_name']); ?></h3>
                            <span class="review-date"><?php echo $review_date; ?></span>
                        </div>
                        <div class="review-rating">
                            <?php echo str_repeat('★', $review['rating']) . str_repeat('☆', 5 - $review['rating']); ?>
                        </div>
                    </div>

                    <div class="review-card-body">
                        <p><?php echo nl2br(htmlspecialchars($review['text'])); ?></p>
                    </div>

                    <div class="review-card-footer">
                        <?php if ($has_appointment): ?>
                            <span class="review-verified">✅ Подтверждённый клиент</span>
                        <?php endif; ?>

                        <?php if (!empty($review['services_list'])): ?>
                            <span class="review-service-badge">
                                🔧 <?php echo htmlspecialchars(mb_substr($review['services_list'], 0, 60)); ?>
                                <?php echo mb_strlen($review['services_list']) > 60 ? '...' : ''; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div style="text-align: center; padding: 50px 20px; background: var(--white); border-radius: 14px; border: 1px solid var(--gray-200); margin-bottom: 30px;">
            <div style="font-size: 60px; margin-bottom: 16px;">💬</div>
            <h3 style="font-size: 20px; color: var(--secondary); margin-bottom: 8px;">Нет отзывов</h3>
            <p style="color: var(--gray-500); margin-bottom: 20px;">
                <?php if ($rating_filter > 0): ?>
                    Отзывов с оценкой <?php echo $rating_filter; ?> ★ пока нет. 
                    <a href="/reviews.php" style="color: var(--primary);">Показать все отзывы</a>
                <?php else: ?>
                    Отзывов пока нет. Станьте первым, кто оставит отзыв о нашей работе!
                <?php endif; ?>
            </p>
            <?php if (isLoggedIn()): ?>
                <a href="/profile.php?tab=reviews" class="btn btn-primary">Оставить отзыв</a>
            <?php else: ?>
                <a href="/login.php" class="btn btn-outline">Войти и оставить отзыв</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Пагинация -->
    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php 
            $build_url = function($p) use ($rating_filter, $sort) {
                $url = '/reviews.php?page=' . $p;
                if ($rating_filter > 0) $url .= '&rating=' . $rating_filter;
                if ($sort !== 'newest') $url .= '&sort=' . $sort;
                return $url;
            };
            ?>

            <?php if ($page > 1): ?>
                <a href="<?php echo $build_url($page - 1); ?>" title="Назад">←</a>
            <?php else: ?>
                <span class="disabled">←</span>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $total_pages; $i++): 
                if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)):
            ?>
                <?php if ($i == $page): ?>
                    <span class="active"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="<?php echo $build_url($i); ?>"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                <span>...</span>
            <?php endif; endfor; ?>

            <?php if ($page < $total_pages): ?>
                <a href="<?php echo $build_url($page + 1); ?>" title="Вперёд">→</a>
            <?php else: ?>
                <span class="disabled">→</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- CTA: Призыв оставить отзыв -->
    <div class="cta-banner">
        <h2>Понравилась наша работа? 😊</h2>
        <p>Расскажите о своём опыте — ваш отзыв поможет нам стать лучше, а другим клиентам — сделать правильный выбор.</p>
        <?php if (isLoggedIn()): ?>
            <a href="/profile.php?tab=reviews" class="btn-light-lg">✍️ Оставить отзыв</a>
        <?php else: ?>
            <a href="/login.php" class="btn-light-lg">Войти и оставить отзыв</a>
        <?php endif; ?>
    </div>

</section>

<script>
// Анимация полосок рейтинга при загрузке
document.addEventListener('DOMContentLoaded', function() {
    const bars = document.querySelectorAll('.rating-bar-fill-inner');
    bars.forEach(bar => {
        const targetWidth = bar.style.width;
        bar.style.width = '0%';
        setTimeout(() => {
            bar.style.width = targetWidth;
        }, 200);
    });
});
</script>

<?php
require_once 'includes/footer.php';
?>