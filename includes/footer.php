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
                        <li><a href="/services.php?search=%D0%94%D0%B8%D0%B0%D0%B3%D0%BD%D0%BE%D1%81%D1%82%D0%B8%D0%BA%D0%B0&sort=name">Диагностика</a></li>
                        <li><a href="/services.php?search=%D0%A2%D0%B5%D1%85%D0%BE%D0%B1%D1%81%D0%BB%D1%83%D0%B6%D0%B8%D0%B2%D0%B0%D0%BD%D0%B8%D0%B5&sort=name">Техобслуживание</a></li>
                        <li><a href="/services.php?search=%D0%A0%D0%B5%D0%BC%D0%BE%D0%BD%D1%82+%D1%85%D0%BE%D0%B4%D0%BE%D0%B2%D0%BE%D0%B9&sort=name">Ремонт ходовой</a></li>
                        <li><a href="/services.php?search=%D0%A8%D0%B8%D0%BD%D0%BE%D0%BC%D0%BE%D0%BD%D1%82%D0%B0%D0%B6&sort=name">Шиномонтаж</a></li>
                        <li><a href="/services.php?search=%D0%9A%D1%83%D0%B7%D0%BE%D0%B2%D0%BD%D0%BE%D0%B9+%D1%80%D0%B5%D0%BC%D0%BE%D0%BD%D1%82&sort=name">Кузовной ремонт</a></li>
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
        document.querySelectorAll('#mainNav .nav-link').forEach(link => {
            link.addEventListener('click', () => {
                document.getElementById('mainNav').classList.remove('open');
            });
        });
    </script>
</body>
</html>