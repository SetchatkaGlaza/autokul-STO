<?php
// index.php - Главная страница сайта "Автокул СТО"

// Подключаем БД
require_once 'includes/config.php';

// Заголовок страницы
$page_title = 'Автокул СТО — Ремонт и обслуживание автомобилей в Тюмени';

// Получаем соединение с БД
$pdo = getDBConnection();

// Загружаем популярные услуги (первые 6 активных) с категориями
$services = $pdo->query("
    SELECT s.name, s.description, s.price, s.duration, c.name AS category_name 
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
                <h1>Профессиональный <span>автосервис</span> в Тюмени</h1>
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
                    🚗
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========== ПРЕИМУЩЕСТВА ========== -->
<section class="advantages">
    <div class="container">
        <div class="section-header">
            <h2>Почему выбирают нас</h2>
            <p>Более 10 лет мы делаем автомобили наших клиентов надёжными и безопасными</p>
        </div>
        <div class="advantages-grid">
            <div class="advantage-card">
                <div class="advantage-icon">🔧</div>
                <h3>Опытные мастера</h3>
                <p>Квалифицированные специалисты с опытом работы от 5 лет</p>
            </div>
            <div class="advantage-card">
                <div class="advantage-icon">✅</div>
                <h3>Гарантия качества</h3>
                <p>Гарантия на все виды работ и установленные запчасти</p>
            </div>
            <div class="advantage-card">
                <div class="advantage-icon">💰</div>
                <h3>Честные цены</h3>
                <p>Фиксированная стоимость, без скрытых наценок и доплат</p>
            </div>
            <div class="advantage-card">
                <div class="advantage-icon">📅</div>
                <h3>Удобная запись</h3>
                <p>Онлайн-запись 24/7, без звонков и ожидания на линии</p>
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

<!-- ========== КОНТАКТЫ (кратко) ========== -->
<section class="contact-brief">
    <div class="container">
        <div class="section-header">
            <h2>Наши контакты</h2>
            <p style="color: #aaa;">Ждём вас по будням с 09:00 до 18:00</p>
        </div>
        <div class="contact-info-grid">
            <div class="contact-info-item">
                <div class="contact-info-icon">📍</div>
                <h3>Адрес</h3>
                <p>г. Тюмень, ул. Автомобильная, 15</p>
            </div>
            <div class="contact-info-item">
                <div class="contact-info-icon">📞</div>
                <h3>Телефон</h3>
                <p><a href="tel:+79001234567">+7 (900) 123-45-67</a></p>
            </div>
            <div class="contact-info-item">
                <div class="contact-info-icon">✉️</div>
                <h3>Email</h3>
                <p><a href="mailto:info@autokul.ru">info@autokul.ru</a></p>
            </div>
            <div class="contact-info-item">
                <div class="contact-info-icon">🕐</div>
                <h3>Режим работы</h3>
                <p>Пн–Пт: 09:00 – 18:00</p>
            </div>
        </div>
    </div>
</section>

<?php
// Подключаем подвал
require_once 'includes/footer.php';
?>