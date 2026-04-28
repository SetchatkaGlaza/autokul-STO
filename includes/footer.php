<?php
// footer.php - Общий подвал сайта
$footer_service_names = [
    'Диагностика',
    'Техническое обслуживание',
    'Ремонт двигателя',
    'Ремонт ходовой части',
    'Ремонт трансмиссии',
    'Шиномонтаж',
    'Кузовной ремонт',
    'Автоэлектрика'
];

$footer_categories = [];
try {
    if (!function_exists('getDBConnection')) {
        require_once __DIR__ . '/config.php';
    }
    $pdo_footer = getDBConnection();
    $stmt_footer = $pdo_footer->query("
        SELECT c.id, c.name, COUNT(s.id) AS services_count
        FROM categories c
        LEFT JOIN services s ON s.category_id = c.id AND s.is_active = 1
        GROUP BY c.id, c.name
    ");
    $categories_index = [];
    foreach ($stmt_footer->fetchAll() as $cat) {
        $categories_index[mb_strtolower(trim($cat['name']))] = $cat;
    }
    foreach ($footer_service_names as $name) {
        $key = mb_strtolower(trim($name));
        if (isset($categories_index[$key])) {
            $footer_categories[] = $categories_index[$key];
            continue;
        }

        // Фолбэк по частичному совпадению названия
        foreach ($categories_index as $cat_name => $cat_data) {
            if (mb_stripos($cat_name, $key) !== false || mb_stripos($key, $cat_name) !== false) {
                $footer_categories[] = $cat_data;
                continue 2;
            }
        }
    }
} catch (Exception $e) {
    // Фолбэк без привязки к БД
    foreach ($footer_service_names as $name) {
        $footer_categories[] = ['id' => 0, 'name' => $name, 'services_count' => 0];
    }
}
?>
    </main>

    <!-- ПОДВАЛ -->
    <footer class="site-footer">
        <div class="container">
            <div class="footer-grid">
                
                <!-- О компании -->
                <div class="footer-col">
                    <h3>Автокул СТО</h3>
                    <p style="line-height: 1.8;">
                        Профессиональный автосервис с опытом работы более 10 лет. 
                        Ремонт, диагностика, техническое обслуживание — всё в одном месте.
                    </p>
                </div>
                
                <!-- Услуги -->
                <div class="footer-col">
                    <h3>Услуги</h3>
                    <ul>
                        <?php foreach ($footer_categories as $footer_cat): ?>
                            <li>
                                <a href="/services.php<?php echo !empty($footer_cat['id']) ? '?category=' . (int)$footer_cat['id'] . '&sort=name' : '?sort=name'; ?>">
                                    <?php echo htmlspecialchars($footer_cat['name']); ?>
                                    <?php if ((int)$footer_cat['services_count'] > 0): ?>
                                        <span style="opacity: .7; font-size: 12px;"> <?php echo (int)$footer_cat['services_count']; ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                
                <!-- Навигация -->
                <div class="footer-col">
                    <h3>Навигация</h3>
                    <ul>
                        <li><a href="/index.php">Главная</a></li>
                        <li><a href="/services.php">Услуги и цены</a></li>
                        <li><a href="/appointment.php">Онлайн-запись</a></li>
                        <li><a href="/reviews.php">Отзывы</a></li>
                        <li><a href="/contacts.php">Контакты</a></li>
                    </ul>
                </div>
                
                <!-- Контакты -->
                <div class="footer-col">
                    <h3>Контакты</h3>
                    <ul>
                        <li>📍 г. Вологда, Кирпичная ул., 48А</li>
                        <li>📞 <a href="tel:+79001234567">+7 (900) 123-45-67</a></li>
                        <li>✉️ <a href="mailto:info@autokul.ru">info@autokul.ru</a></li>
                        <li>🕐 Ежедневно: 09:00 – 18:00</li>
                    </ul>
                </div>
                
            </div>
            
            <!-- Копирайт -->
            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> Автокул СТО. Все права защищены.</p>
                <p>Разработано в рамках дипломного проекта</p>
            </div>
        </div>
    </footer>

    <!-- Скрипт для мобильного меню -->
    <script>
        // Мобильное меню
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.getElementById('mainNav').classList.toggle('open');
        });
        
        // Закрытие меню при клике на ссылку
        document.querySelectorAll('#mainNav .header-nav-link').forEach(link => {
            link.addEventListener('click', () => {
                document.getElementById('mainNav').classList.remove('open');
            });
        });
    </script>
</body>
</html>
