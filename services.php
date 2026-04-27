<?php
// services.php - Каталог услуг автосервиса

require_once 'includes/config.php';
require_once 'includes/auth_check.php';

$page_title = 'Услуги и цены — Автокул СТО';
$pdo = getDBConnection();

// Получаем все категории
$categories = $pdo->query("SELECT * FROM categories ORDER BY id")->fetchAll();

// Получаем все активные услуги с категориями
$all_services = $pdo->query("
    SELECT s.*, c.name AS category_name, c.id AS category_id
    FROM services s
    JOIN categories c ON s.category_id = c.id
    WHERE s.is_active = 1
    ORDER BY c.id, s.price ASC
")->fetchAll();

// Группируем услуги по категориям для подсчёта
$services_by_category = [];
foreach ($all_services as $svc) {
    $services_by_category[$svc['category_id']][] = $svc;
}

// Определяем активную категорию (из GET-параметра)
$active_category = isset($_GET['category']) ? intval($_GET['category']) : 0;
$search_query = trim($_GET['search'] ?? '');

// Фильтруем услуги
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

// Сортируем (по цене по умолчанию)
$sort_by = $_GET['sort'] ?? 'price_asc';
switch ($sort_by) {
    case 'price_desc':
        usort($filtered_services, function($a, $b) { return $b['price'] <=> $a['price']; });
        break;
    case 'duration_asc':
        usort($filtered_services, function($a, $b) { return $a['duration'] <=> $b['duration']; });
        break;
    case 'duration_desc':
        usort($filtered_services, function($a, $b) { return $b['duration'] <=> $a['duration']; });
        break;
    case 'name':
        usort($filtered_services, function($a, $b) { return strcmp($a['name'], $b['name']); });
        break;
    default: // price_asc
        usort($filtered_services, function($a, $b) { return $a['price'] <=> $b['price']; });
        break;
}

require_once 'includes/header.php';
?>

<style>
    /* ========== ОСНОВНОЙ КОНТЕЙНЕР ========== */
    .services-page {
        max-width: var(--max-width);
        margin: 0 auto;
        padding: 40px 20px;
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

    /* ========== ПАНЕЛЬ ФИЛЬТРОВ И ПОИСКА ========== */
    .filters-panel {
        background: var(--white);
        border-radius: 14px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        padding: 20px 24px;
        margin-top: -30px;
        position: sticky;
        top: calc(var(--header-height) + 10px);
        z-index: 100;
        border: 1px solid var(--gray-200);
        margin-bottom: 30px;
    }

    .filters-top {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: center;
        margin-bottom: 16px;
    }

    /* Поиск */
    .search-wrapper {
        flex: 1;
        min-width: 220px;
        position: relative;
    }

    .search-wrapper input {
        width: 100%;
        padding: 11px 16px 11px 42px;
        border: 1px solid var(--gray-300);
        border-radius: 10px;
        font-size: 15px;
        outline: none;
        transition: var(--transition);
        background: var(--gray-100);
    }

    .search-wrapper input:focus {
        border-color: var(--primary);
        background: var(--white);
        box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.08);
    }

    .search-wrapper .search-icon {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 17px;
        color: var(--gray-500);
    }

    /* Сортировка */
    .sort-select {
        padding: 11px 36px 11px 14px;
        border: 1px solid var(--gray-300);
        border-radius: 10px;
        font-size: 14px;
        background: var(--gray-100);
        cursor: pointer;
        outline: none;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M6 8L1 3h10z' fill='%239e9e9e'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
        transition: var(--transition);
        min-width: 200px;
    }

    .sort-select:focus {
        border-color: var(--primary);
    }

    /* Категории-фильтры */
    .category-tabs {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .category-tab {
        padding: 8px 18px;
        border-radius: 20px;
        border: 2px solid var(--gray-200);
        background: var(--white);
        cursor: pointer;
        font-size: 13px;
        font-weight: 500;
        transition: var(--transition);
        white-space: nowrap;
        text-decoration: none;
        color: var(--gray-700);
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .category-tab:hover {
        border-color: var(--primary);
        color: var(--primary);
    }

    .category-tab.active {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
    }

    .category-tab .tab-count {
        background: rgba(0,0,0,0.1);
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 11px;
        font-weight: 600;
    }

    .category-tab.active .tab-count {
        background: rgba(255,255,255,0.25);
    }

    /* ========== СЕТКА УСЛУГ ========== */
    .services-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 20px;
    }

    /* Карточка услуги */
    .service-card {
        background: var(--white);
        border-radius: 12px;
        border: 1px solid var(--gray-200);
        overflow: hidden;
        transition: var(--transition);
        display: flex;
        flex-direction: column;
    }

    .service-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 28px rgba(0,0,0,0.1);
        border-color: var(--primary);
    }

    .service-card-header {
        padding: 20px 20px 12px;
        border-bottom: 1px solid var(--gray-100);
    }

    .service-category-label {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-bottom: 10px;
    }

    .cat-diagnostic { background: #e3f2fd; color: #1565c0; }
    .cat-technical { background: #e8f5e9; color: #2e7d32; }
    .cat-engine { background: #fff3e0; color: #e65100; }
    .cat-chassis { background: #fce4ec; color: #c62828; }
    .cat-transmission { background: #f3e5f5; color: #6a1b9a; }
    .cat-tires { background: #e0f7fa; color: #00838f; }
    .cat-body { background: #fff8e1; color: #ff8f00; }
    .cat-electric { background: #efebe9; color: #4e342e; }

    .service-card-header h3 {
        font-size: 17px;
        font-weight: 700;
        color: var(--secondary);
        margin-bottom: 6px;
        line-height: 1.3;
    }

    .service-card-body {
        padding: 14px 20px;
        flex: 1;
    }

    .service-card-body p {
        font-size: 14px;
        color: var(--gray-500);
        line-height: 1.6;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .service-card-footer {
        padding: 16px 20px;
        border-top: 1px solid var(--gray-100);
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--gray-100);
    }

    .service-price {
        font-size: 24px;
        font-weight: 800;
        color: var(--primary);
    }

    .service-price small {
        font-size: 14px;
        font-weight: 400;
        color: var(--gray-500);
    }

    .service-duration {
        font-size: 13px;
        color: var(--gray-500);
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .btn-sm {
        padding: 8px 20px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        transition: var(--transition);
        text-decoration: none;
        display: inline-block;
    }

    .btn-sm-primary {
        background: var(--primary);
        color: white;
        border: none;
    }

    .btn-sm-primary:hover {
        background: var(--primary-dark);
    }

    /* ========== ПУСТОЙ РЕЗУЛЬТАТ ========== */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        grid-column: 1 / -1;
    }

    .empty-state-icon {
        font-size: 60px;
        margin-bottom: 16px;
    }

    .empty-state h3 {
        font-size: 20px;
        color: var(--secondary);
        margin-bottom: 8px;
    }

    .empty-state p {
        color: var(--gray-500);
        margin-bottom: 20px;
    }

    /* ========== СЧЁТЧИК НАЙДЕННЫХ УСЛУГ ========== */
    .results-info {
        font-size: 14px;
        color: var(--gray-500);
        margin-bottom: 16px;
    }

    .results-info strong {
        color: var(--primary);
    }

    /* ========== АДАПТИВНОСТЬ ========== */
    @media (max-width: 768px) {
        .page-hero h1 {
            font-size: 26px;
        }

        .filters-panel {
            position: static;
            margin-top: -20px;
        }

        .filters-top {
            flex-direction: column;
        }

        .search-wrapper {
            width: 100%;
        }

        .sort-select {
            width: 100%;
        }

        .services-grid {
            grid-template-columns: 1fr;
        }

        .service-card-header h3 {
            font-size: 15px;
        }

        .service-price {
            font-size: 20px;
        }
    }
</style>

<!-- ========== ЗАГОЛОВОК ========== -->
<section class="page-hero">
    <div class="container">
        <h1>Услуги <span>и цены</span></h1>
        <p>Полный каталог услуг автосервиса «Автокул СТО». Честные цены, профессиональный подход, гарантия на все виды работ.</p>
    </div>
</section>

<!-- ========== ОСНОВНОЙ КОНТЕНТ ========== -->
<section class="services-page">

    <!-- Панель фильтров и поиска -->
    <div class="filters-panel">
        <!-- Строка поиска и сортировка -->
        <form method="GET" action="/services.php" id="filterForm">
            <div class="filters-top">
                <div class="search-wrapper">
                    <span class="search-icon">🔍</span>
                    <input type="text" name="search" placeholder="Поиск услуги..." 
                           value="<?php echo htmlspecialchars($search_query); ?>"
                           oninput="this.form.submit()">
                </div>
                <select name="sort" class="sort-select" onchange="this.form.submit()">
                    <option value="price_asc" <?php echo $sort_by === 'price_asc' ? 'selected' : ''; ?>>⬆️ Цена: по возрастанию</option>
                    <option value="price_desc" <?php echo $sort_by === 'price_desc' ? 'selected' : ''; ?>>⬇️ Цена: по убыванию</option>
                    <option value="duration_asc" <?php echo $sort_by === 'duration_asc' ? 'selected' : ''; ?>>⏱ Длительность: по возрастанию</option>
                    <option value="duration_desc" <?php echo $sort_by === 'duration_desc' ? 'selected' : ''; ?>>⏱ Длительность: по убыванию</option>
                    <option value="name" <?php echo $sort_by === 'name' ? 'selected' : ''; ?>>🔤 По алфавиту</option>
                </select>
            </div>
        </form>

        <!-- Категории -->
        <div class="category-tabs">
            <a href="/services.php<?php echo !empty($search_query) ? '?search=' . urlencode($search_query) : ''; ?>" 
               class="category-tab <?php echo $active_category === 0 ? 'active' : ''; ?>">
                🏷️ Все услуги
                <span class="tab-count"><?php echo count($all_services); ?></span>
            </a>
            <?php foreach ($categories as $cat): 
                $cat_count = isset($services_by_category[$cat['id']]) ? count($services_by_category[$cat['id']]) : 0;
                if ($cat_count === 0) continue;
                $url = '/services.php?category=' . $cat['id'];
                if (!empty($search_query)) $url .= '&search=' . urlencode($search_query);
                $cat_classes = [
                    1 => 'cat-diagnostic', 2 => 'cat-technical', 3 => 'cat-engine', 
                    4 => 'cat-chassis', 5 => 'cat-transmission', 6 => 'cat-tires', 
                    7 => 'cat-body', 8 => 'cat-electric'
                ];
                $cat_class = $cat_classes[$cat['id']] ?? '';
            ?>
                <a href="<?php echo $url; ?>" class="category-tab <?php echo $active_category === (int)$cat['id'] ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($cat['name']); ?>
                    <span class="tab-count"><?php echo $cat_count; ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Информация о результатах -->
    <?php
    $results_count = count($filtered_services);
    $category_name = '';
    if ($active_category > 0) {
        foreach ($categories as $cat) {
            if ($cat['id'] == $active_category) {
                $category_name = $cat['name'];
                break;
            }
        }
    }
    ?>
    <div class="results-info">
        Найдено услуг: <strong><?php echo $results_count; ?></strong>
        <?php if ($category_name): ?>
            в категории «<?php echo htmlspecialchars($category_name); ?>»
        <?php endif; ?>
        <?php if (!empty($search_query)): ?>
            по запросу «<?php echo htmlspecialchars($search_query); ?>»
        <?php endif; ?>
    </div>

    <!-- Сетка услуг -->
    <?php if ($results_count > 0): ?>
        <div class="services-grid">
            <?php 
            $cat_classes_map = [
                1 => 'cat-diagnostic', 2 => 'cat-technical', 3 => 'cat-engine', 
                4 => 'cat-chassis', 5 => 'cat-transmission', 6 => 'cat-tires', 
                7 => 'cat-body', 8 => 'cat-electric'
            ];
            foreach ($filtered_services as $svc): 
                $badge_class = $cat_classes_map[$svc['category_id']] ?? '';
            ?>
                <div class="service-card">
                    <div class="service-card-header">
                        <span class="service-category-label <?php echo $badge_class; ?>">
                            <?php echo htmlspecialchars($svc['category_name']); ?>
                        </span>
                        <h3><?php echo htmlspecialchars($svc['name']); ?></h3>
                    </div>
                    <div class="service-card-body">
                        <p><?php echo htmlspecialchars($svc['description']); ?></p>
                    </div>
                    <div class="service-card-footer">
                        <div>
                            <div class="service-price">
                                <?php echo number_format($svc['price'], 0, ',', ' '); ?> <small>₽</small>
                            </div>
                            <div class="service-duration">
                                ⏱ <?php echo $svc['duration']; ?> минут
                            </div>
                        </div>
                        <a href="/appointment.php?service=<?php echo $svc['id']; ?>" class="btn-sm btn-sm-primary">
                            Записаться
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon">🔍</div>
            <h3>Услуги не найдены</h3>
            <p>По вашему запросу ничего не найдено. Попробуйте изменить параметры поиска или выбрать другую категорию.</p>
            <a href="/services.php" class="btn btn-outline">Показать все услуги</a>
        </div>
    <?php endif; ?>

</section>

<script>
// Автосабмит формы при изменении поиска (уже через oninput)
// Дополнительно: при клике на категорию сбрасываем поиск если он был
document.querySelectorAll('.category-tab').forEach(tab => {
    tab.addEventListener('click', function(e) {
        const url = new URL(this.href);
        // Оставляем текущий поиск если он есть
        const currentSearch = document.querySelector('input[name="search"]').value;
        if (currentSearch) {
            url.searchParams.set('search', currentSearch);
            this.href = url.toString();
        }
    });
});
</script>

<?php
require_once 'includes/footer.php';
?>