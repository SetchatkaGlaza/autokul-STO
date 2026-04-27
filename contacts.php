<?php
// contacts.php - Контакты автосервиса

require_once 'includes/config.php';
require_once 'includes/auth_check.php';

$page_title = 'Контакты — Автокул СТО';
$pdo = getDBConnection();

// Получаем график работы из БД
$schedule = $pdo->query("
    SELECT * FROM work_schedule 
    ORDER BY day_of_week
")->fetchAll();

// Дни недели на русском
$days_of_week = [
    1 => 'Понедельник',
    2 => 'Вторник',
    3 => 'Среда',
    4 => 'Четверг',
    5 => 'Пятница',
    6 => 'Суббота',
    7 => 'Воскресенье'
];

// Формируем рабочие дни (СТО работает ежедневно)
$work_days = [];
$schedule_by_day = [];

foreach ($schedule as $day) {
    $schedule_by_day[(int)$day['day_of_week']] = $day;
}

for ($day_num = 1; $day_num <= 7; $day_num++) {
    $day_data = $schedule_by_day[$day_num] ?? [
        'start_time' => '09:00:00',
        'end_time' => '18:00:00'
    ];

    $day_name = $days_of_week[$day_num] ?? 'День ' . $day_num;
    $time_str = date('H:i', strtotime($day_data['start_time'])) . ' – ' . date('H:i', strtotime($day_data['end_time']));

    $work_days[] = [
        'name' => $day_name,
        'time' => $time_str,
        'is_today' => date('N') == $day_num
    ];
}

// Обработка формы обратной связи
$form_success = false;
$form_error = '';
$form_data = [
    'name' => '',
    'email' => '',
    'phone' => '',
    'message' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_contact') {
    
    $form_data['name'] = trim($_POST['name'] ?? '');
    $form_data['email'] = trim($_POST['email'] ?? '');
    $form_data['phone'] = trim($_POST['phone'] ?? '');
    $form_data['message'] = trim($_POST['message'] ?? '');
    
    // Валидация
    $errors = [];
    
    if (empty($form_data['name'])) {
        $errors[] = 'Укажите ваше имя';
    } elseif (mb_strlen($form_data['name']) < 2) {
        $errors[] = 'Имя должно содержать минимум 2 символа';
    }
    
    if (empty($form_data['email']) && empty($form_data['phone'])) {
        $errors[] = 'Укажите email или телефон для связи';
    }
    
    if (!empty($form_data['email']) && !filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Введите корректный email';
    }
    
    if (empty($form_data['message'])) {
        $errors[] = 'Напишите ваше сообщение';
    } elseif (mb_strlen($form_data['message']) < 10) {
        $errors[] = 'Сообщение должно содержать минимум 10 символов';
    }
    
    // Простая защита от спама (скрытое поле)
    if (!empty($_POST['honeypot'])) {
        $errors[] = 'Обнаружен спам';
    }
    
    if (empty($errors)) {
        // В реальном проекте здесь отправка на email
        // mail('info@autokul.ru', 'Обратная связь с сайта', ...);
        
        // Имитируем успешную отправку
        $form_success = true;
        $form_data = ['name' => '', 'email' => '', 'phone' => '', 'message' => ''];
    } else {
        $form_error = implode('<br>', $errors);
    }
}

require_once 'includes/header.php';
?>

<style>
    /* ========== ОСНОВНОЙ КОНТЕЙНЕР ========== */
    .contacts-page {
        max-width: var(--max-width);
        margin: 0 auto;
        padding: 0 20px;
    }

    /* ========== ЗАГОЛОВОК ========== */
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

    /* ========== КОНТЕНТ В 2 КОЛОНКИ ========== */
    .contacts-content {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
        margin-top: -20px;
        margin-bottom: 40px;
    }

    .contacts-left {
        display: flex;
        flex-direction: column;
        gap: 24px;
    }

    /* ========== КАРТОЧКИ ========== */
    .info-card {
        background: var(--white);
        border-radius: 14px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        border: 1px solid var(--gray-200);
        padding: 28px 30px;
        transition: var(--transition);
    }

    .info-card:hover {
        box-shadow: 0 6px 24px rgba(0,0,0,0.12);
    }

    .info-card h2 {
        font-size: 20px;
        font-weight: 700;
        color: var(--secondary);
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        padding-bottom: 14px;
        border-bottom: 2px solid var(--gray-100);
    }

    .info-card h2 .icon-circle {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        flex-shrink: 0;
    }

    .icon-red { background: var(--primary-light); }
    .icon-blue { background: #e3f2fd; }
    .icon-green { background: #e8f5e9; }

    /* ========== КОНТАКТНЫЕ ДАННЫЕ ========== */
    .contact-info-list {
        display: flex;
        flex-direction: column;
        gap: 18px;
    }

    .contact-info-item {
        display: flex;
        align-items: flex-start;
        gap: 14px;
    }

    .contact-icon-box {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        flex-shrink: 0;
        background: var(--gray-100);
    }

    .contact-info-text h3 {
        font-size: 14px;
        font-weight: 600;
        color: var(--secondary);
        margin-bottom: 2px;
    }

    .contact-info-text p,
    .contact-info-text a {
        font-size: 15px;
        color: var(--gray-700);
        text-decoration: none;
        transition: var(--transition);
    }

    .contact-info-text a:hover {
        color: var(--primary);
    }

    /* ========== ГРАФИК РАБОТЫ ========== */
    .schedule-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .schedule-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 16px;
        border-radius: 10px;
        background: var(--gray-100);
        transition: var(--transition);
    }

    .schedule-row:hover {
        background: var(--gray-200);
    }

    .schedule-row.today {
        background: var(--primary-light);
        border: 1px solid var(--primary);
        font-weight: 600;
    }

    .schedule-row.today .schedule-day {
        color: var(--primary);
    }

    .schedule-day {
        font-size: 15px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .today-badge {
        background: var(--primary);
        color: white;
        font-size: 10px;
        padding: 2px 8px;
        border-radius: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .schedule-time {
        font-size: 15px;
        color: var(--gray-700);
    }

    /* ========== КАРТА ========== */
    .map-container {
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        border: 1px solid var(--gray-200);
        height: 100%;
        min-height: 400px;
        position: relative;
        background: var(--gray-100);
    }

    .map-placeholder {
        width: 100%;
        height: 100%;
        min-height: 400px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #e8eaed 0%, #dadce0 100%);
        position: relative;
    }

    .map-marker {
        width: 50px;
        height: 50px;
        background: var(--primary);
        border-radius: 50% 50% 50% 0;
        transform: rotate(-45deg);
        margin-bottom: 20px;
        position: relative;
        animation: bounce 2s infinite;
    }

    .map-marker::after {
        content: '';
        position: absolute;
        width: 16px;
        height: 16px;
        background: white;
        border-radius: 50%;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) rotate(45deg);
    }

    @keyframes bounce {
        0%, 100% { transform: rotate(-45deg) translateY(0); }
        50% { transform: rotate(-45deg) translateY(-10px); }
    }

    .map-text {
        font-size: 16px;
        color: var(--gray-700);
        text-align: center;
        margin-top: 10px;
    }

    .map-text strong {
        color: var(--secondary);
    }

    .map-link {
        display: inline-block;
        margin-top: 14px;
        padding: 10px 20px;
        background: var(--primary);
        color: white;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 600;
        font-size: 14px;
        transition: var(--transition);
    }

    .map-link:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(211, 47, 47, 0.3);
    }

    /* ========== ФОРМА ОБРАТНОЙ СВЯЗИ ========== */
    .contact-form {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .form-group label {
        font-size: 14px;
        font-weight: 600;
        color: var(--secondary);
    }

    .form-group label .required {
        color: var(--primary);
    }

    .form-group input,
    .form-group textarea {
        width: 100%;
        padding: 12px 14px;
        border: 1px solid var(--gray-300);
        border-radius: 10px;
        font-size: 15px;
        font-family: inherit;
        outline: none;
        transition: var(--transition);
        background: var(--gray-100);
    }

    .form-group input:focus,
    .form-group textarea:focus {
        border-color: var(--primary);
        background: var(--white);
        box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.08);
    }

    .form-group textarea {
        resize: vertical;
        min-height: 120px;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
    }

    .form-submit {
        padding: 14px 28px;
        background: var(--primary);
        color: white;
        border: none;
        border-radius: 10px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        align-self: flex-start;
    }

    .form-submit:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(211, 47, 47, 0.3);
    }

    .form-submit:active {
        transform: translateY(0);
    }

    /* Скрытое поле для защиты от спама */
    .honeypot {
        position: absolute;
        left: -9999px;
        opacity: 0;
    }

    /* Алерты */
    .alert {
        padding: 14px 18px;
        border-radius: 10px;
        font-size: 14px;
        margin-bottom: 16px;
    }

    .alert-success {
        background: #d4edda;
        color: #155724;
        border-left: 4px solid #28a745;
    }

    .alert-error {
        background: #f8d7da;
        color: #721c24;
        border-left: 4px solid #dc3545;
    }

    /* ========== СХЕМА ПРОЕЗДА (ТЕКСТ) ========== */
    .directions-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .direction-item {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 12px 14px;
        background: var(--gray-100);
        border-radius: 10px;
        font-size: 14px;
        color: var(--gray-700);
    }

    .direction-icon {
        font-size: 22px;
        flex-shrink: 0;
        margin-top: 2px;
    }

    /* ========== АДАПТИВНОСТЬ ========== */
    @media (max-width: 900px) {
        .contacts-content {
            grid-template-columns: 1fr;
        }

        .map-container {
            min-height: 300px;
            order: -1;
        }

        .map-placeholder {
            min-height: 300px;
        }

        .form-row {
            grid-template-columns: 1fr;
        }

        .page-hero h1 {
            font-size: 26px;
        }

        .info-card {
            padding: 20px 18px;
        }
    }
</style>

<!-- ========== ЗАГОЛОВОК ========== -->
<section class="page-hero">
    <div class="container">
        <h1>Контакты <span>автосервиса</span></h1>
        <p>Ждём вас по адресу: г. Вологда, Кирпичная ул., 48А. Работаем в удобное для вас время по предварительной записи.</p>
    </div>
</section>

<!-- ========== ОСНОВНОЙ КОНТЕНТ ========== -->
<section class="contacts-page">

    <div class="contacts-content">

        <!-- ЛЕВАЯ КОЛОНКА -->
        <div class="contacts-left">

            <!-- Контактная информация -->
            <div class="info-card">
                <h2>
                    <span class="icon-circle icon-red">📞</span> 
                    Свяжитесь с нами
                </h2>

                <div class="contact-info-list">
                    <div class="contact-info-item">
                        <div class="contact-icon-box">📞</div>
                        <div class="contact-info-text">
                            <h3>Телефон</h3>
                            <a href="tel:+79001234567">+7 (900) 123-45-67</a>
                            <p style="font-size: 12px; color: var(--gray-500);">Ежедневно: с 09:00 до 18:00</p>
                        </div>
                    </div>

                    <div class="contact-info-item">
                        <div class="contact-icon-box">✉️</div>
                        <div class="contact-info-text">
                            <h3>Email</h3>
                            <a href="mailto:info@autokul.ru">info@autokul.ru</a>
                            <p style="font-size: 12px; color: var(--gray-500);">Отвечаем ежедневно в рабочее время</p>
                        </div>
                    </div>

                    <div class="contact-info-item">
                        <div class="contact-icon-box">📍</div>
                        <div class="contact-info-text">
                            <h3>Адрес</h3>
                            <p>г. Вологда, Кирпичная ул., 48А</p>
                            <p style="font-size: 12px; color: var(--gray-500);">Ориентир: рядом с ТЦ "Автомир"</p>
                        </div>
                    </div>

                    <div class="contact-info-item">
                        <div class="contact-icon-box">💬</div>
                        <div class="contact-info-text">
                            <h3>Мессенджеры</h3>
                            <p>
                                <a href="https://wa.me/79001234567" target="_blank" rel="noopener">WhatsApp</a> · 
                                <a href="https://t.me/autokul" target="_blank" rel="noopener">Telegram</a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Режим работы -->
            <div class="info-card">
                <h2>
                    <span class="icon-circle icon-blue">🕐</span> 
                    Режим работы
                </h2>

                <div class="schedule-list">
                    <?php foreach ($work_days as $day): ?>
                        <div class="schedule-row <?php echo $day['is_today'] ? 'today' : ''; ?>">
                            <span class="schedule-day">
                                <?php echo htmlspecialchars($day['name']); ?>
                                <?php if ($day['is_today']): ?>
                                    <span class="today-badge">Сегодня</span>
                                <?php endif; ?>
                            </span>
                            <span class="schedule-time"><?php echo $day['time']; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Схема проезда -->
            <div class="info-card">
                <h2>
                    <span class="icon-circle icon-green">🚗</span> 
                    Как добраться
                </h2>

                <div class="directions-list">
                    <div class="direction-item">
                        <span class="direction-icon">🚌</span>
                        <span>Городские маршруты до остановки «Кирпичная улица», далее 2–3 минуты пешком</span>
                    </div>
                    <div class="direction-item">
                        <span class="direction-icon">🚇</span>
                        <span>На автомобиле удобно подъехать по Кирпичной улице, ориентир — дом 48А</span>
                    </div>
                    <div class="direction-item">
                        <span class="direction-icon">🅿️</span>
                        <span>Есть парковка для клиентов рядом с сервисом</span>
                    </div>
                </div>
            </div>

        </div>

        <!-- ПРАВАЯ КОЛОНКА -->
        <div>
            <!-- Карта -->
            <div class="map-container">
                <iframe
                    src="https://yandex.ru/map-widget/v1/?text=%D0%92%D0%BE%D0%BB%D0%BE%D0%B3%D0%B4%D0%B0%2C%20%D0%9A%D0%B8%D1%80%D0%BF%D0%B8%D1%87%D0%BD%D0%B0%D1%8F%20%D1%83%D0%BB.%2C%2048%D0%90&z=16"
                    width="100%"
                    height="100%"
                    frameborder="0"
                    allowfullscreen="true"
                    style="border:0; border-radius: 14px;"
                    title="Карта проезда к Автокул СТО"
                    loading="lazy"></iframe>
                <a href="https://yandex.ru/maps/?text=%D0%92%D0%BE%D0%BB%D0%BE%D0%B3%D0%B4%D0%B0,+%D0%9A%D0%B8%D1%80%D0%BF%D0%B8%D1%87%D0%BD%D0%B0%D1%8F+%D1%83%D0%BB.,+48%D0%90"
                   target="_blank" rel="noopener" class="map-link" style="margin-top: 12px; display: inline-block;">
                    🗺️ Открыть в Яндекс.Картах
                </a>
            </div>
        </div>

    </div>

    <!-- Форма обратной связи (внизу) -->
    <div class="info-card" style="margin-bottom: 40px;">
        <h2>
            <span class="icon-circle icon-red">📝</span> 
            Обратная связь
        </h2>

        <p style="color: var(--gray-500); margin-bottom: 20px; font-size: 14px;">
            Остались вопросы или предложения? Напишите нам — мы ответим в ближайшее время.
        </p>

        <?php if ($form_success): ?>
            <div class="alert alert-success">
                ✅ Спасибо за обращение! Мы получили ваше сообщение и свяжемся с вами в ближайшее время.
            </div>
        <?php endif; ?>

        <?php if ($form_error): ?>
            <div class="alert alert-error">
                ⚠️ Пожалуйста, исправьте ошибки:<br><?php echo $form_error; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/contacts.php#contactForm" id="contactForm" class="contact-form" novalidate>
            <input type="hidden" name="action" value="send_contact">
            
            <!-- Защита от спама (скрытое поле) -->
            <input type="text" name="honeypot" class="honeypot" tabindex="-1" autocomplete="off">

            <div class="form-row">
                <div class="form-group">
                    <label for="name">Ваше имя <span class="required">*</span></label>
                    <input type="text" id="name" name="name" 
                           value="<?php echo htmlspecialchars($form_data['name']); ?>"
                           placeholder="Иван Петров" required>
                </div>
                <div class="form-group">
                    <label for="phone">Телефон</label>
                    <input type="tel" id="phone" name="phone" 
                           value="<?php echo htmlspecialchars($form_data['phone']); ?>"
                           placeholder="+7 (900) 123-45-67">
                </div>
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" 
                       value="<?php echo htmlspecialchars($form_data['email']); ?>"
                       placeholder="user@example.com">
            </div>

            <div class="form-group">
                <label for="message">Сообщение <span class="required">*</span></label>
                <textarea id="message" name="message" 
                          placeholder="Опишите ваш вопрос или предложение..."
                          required><?php echo htmlspecialchars($form_data['message']); ?></textarea>
            </div>

            <button type="submit" class="form-submit">📤 Отправить сообщение</button>
        </form>
    </div>

</section>

<script>
// Валидация формы перед отправкой
document.getElementById('contactForm').addEventListener('submit', function(e) {
    const name = document.getElementById('name').value.trim();
    const message = document.getElementById('message').value.trim();
    const email = document.getElementById('email').value.trim();
    const phone = document.getElementById('phone').value.trim();
    
    let errors = [];
    
    if (!name || name.length < 2) {
        errors.push('• Укажите ваше имя (минимум 2 символа)');
    }
    
    if (!email && !phone) {
        errors.push('• Укажите email или телефон для связи');
    }
    
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errors.push('• Введите корректный email');
    }
    
    if (!message || message.length < 10) {
        errors.push('• Напишите сообщение (минимум 10 символов)');
    }
    
    if (errors.length > 0) {
        e.preventDefault();
        alert('Пожалуйста, исправьте ошибки:\n\n' + errors.join('\n'));
    }
});

// Плавный скролл к форме, если есть якорь
if (window.location.hash === '#contactForm') {
    document.getElementById('contactForm').scrollIntoView({ behavior: 'smooth' });
}
</script>

<?php
require_once 'includes/footer.php';
?>