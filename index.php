<?php
// index.php - Главная страница сайта "Автокул СТО"

// Подключаем БД
require_once 'includes/config.php';

// Заголовок страницы
$page_title = 'Автокул СТО — Ремонт и обслуживание автомобилей в Вологде';

// Получаем соединение с БД
$pdo = getDBConnection();

// Загружаем популярные услуги (первые 6 активных) с категориями
$services = $pdo->query("
    SELECT s.name, s.description, s.price, s.duration, s.image, c.name AS category_name 
    FROM services s 
    JOIN categories c ON s.category_id = c.id 
    WHERE s.is_active = 1 
    ORDER BY s.id 
    LIMIT 6
")->fetchAll();

// Подключаем шапку
require_once 'includes/header.php';
?>

<!-- ========== ГЛАВНЫЙ БАННЕР (HERO) ========== -->
<section class="hero">
    <div class="container">
        <div class="hero-inner">
            <div class="hero-content">
                <h1>Профессиональный <span>автосервис</span> в Вологде</h1>
                <p>
                    Ремонт любой сложности, техническое обслуживание и диагностика 
                    автомобилей. Запишитесь онлайн за 1 минуту и получите 
                    качественный сервис по честным ценам.
                </p>
                <div class="hero-buttons">
                    <a href="/appointment.php" class="btn btn-primary">Записаться онлайн</a>
                    <a href="/services.php" class="btn btn-light">Смотреть услуги</a>
                </div>
            </div>
            <div class="hero-image">
                <div class="hero-image-placeholder">
                    <img src="/uploads/avatars/index.jpg" alt="Автокул СТО в Вологде" loading="eager">
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========== ПРЕИМУЩЕСТВА ========== -->
<section class="advantages">
    <div class="container">
        <div class="section-header">
            <h2>Почему выбирают Автокул СТО</h2>
            <p>Собрали сервис, в котором важны прозрачность, скорость и предсказуемый результат для клиента</p>
        </div>
        <div class="advantages-grid">
            <div class="advantage-card">
                <div class="advantage-icon">🧰</div>
                <h3>Профильные мастера</h3>
                <p>Работаем с чёткими регламентами: диагностика, согласование и только потом ремонт</p>
            </div>
            <div class="advantage-card">
                <div class="advantage-icon">🧾</div>
                <h3>Прозрачная смета</h3>
                <p>Фиксируем стоимость до начала работ и объясняем, из чего складывается итоговая цена</p>
            </div>
            <div class="advantage-card">
                <div class="advantage-icon">⏱️</div>
                <h3>Пунктуальные сроки</h3>
                <p>Планируем загрузку постов заранее и держим вас в курсе статуса на каждом этапе</p>
            </div>
            <div class="advantage-card">
                <div class="advantage-icon">🛡️</div>
                <h3>Гарантия и поддержка</h3>
                <p>После обслуживания остаёмся на связи и помогаем по вопросам эксплуатации</p>
            </div>
        </div>
    </div>
</section>

<!-- ========== ПОПУЛЯРНЫЕ УСЛУГИ ========== -->
<section class="services-preview">
    <div class="container">
        <div class="section-header">
            <h2>Наши услуги</h2>
            <p>Актуальные цены и описание самых востребованных услуг</p>
        </div>
        
        <?php if (!empty($services)): ?>
        <div class="services-grid">
            <?php foreach ($services as $service): ?>
            <div class="service-card">
                <div class="service-preview-image">
                    <img src="<?php echo !empty($service['image']) && file_exists(__DIR__ . '/' . $service['image']) ? '/' . htmlspecialchars($service['image']) : '/uploads/avatars/default-service.png'; ?>"
                         alt="<?php echo htmlspecialchars($service['name']); ?>"
                         loading="lazy">
                </div>
                <span class="service-category-badge">
                    <?php echo htmlspecialchars($service['category_name']); ?>
                </span>
                <h3><?php echo htmlspecialchars($service['name']); ?></h3>
                <p><?php echo htmlspecialchars(mb_substr($service['description'], 0, 80)) . '...'; ?></p>
                <div class="service-card-footer">
                    <span class="service-price"><?php echo number_format($service['price'], 0, ',', ' '); ?> ₽</span>
                    <span class="service-duration"><?php echo $service['duration']; ?> мин.</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="text-align: center; margin-top: 30px;">
            <a href="/services.php" class="btn btn-outline">Все услуги</a>
        </div>
        <?php else: ?>
        <p style="text-align: center; color: var(--gray-500);">Услуги пока не добавлены.</p>
        <?php endif; ?>
    </div>
</section>

<!-- ========== КАК ЗАПИСАТЬСЯ ========== -->
<section class="how-to">
    <div class="container">
        <div class="section-header">
            <h2>Как записаться</h2>
            <p>Всего 3 простых шага до визита в наш автосервис</p>
        </div>
        <div class="steps">
            <div class="step-card">
                <div class="step-number">1</div>
                <h3>Выберите услугу</h3>
                <p>Ознакомьтесь с перечнем услуг и выберите нужную из удобного каталога</p>
            </div>
            <div class="step-card">
                <div class="step-number">2</div>
                <h3>Выберите время</h3>
                <p>Укажите удобную дату и свободное время визита в режиме онлайн</p>
            </div>
            <div class="step-card">
                <div class="step-number">3</div>
                <h3>Приезжайте</h3>
                <p>Приезжайте в назначенное время и доверьте авто профессионалам</p>
            </div>
        </div>
    </div>
</section>

<?php
// Подключаем подвал
require_once 'includes/footer.php';
?>
