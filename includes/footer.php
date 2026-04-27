<?php
// footer.php - Общий подвал сайта
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
                        <li><a href="/services.php">Диагностика</a></li>
                        <li><a href="/services.php">Техобслуживание</a></li>
                        <li><a href="/services.php">Ремонт ходовой</a></li>
                        <li><a href="/services.php">Шиномонтаж</a></li>
                        <li><a href="/services.php">Кузовной ремонт</a></li>
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
                        <li>📍 г. Тюмень, ул. Автомобильная, 15</li>
                        <li>📞 <a href="tel:+79001234567">+7 (900) 123-45-67</a></li>
                        <li>✉️ <a href="mailto:info@autokul.ru">info@autokul.ru</a></li>
                        <li>🕐 Пн–Пт: 09:00 – 18:00</li>
                        <li>🕐 Сб–Вс: выходной</li>
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
        document.querySelectorAll('#mainNav .nav-link').forEach(link => {
            link.addEventListener('click', () => {
                document.getElementById('mainNav').classList.remove('open');
            });
        });
    </script>
</body>
</html>