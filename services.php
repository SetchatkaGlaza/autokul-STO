<?php
// services.php - Каталог услуг с изображениями

require_once 'includes/config.php';
require_once 'includes/auth_check.php';

$page_title = 'Услуги и цены — Автокул СТО';
$pdo = getDBConnection();

// Получаем категории
$categories = $pdo->query("SELECT * FROM categories ORDER BY id")->fetchAll();

// Получаем услуги
$all_services = $pdo->query("
    SELECT s.*, c.name AS category_name, c.id AS category_id
    FROM services s
    JOIN categories c ON s.category_id = c.id
    WHERE s.is_active = 1
    ORDER BY c.id, s.name ASC
")->fetchAll();

// Группируем по категориям
$services_by_category = [];
foreach ($all_services as $svc) {
    $services_by_category[$svc['category_id']][] = $svc;
}

// Фильтрация
$active_category = isset($_GET['category']) ? intval($_GET['category']) : 0;
$search_query = trim($_GET['search'] ?? '');
$sort_by = $_GET['sort'] ?? 'name';

$filtered_services = $all_services;

if ($active_category > 0) {
    $filtered_services = array_filter($filtered_services, function($s) use ($active_category) {
        return $s['category_id'] == $active_category;
    });
}

if (!empty($search_query)) {
    $search_lower = mb_strtolower($search_query);
    $filtered_services = array_filter($filtered_services, function($s) use ($search_lower) {
        return strpos(mb_strtolower($s['name']), $search_lower) !== false 
            || strpos(mb_strtolower($s['description']), $search_lower) !== false;
    });
}

// Сортировка
switch ($sort_by) {
    case 'price_asc':
        usort($filtered_services, function($a, $b) { return $a['price'] <=> $b['price']; });
        break;
    case 'price_desc':
        usort($filtered_services, function($a, $b) { return $b['price'] <=> $a['price']; });
        break;
    case 'duration':
        usort($filtered_services, function($a, $b) { return $a['duration'] <=> $b['duration']; });
        break;
    default:
        usort($filtered_services, function($a, $b) { return strcmp($a['name'], $b['name']); });
}

// Функция получения URL изображения
function getServiceImage($image_path) {
    if (!empty($image_path) && file_exists(__DIR__ . '/' . $image_path)) {
        return '/' . $image_path;
    }
    return '/uploads/avatars/default-service.png';
}

require_once 'includes/header.php';
?>

<style>
    /* ========== ЗАГОЛОВОК СТРАНИЦЫ ========== */
    .page-hero {
        text-align: center;
        padding: 60px 20px 50px;
        background: linear-gradient(135deg, #1a1a1a 0%, #2a2a2a 100%);
        color: #ffffff;
        margin-bottom: 0;
    }

    .page-hero h1 {
        font-size: 38px;
        font-weight: 700;
        margin-bottom: 12px;
        letter-spacing: -0.5px;
    }

    .page-hero h1 span {
        color: #cc3333;
    }

    .page-hero p {
        font-size: 16px;
        color: #aaaaaa;
        max-width: 600px;
        margin: 0 auto;
        line-height: 1.6;
    }

    /* ========== КОНТЕЙНЕР ========== */
    .services-page {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 20px 40px;
    }

    /* ========== ПАНЕЛЬ ФИЛЬТРОВ ========== */
    .filters-panel {
        background: #ffffff;
        border-radius: 12px;
        padding: 20px 24px;
        margin-top: -28px;
        border: 1px solid #e8eaed;
        box-shadow: 0 4px 16px rgba(0,0,0,0.06);
        position: sticky;
        top: 84px;
        z-index: 100;
        margin-bottom: 28px;
    }

    .filters-row {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: flex-end;
    }

    .search-wrapper {
        flex: 1;
        min-width: 220px;
        position: relative;
    }

    .search-wrapper input {
        width: 100%;
        padding: 10px 14px 10px 40px;
        border: 1px solid #d0d4d8;
        border-radius: 8px;
        font-size: 14px;
        outline: none;
        transition: all 0.2s ease;
        background: #fafafa;
    }

    .search-wrapper input:focus {
        border-color: #cc3333;
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(204, 51, 51, 0.06);
    }

    .search-wrapper .search-icon {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #999;
        pointer-events: none;
    }

    .search-wrapper .search-icon svg {
        width: 18px;
        height: 18px;
    }

    .sort-select {
        padding: 10px 34px 10px 14px;
        border: 1px solid #d0d4d8;
        border-radius: 8px;
        font-size: 13px;
        background: #fafafa;
        cursor: pointer;
        outline: none;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23999'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 10px center;
        min-width: 190px;
    }

    .sort-select:focus {
        border-color: #cc3333;
    }

    /* Категории */
    .category-tabs {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        margin-top: 14px;
        padding-top: 14px;
        border-top: 1px solid #f0f0f0;
    }

    .category-tab {
        padding: 7px 16px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.2s ease;
        border: 1.5px solid #e0e0e0;
        background: #ffffff;
        color: #555;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .category-tab:hover {
        border-color: #cc3333;
        color: #cc3333;
        background: #fefafa;
    }

    .category-tab.active {
        background: #cc3333;
        color: #ffffff;
        border-color: #cc3333;
    }

    .category-tab .tab-count {
        font-size: 11px;
        background: rgba(0,0,0,0.08);
        padding: 2px 7px;
        border-radius: 10px;
        font-weight: 600;
    }

    .category-tab.active .tab-count {
        background: rgba(255,255,255,0.2);
    }

    /* ========== СЕТКА УСЛУГ (3 в ряд) ========== */
    .services-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 24px;
    }

    .service-card {
        background: #ffffff;
        border-radius: 12px;
        border: 1px solid #e8eaed;
        overflow: hidden;
        transition: all 0.3s ease;
        display: flex;
        flex-direction: column;
    }

    .service-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 32px rgba(0,0,0,0.1);
        border-color: #d0d0d0;
    }

    /* Изображение услуги */
    .service-image-wrapper {
        position: relative;
        width: 100%;
        height: 240px;
        overflow: hidden;
        background: #f5f5f5;
    }

    .service-image-wrapper img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.5s ease;
    }

    .service-card:hover .service-image-wrapper img {
        transform: scale(1.06);
    }

    .service-category-badge {
        position: absolute;
        top: 14px;
        left: 14px;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: rgba(0,0,0,0.7);
        color: #ffffff;
        backdrop-filter: blur(4px);
    }

    /* Контент карточки */
    .service-card-body {
        padding: 20px 22px 18px;
        flex: 1;
        display: flex;
        flex-direction: column;
    }

    .service-card-body h3 {
        font-size: 17px;
        font-weight: 600;
        color: #1a1a1a;
        margin-bottom: 8px;
        line-height: 1.4;
    }

    .service-card-body .service-desc {
        font-size: 13px;
        color: #888;
        line-height: 1.6;
        margin-bottom: 16px;
        flex: 1;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .service-card-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px 22px;
        border-top: 1px solid #f0f0f0;
        background: #fafafa;
    }

    .service-price {
        font-size: 22px;
        font-weight: 700;
        color: #1a1a1a;
    }

    .service-price span {
        font-size: 14px;
        font-weight: 500;
        color: #888;
    }

    .service-duration {
        font-size: 12px;
        color: #999;
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .btn-service {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 18px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s ease;
        background: #cc3333;
        color: #ffffff;
        border: none;
        cursor: pointer;
    }

    .btn-service:hover {
        background: #b22d2d;
        box-shadow: 0 4px 12px rgba(204, 51, 51, 0.3);
    }

    /* ========== ПУСТОЕ СОСТОЯНИЕ ========== */
    .empty-state {
        grid-column: 1 / -1;
        text-align: center;
        padding: 60px 20px;
        color: #999;
    }

    .empty-state h3 {
        font-size: 18px;
        color: #555;
        margin: 12px 0 6px;
    }

    /* ========== АДАПТИВНОСТЬ ========== */
    @media (max-width: 1100px) {
        .services-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 650px) {
        .services-grid {
            grid-template-columns: 1fr;
        }

        .page-hero h1 {
            font-size: 26px;
        }

        .filters-panel {
            position: static;
            margin-top: -20px;
        }

        .filters-row {
            flex-direction: column;
        }

        .service-image-wrapper {
            height: 200px;
        }
    }

    @media (max-width: 520px) {
        .services-page {
            padding: 0 12px 28px;
        }

        .filters-panel {
            padding: 14px;
        }

        .sort-select,
        .search-wrapper input {
            font-size: 13px;
        }

        .service-card-body {
            padding: 16px;
        }

        .service-card-footer {
            padding: 12px 16px;
        }

        .service-price {
            font-size: 18px;
        }
    }
</style>

<section class="page-hero">
    <div class="container">
        <h1>Услуги <span>и цены</span></h1>
        <p>Полный каталог услуг автосервиса «Автокул СТО». Честные цены, профессиональный подход и гарантия на все виды работ.</p>
    </div>
</section>

<section class="services-page">

    <!-- Фильтры -->
    <div class="filters-panel">
        <form method="GET" action="/services.php" id="filterForm">
            <div class="filters-row">
                <div class="search-wrapper">
                    <span class="search-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0016 9.5 6.5 6.5 0 109.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                        </svg>
                    </span>
                    <input type="text" name="search" placeholder="Поиск услуги по названию или описанию..." 
                           value="<?php echo htmlspecialchars($search_query); ?>" oninput="this.form.submit()">
                </div>
                <select name="sort" class="sort-select" onchange="this.form.submit()">
                    <option value="name" <?php echo $sort_by === 'name' ? 'selected' : ''; ?>>По названию (А-Я)</option>
                    <option value="price_asc" <?php echo $sort_by === 'price_asc' ? 'selected' : ''; ?>>Цена: по возрастанию</option>
                    <option value="price_desc" <?php echo $sort_by === 'price_desc' ? 'selected' : ''; ?>>Цена: по убыванию</option>
                    <option value="duration" <?php echo $sort_by === 'duration' ? 'selected' : ''; ?>>По длительности</option>
                </select>
            </div>
        </form>

        <div class="category-tabs">
            <a href="/services.php<?php echo !empty($search_query) ? '?search=' . urlencode($search_query) : ''; ?>" 
               class="category-tab <?php echo $active_category === 0 ? 'active' : ''; ?>">
                Все услуги
                <span class="tab-count"><?php echo count($all_services); ?></span>
            </a>
            <?php foreach ($categories as $cat): 
                $cat_count = isset($services_by_category[$cat['id']]) ? count($services_by_category[$cat['id']]) : 0;
                if ($cat_count === 0) continue;
                $url = '/services.php?category=' . $cat['id'];
                if (!empty($search_query)) $url .= '&search=' . urlencode($search_query);
            ?>
                <a href="<?php echo $url; ?>" class="category-tab <?php echo $active_category === (int)$cat['id'] ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($cat['name']); ?>
                    <span class="tab-count"><?php echo $cat_count; ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Сетка услуг -->
    <?php if (count($filtered_services) > 0): ?>
        <div class="services-grid">
            <?php foreach ($filtered_services as $svc): 
                $image_url = getServiceImage($svc['image']);
            ?>
                <div class="service-card">
                    <div class="service-image-wrapper">
                        <img src="<?php echo htmlspecialchars($image_url); ?>" 
                             alt="<?php echo htmlspecialchars($svc['name']); ?>"
                             loading="lazy">
                        <span class="service-category-badge"><?php echo htmlspecialchars($svc['category_name']); ?></span>
                    </div>
                    
                    <div class="service-card-body">
                        <h3><?php echo htmlspecialchars($svc['name']); ?></h3>
                        <p class="service-desc"><?php echo htmlspecialchars($svc['description'] ?: 'Подробное описание услуги уточняйте у менеджера.'); ?></p>
                    </div>
                    
                    <div class="service-card-footer">
                        <div>
                            <div class="service-price">
                                <?php echo number_format($svc['price'], 0, ',', ' '); ?> <span>₽</span>
                            </div>
                            <div class="service-duration">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>
                                <?php echo $svc['duration']; ?> мин.
                            </div>
                        </div>
                        <a href="/appointment.php" class="btn-service">Записаться</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <div style="font-size: 48px; margin-bottom: 12px; opacity: 0.4;">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="currentColor"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0016 9.5 6.5 6.5 0 109.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
            </div>
            <h3>Услуги не найдены</h3>
            <p>Измените параметры поиска или выберите другую категорию</p>
        </div>
    <?php endif; ?>

</section>

<?php
require_once 'includes/footer.php';
?>
