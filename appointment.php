<?php
// appointment.php - Страница онлайн-записи на обслуживание

require_once 'includes/config.php';
require_once 'includes/auth_check.php';

// Требуем авторизацию
requireAuth();

$page_title = 'Онлайн-запись — Автокул СТО';
$pdo = getDBConnection();

// Получаем данные пользователя
$stmt = $pdo->prepare("SELECT id, full_name, email, phone FROM users WHERE id = :id");
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch();

// Получаем автомобили пользователя
$stmt = $pdo->prepare("SELECT * FROM cars WHERE user_id = :uid ORDER BY id");
$stmt->execute(['uid' => $user['id']]);
$cars = $stmt->fetchAll();

// Получаем категории с услугами
$categories = $pdo->query("
    SELECT c.id AS cat_id, c.name AS cat_name, c.description AS cat_desc,
           s.id AS svc_id, s.name AS svc_name, s.description AS svc_desc, 
           s.price, s.duration
    FROM categories c
    LEFT JOIN services s ON c.id = s.category_id AND s.is_active = 1
    ORDER BY c.id, s.id
")->fetchAll();

// Группируем услуги по категориям
$grouped_services = [];
foreach ($categories as $row) {
    if (!isset($grouped_services[$row['cat_id']])) {
        $grouped_services[$row['cat_id']] = [
            'name' => $row['cat_name'],
            'description' => $row['cat_desc'],
            'services' => []
        ];
    }
    if ($row['svc_id']) {
        $grouped_services[$row['cat_id']]['services'][] = [
            'id' => $row['svc_id'],
            'name' => $row['svc_name'],
            'description' => $row['svc_desc'],
            'price' => $row['price'],
            'duration' => $row['duration']
        ];
    }
}

// Переменные для ошибок и успеха
$error_message = '';
$success_message = '';
$form_errors = [];

// Обработка формы записи
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_appointment') {
    
    $car_id = intval($_POST['car_id'] ?? 0);
    $new_car_brand = trim($_POST['new_car_brand'] ?? '');
    $new_car_model = trim($_POST['new_car_model'] ?? '');
    $new_car_year = intval($_POST['new_car_year'] ?? 0);
    $new_car_plate = trim($_POST['new_car_plate'] ?? '');
    $new_car_vin = trim($_POST['new_car_vin'] ?? '');
    
    $service_ids = $_POST['service_ids'] ?? [];
    $appointment_date = $_POST['appointment_date'] ?? '';
    $appointment_time = $_POST['appointment_time'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    
    // === ВАЛИДАЦИЯ ===
    
    $final_car_id = null;
    if ($car_id === 'new') {
        if (empty($new_car_brand) || empty($new_car_model)) {
            $form_errors['car'] = 'Укажите марку и модель автомобиля';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO cars (user_id, brand, model, year, license_plate, vin) 
                                       VALUES (:uid, :brand, :model, :year, :plate, :vin)");
                $stmt->execute([
                    'uid' => $user['id'],
                    'brand' => $new_car_brand,
                    'model' => $new_car_model,
                    'year' => $new_car_year ?: null,
                    'plate' => $new_car_plate ?: null,
                    'vin' => $new_car_vin ?: null
                ]);
                $final_car_id = $pdo->lastInsertId();
            } catch (PDOException $e) {
                $form_errors['car'] = 'Ошибка при добавлении автомобиля';
            }
        }
    } else {
        $stmt = $pdo->prepare("SELECT id FROM cars WHERE id = :id AND user_id = :uid");
        $stmt->execute(['id' => $car_id, 'uid' => $user['id']]);
        $car = $stmt->fetch();
        if ($car) {
            $final_car_id = $car['id'];
        } else {
            $form_errors['car'] = 'Выбранный автомобиль не найден';
        }
    }
    
    if (empty($service_ids) || !is_array($service_ids)) {
        $form_errors['services'] = 'Выберите хотя бы одну услугу';
    } else {
        $placeholders = implode(',', array_fill(0, count($service_ids), '?'));
        $stmt = $pdo->prepare("SELECT id, duration FROM services WHERE id IN ($placeholders) AND is_active = 1");
        $stmt->execute(array_map('intval', $service_ids));
        $valid_services = $stmt->fetchAll();
        
        if (count($valid_services) !== count($service_ids)) {
            $form_errors['services'] = 'Некоторые выбранные услуги недоступны';
        } else {
            $total_duration = array_sum(array_column($valid_services, 'duration'));
        }
    }
    
    if (empty($appointment_date)) {
        $form_errors['date'] = 'Выберите дату визита';
    } elseif (strtotime($appointment_date) < strtotime(date('Y-m-d'))) {
        $form_errors['date'] = 'Нельзя записаться на прошедшую дату';
    } else {
        $day_of_week = date('N', strtotime($appointment_date));
        $stmt = $pdo->prepare("SELECT id FROM work_schedule WHERE day_of_week = :day");
        $stmt->execute(['day' => $day_of_week]);
        // СТО работает ежедневно: если отдельной записи в таблице нет,
        // используем стандартный график и не блокируем выбор даты.
    }
    
    if (empty($appointment_time)) {
        $form_errors['time'] = 'Выберите время визита';
    }
    
    if (empty($form_errors) && $final_car_id && !empty($valid_services)) {
        $slot_start = strtotime($appointment_date . ' ' . $appointment_time);
        $slot_end = $slot_start + ($total_duration * 60);
        
        $stmt = $pdo->prepare("
            SELECT a.id, a.appointment_time, SUM(s.duration) AS booked_duration
            FROM appointments a
            JOIN appointment_services aps ON a.id = aps.appointment_id
            JOIN services s ON aps.service_id = s.id
            WHERE a.appointment_date = :date
              AND a.status NOT IN ('cancelled')
            GROUP BY a.id, a.appointment_time
        ");
        $stmt->execute(['date' => $appointment_date]);
        $existing = $stmt->fetchAll();
        
        foreach ($existing as $ex) {
            $ex_start = strtotime($appointment_date . ' ' . $ex['appointment_time']);
            $ex_end = $ex_start + ($ex['booked_duration'] * 60);
            
            if ($slot_start < $ex_end && $slot_end > $ex_start) {
                $form_errors['time'] = 'Это время только что заняли. Пожалуйста, выберите другое время.';
                break;
            }
        }
    }
    
    if (empty($form_errors) && $final_car_id && !empty($valid_services)) {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("
                INSERT INTO appointments (user_id, car_id, appointment_date, appointment_time, status, notes) 
                VALUES (:uid, :car, :date, :time, 'pending', :notes)
            ");
            $stmt->execute([
                'uid' => $user['id'],
                'car' => $final_car_id,
                'date' => $appointment_date,
                'time' => $appointment_time,
                'notes' => $notes
            ]);
            
            $appointment_id = $pdo->lastInsertId();
            
            $insert_service = $pdo->prepare("INSERT INTO appointment_services (appointment_id, service_id) VALUES (:aid, :sid)");
            foreach ($valid_services as $svc) {
                $insert_service->execute(['aid' => $appointment_id, 'sid' => $svc['id']]);
            }
            
            $pdo->commit();
            
            $success_message = 'Запись успешно создана! Мы свяжемся с вами для подтверждения. Номер записи: #' . $appointment_id;
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error_message = 'Ошибка при создании записи. Попробуйте позже.';
            error_log('Ошибка создания записи: ' . $e->getMessage());
        }
    }
}

require_once 'includes/header.php';
?>

<style>
    /* ========== ОСНОВНОЙ КОНТЕЙНЕР ========== */
    .appointment-page {
        max-width: 900px;
        margin: 40px auto;
        padding: 0 20px;
    }

    .appointment-page h1 {
        text-align: center;
        margin-bottom: 8px;
        font-size: 30px;
    }

    .appointment-subtitle {
        text-align: center;
        color: var(--gray-500);
        margin-bottom: 30px;
        font-size: 15px;
    }

    /* ========== КАРТОЧКИ-ШАГИ ========== */
    .step-card {
        background: var(--white);
        border-radius: 14px;
        box-shadow: 0 2px 16px rgba(0,0,0,0.06);
        border: 1px solid var(--gray-200);
        padding: 28px 30px;
        margin-bottom: 22px;
    }

    .step-card h2 {
        font-size: 20px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
        justify-content: flex-start;
        flex-wrap: wrap;
        line-height: 1.3;
    }

    .step-title-text {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .step-number {
        width: 34px;
        height: 34px;
        background: var(--primary);
        color: white;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        font-weight: 700;
        flex-shrink: 0;
    }

    /* ========== ПОИСК УСЛУГ ========== */
    .search-box {
        position: relative;
        margin-bottom: 18px;
    }

    .search-box input {
        width: 100%;
        padding: 12px 16px 12px 44px;
        border: 1px solid var(--gray-300);
        border-radius: 10px;
        font-size: 15px;
        outline: none;
        transition: var(--transition);
        background: var(--gray-100);
    }

    .search-box input:focus {
        border-color: var(--primary);
        background: var(--white);
        box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.08);
    }

    .search-icon {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 18px;
        color: var(--gray-500);
        pointer-events: none;
    }

    /* ========== КАТЕГОРИИ-АККОРДЕОНЫ ========== */
    .category-accordion {
        border: 1px solid var(--gray-200);
        border-radius: 10px;
        margin-bottom: 10px;
        overflow: hidden;
        transition: var(--transition);
    }

    .category-accordion.has-selected {
        border-color: var(--primary);
        box-shadow: 0 0 0 2px rgba(211, 47, 47, 0.1);
    }

    .category-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 18px;
        background: var(--gray-100);
        cursor: pointer;
        user-select: none;
        transition: var(--transition);
        gap: 12px;
    }

    .category-header:hover {
        background: var(--gray-200);
    }

    .category-header-left {
        display: flex;
        align-items: center;
        gap: 10px;
        flex: 1;
        min-width: 0;
    }

    .category-icon {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        flex-shrink: 0;
    }

    .category-info h3 {
        font-size: 15px;
        font-weight: 600;
        color: var(--secondary);
    }

    .category-info span {
        font-size: 12px;
        color: var(--gray-500);
    }

    .category-arrow {
        font-size: 14px;
        color: var(--gray-500);
        transition: transform 0.3s ease;
        flex-shrink: 0;
    }

    .category-accordion.open .category-arrow {
        transform: rotate(180deg);
    }

    .category-badge {
        background: var(--primary);
        color: white;
        font-size: 11px;
        font-weight: 600;
        padding: 3px 9px;
        border-radius: 12px;
        white-space: nowrap;
        display: none;
    }

    .category-accordion.has-selected .category-badge {
        display: inline-block;
    }

    /* Тело аккордеона */
    .category-body {
        display: none;
        padding: 8px 18px 16px;
        background: var(--white);
    }

    .category-accordion.open .category-body {
        display: block;
    }

    /* Карточки услуг внутри категории */
    .services-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .service-item {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 12px 14px;
        border-radius: 8px;
        cursor: pointer;
        transition: var(--transition);
        border: 1px solid transparent;
        position: relative;
    }

    .service-item:hover {
        background: var(--gray-100);
    }

    .service-item.selected {
        background: var(--primary-light);
        border-color: var(--primary);
    }

    /* Кастомный чекбокс */
    .service-checkbox-custom {
        width: 22px;
        height: 22px;
        border: 2px solid var(--gray-300);
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: var(--transition);
        font-size: 12px;
        color: transparent;
    }

    .service-item.selected .service-checkbox-custom {
        background: var(--primary);
        border-color: var(--primary);
        color: white;
    }

    .service-item-info {
        flex: 1;
        min-width: 0;
    }

    .service-item-info .svc-name {
        font-weight: 600;
        font-size: 14px;
        color: var(--secondary);
        margin-bottom: 2px;
    }

    .service-item-info .svc-desc {
        font-size: 12px;
        color: var(--gray-500);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .service-item-meta {
        text-align: right;
        flex-shrink: 0;
    }

    .service-item-meta .svc-price {
        font-weight: 700;
        font-size: 16px;
        color: var(--primary);
    }

    .service-item-meta .svc-duration {
        font-size: 12px;
        color: var(--gray-500);
    }

    /* Скрытый чекбокс */
    .service-item input[type="checkbox"] {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    /* ========== ИТОГО ПО УСЛУГАМ ========== */
    .services-summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 16px;
        padding: 14px 18px;
        background: linear-gradient(135deg, var(--secondary), #2a2a2a);
        border-radius: 10px;
        color: white;
    }

    .services-summary-left {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
    }

    .summary-item {
        font-size: 14px;
    }

    .summary-item strong {
        display: block;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        opacity: 0.7;
        margin-bottom: 2px;
    }

    .summary-item .value {
        font-size: 16px;
        font-weight: 600;
    }

    .summary-price {
        font-size: 24px;
        font-weight: 700;
        color: #4caf50;
    }

    .no-services-message {
        text-align: center;
        padding: 30px;
        color: var(--gray-400);
        font-size: 15px;
    }

    /* ========== СЛОТЫ ВРЕМЕНИ ========== */
    .time-slots-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
        gap: 10px;
        margin-top: 14px;
    }

    .time-slot-btn {
        padding: 12px 8px;
        border-radius: 8px;
        border: 1px solid var(--gray-300);
        background: var(--white);
        cursor: pointer;
        text-align: center;
        transition: var(--transition);
        font-size: 14px;
        font-weight: 500;
    }

    .time-slot-btn:hover {
        border-color: var(--primary);
        color: var(--primary);
        background: var(--primary-light);
    }

    .time-slot-btn.selected {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
        box-shadow: 0 2px 8px rgba(211, 47, 47, 0.3);
    }

    .time-slot-btn.unavailable {
        background: var(--gray-200);
        color: var(--gray-400);
        cursor: not-allowed;
        text-decoration: line-through;
        border-style: dashed;
    }

    .loading-spinner {
        display: inline-block;
        width: 18px;
        height: 18px;
        border: 3px solid var(--gray-300);
        border-radius: 50%;
        border-top-color: var(--primary);
        animation: spin 0.8s linear infinite;
        margin-left: 8px;
        vertical-align: middle;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    /* ========== КНОПКА ОТПРАВКИ ========== */
    .submit-btn {
        width: 100%;
        padding: 16px;
        font-size: 18px;
        font-weight: 600;
        border: none;
        border-radius: 12px;
        background: var(--primary);
        color: white;
        cursor: pointer;
        transition: var(--transition);
        margin-top: 8px;
    }

    .submit-btn:hover {
        background: var(--primary-dark);
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(211, 47, 47, 0.3);
    }

    .submit-btn:active {
        transform: translateY(0);
    }

    /* ========== АДАПТИВНОСТЬ ========== */
    @media (max-width: 1024px) {
        .appointment-page {
            max-width: 100%;
            margin: 24px auto;
        }
    }

    @media (max-width: 760px) {
        .appointment-page h1 {
            font-size: 24px;
        }

        .appointment-subtitle {
            font-size: 14px;
        }

        .step-card h2 {
            font-size: 18px;
        }

        .step-number {
            width: 30px;
            height: 30px;
            font-size: 14px;
        }
    }

    @media (max-width: 600px) {
        .step-card {
            padding: 18px 16px;
        }
        .service-item {
            flex-wrap: wrap;
        }
        .service-item-meta {
            text-align: left;
            width: 100%;
            padding-left: 36px;
        }
        .services-summary {
            flex-direction: column;
            text-align: center;
        }
        .time-slots-grid {
            grid-template-columns: repeat(3, 1fr);
        }

        .submit-btn {
            padding: 14px;
            font-size: 16px;
        }
    }

    @media (max-width: 430px) {
        .appointment-page {
            padding: 0 12px;
        }

        .time-slots-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .service-item {
            padding: 10px;
        }

        .service-item-info .svc-name {
            font-size: 14px;
        }
    }
</style>

<div class="appointment-page">

    <h1>✏️ Онлайн-запись на обслуживание</h1>
    <p class="appointment-subtitle">Выберите нужные услуги, автомобиль и удобное время — мы всё сделаем в лучшем виде</p>

    <!-- Сообщения -->
    <?php if ($success_message): ?>
        <div style="background: #d4edda; color: #155724; padding: 20px; border-radius: 12px; margin-bottom: 22px; border-left: 4px solid #28a745; font-size: 15px;">
            <strong>✅ <?php echo $success_message; ?></strong>
            <br><br>
            <a href="/profile.php?tab=appointments" class="btn btn-primary" style="display: inline-block;">Перейти к моим записям</a>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div style="background: #f8d7da; color: #721c24; padding: 14px; border-radius: 8px; margin-bottom: 22px; border-left: 4px solid #dc3545;">
            ⚠️ <?php echo $error_message; ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="/appointment.php" id="appointmentForm">
        <input type="hidden" name="action" value="create_appointment">
        <input type="hidden" name="appointment_time" id="selectedTime">

        <!-- ====== ШАГ 1: ВЫБОР УСЛУГ ====== -->
        <div class="step-card" id="step1">
            <h2><span class="step-number">1</span> <span class="step-title-text">Выберите услуги</span></h2>

            <?php if (isset($form_errors['services'])): ?>
                <p style="color: #dc3545; font-size: 13px; margin-bottom: 12px;"><?php echo $form_errors['services']; ?></p>
            <?php endif; ?>

            <!-- Поиск услуг -->
            <div class="search-box">
                <span class="search-icon">🔍</span>
                <input type="text" id="serviceSearch" placeholder="Поиск услуги по названию...">
            </div>

            <!-- Категории-аккордеоны -->
            <div id="categoriesContainer">
                <?php foreach ($grouped_services as $cat_id => $cat_data): ?>
                    <?php if (empty($cat_data['services'])) continue; ?>
                    <?php
                    $icons = [
                        1 => '🔌', 2 => '🛠️', 3 => '⚙️', 4 => '🔧',
                        5 => '🔄', 6 => '🛞', 7 => '🎨', 8 => '⚡'
                    ];
                    $colors = [
                        1 => '#e3f2fd', 2 => '#e8f5e9', 3 => '#fff3e0', 4 => '#fce4ec',
                        5 => '#f3e5f5', 6 => '#e0f7fa', 7 => '#fff8e1', 8 => '#efebe9'
                    ];
                    ?>
                    <div class="category-accordion" data-category="<?php echo $cat_id; ?>">
                        <div class="category-header" onclick="toggleCategory(this)">
                            <div class="category-header-left">
                                <div class="category-icon" style="background: <?php echo $colors[$cat_id] ?? '#f5f5f5'; ?>;">
                                    <?php echo $icons[$cat_id] ?? '📋'; ?>
                                </div>
                                <div class="category-info">
                                    <h3><?php echo htmlspecialchars($cat_data['name']); ?></h3>
                                    <span><?php echo count($cat_data['services']); ?> услуг</span>
                                </div>
                            </div>
                            <span class="category-badge">Выбрано</span>
                            <span class="category-arrow">▼</span>
                        </div>
                        <div class="category-body">
                            <div class="services-list">
                                <?php foreach ($cat_data['services'] as $svc): ?>
                                    <label class="service-item" data-search="<?php echo mb_strtolower($svc['name']); ?>">
                                        <input type="checkbox" name="service_ids[]" value="<?php echo $svc['id']; ?>" 
                                               data-duration="<?php echo $svc['duration']; ?>" 
                                               data-price="<?php echo $svc['price']; ?>"
                                               data-category="<?php echo $cat_id; ?>"
                                               onchange="onServiceChange(this)">
                                        <span class="service-checkbox-custom">✓</span>
                                        <div class="service-item-info">
                                            <div class="svc-name"><?php echo htmlspecialchars($svc['name']); ?></div>
                                            <div class="svc-desc"><?php echo htmlspecialchars(mb_substr($svc['description'], 0, 80)); ?>...</div>
                                        </div>
                                        <div class="service-item-meta">
                                            <div class="svc-price"><?php echo number_format($svc['price'], 0, ',', ' '); ?> ₽</div>
                                            <div class="svc-duration">⏱ <?php echo $svc['duration']; ?> мин.</div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div id="noServicesFound" style="display:none; text-align:center; padding:20px; color:var(--gray-400);">
                🔍 Услуги не найдены. Попробуйте изменить запрос.
            </div>

            <!-- Итого -->
            <div class="services-summary" id="servicesSummary">
                <div class="services-summary-left">
                    <div class="summary-item">
                        <strong>Выбрано услуг</strong>
                        <span class="value" id="selectedCount">0</span>
                    </div>
                    <div class="summary-item">
                        <strong>Общее время</strong>
                        <span class="value" id="totalDuration">0 мин.</span>
                    </div>
                </div>
                <div class="summary-price" id="totalPrice">0 ₽</div>
            </div>
        </div>

        <!-- ====== ШАГ 2: АВТОМОБИЛЬ ====== -->
        <div class="step-card" id="step2">
            <h2><span class="step-number">2</span> <span class="step-title-text">Выберите автомобиль</span></h2>

            <?php if (isset($form_errors['car'])): ?>
                <p style="color: #dc3545; font-size: 13px; margin-bottom: 12px;"><?php echo $form_errors['car']; ?></p>
            <?php endif; ?>

            <?php if (!empty($cars)): ?>
                <div style="display: grid; gap: 10px; margin-bottom: 16px;">
                    <?php foreach ($cars as $car): ?>
                        <label style="display: flex; align-items: center; gap: 12px; padding: 14px 16px; border: 2px solid var(--gray-200); border-radius: 10px; cursor: pointer; transition: var(--transition);"
                               onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--gray-200)'">
                            <input type="radio" name="car_id" value="<?php echo $car['id']; ?>" 
                                   <?php echo count($cars) === 1 ? 'checked' : ''; ?>
                                   onchange="document.getElementById('newCarFields').style.display='none'">
                            <div>
                                <strong><?php echo htmlspecialchars($car['brand'] . ' ' . $car['model']); ?></strong>
                                <?php if ($car['year']): ?>(<?php echo $car['year']; ?>)<?php endif; ?>
                                <?php if ($car['license_plate'] || $car['vin']): ?>
                                    <br><small style="color: var(--gray-500);">
                                        <?php if ($car['license_plate']): ?>🚘 <?php echo htmlspecialchars($car['license_plate']); ?><?php endif; ?>
                                        <?php if ($car['vin']): ?> · VIN: <?php echo htmlspecialchars($car['vin']); ?><?php endif; ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p style="color: var(--gray-500); margin-bottom: 16px;">У вас пока нет добавленных автомобилей.</p>
            <?php endif; ?>

            <label style="display: flex; align-items: center; gap: 12px; padding: 14px 16px; border: 2px dashed var(--gray-300); border-radius: 10px; cursor: pointer;"
                   onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--gray-300)'">
                <input type="radio" name="car_id" value="new" onchange="document.getElementById('newCarFields').style.display='block'"
                       <?php echo empty($cars) ? 'checked' : ''; ?>>
                <strong>➕ Добавить новый автомобиль</strong>
            </label>

            <div id="newCarFields" style="display: <?php echo empty($cars) ? 'block' : 'none'; ?>; margin-top: 14px;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div>
                        <label style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">Марка *</label>
                        <input type="text" name="new_car_brand" placeholder="Toyota" style="width: 100%; padding: 10px; border: 1px solid var(--gray-300); border-radius: 8px;">
                    </div>
                    <div>
                        <label style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">Модель *</label>
                        <input type="text" name="new_car_model" placeholder="Camry" style="width: 100%; padding: 10px; border: 1px solid var(--gray-300); border-radius: 8px;">
                    </div>
                    <div>
                        <label style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">Год</label>
                        <input type="number" name="new_car_year" placeholder="2020" min="1950" max="<?php echo date('Y'); ?>" style="width: 100%; padding: 10px; border: 1px solid var(--gray-300); border-radius: 8px;">
                    </div>
                    <div>
                        <label style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">Госномер</label>
                        <input type="text" name="new_car_plate" placeholder="А123БВ177" maxlength="10" style="width: 100%; padding: 10px; border: 1px solid var(--gray-300); border-radius: 8px;">
                    </div>
                </div>
                <div style="margin-top: 12px;">
                    <label style="font-size: 13px; font-weight: 600; display: block; margin-bottom: 4px;">VIN</label>
                    <input type="text" name="new_car_vin" placeholder="17 символов" maxlength="17" style="width: 100%; padding: 10px; border: 1px solid var(--gray-300); border-radius: 8px;">
                </div>
            </div>
        </div>

        <!-- ====== ШАГ 3: ДАТА И ВРЕМЯ ====== -->
        <div class="step-card" id="step3">
            <h2><span class="step-number">3</span> <span class="step-title-text">Выберите дату и время</span></h2>

            <div style="margin-bottom: 16px;">
                <label style="font-weight: 600; display: block; margin-bottom: 6px;">Дата визита</label>
                <input type="date" name="appointment_date" id="appointmentDate" 
                       min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                       max="<?php echo date('Y-m-d', strtotime('+30 days')); ?>"
                       onchange="loadTimeSlots()"
                       style="width: 100%; padding: 12px; border: 1px solid var(--gray-300); border-radius: 10px; font-size: 15px;">
                <?php if (isset($form_errors['date'])): ?>
                    <p style="color: #dc3545; font-size: 13px; margin-top: 4px;"><?php echo $form_errors['date']; ?></p>
                <?php endif; ?>
                <small style="color: var(--gray-500);">Запись доступна ежедневно в течение 30 дней</small>
            </div>

            <div id="timeSlotsContainer" style="display: none;">
                <label style="font-weight: 600; display: block; margin-bottom: 8px;">Доступное время</label>
                <div id="timeSlotsLoading" style="text-align: center; padding: 20px; color: var(--gray-500);">
                    Загрузка доступных слотов... <span class="loading-spinner"></span>
                </div>
                <div class="time-slots-grid" id="timeSlots"></div>
                <p id="timeSlotsMessage" style="color: var(--gray-500); font-size: 13px; margin-top: 8px;"></p>
                <?php if (isset($form_errors['time'])): ?>
                    <p style="color: #dc3545; font-size: 13px;"><?php echo $form_errors['time']; ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ====== ШАГ 4: ПРИМЕЧАНИЯ ====== -->
        <div class="step-card">
            <h2><span class="step-number">4</span> <span class="step-title-text">Примечания к записи</span></h2>
            <textarea name="notes" rows="3" placeholder="Опишите проблему или особые пожелания (необязательно)..." 
                      style="width: 100%; padding: 12px 14px; border: 1px solid var(--gray-300); border-radius: 10px; font-family: inherit; font-size: 14px; resize: vertical;"></textarea>
        </div>

        <button type="submit" class="submit-btn">✅ Подтвердить запись</button>
    </form>
</div>

<script>
// ========== АККОРДЕОНЫ КАТЕГОРИЙ ==========
function toggleCategory(header) {
    const accordion = header.parentElement;
    accordion.classList.toggle('open');
}

// Изначально открываем первую категорию
document.querySelector('.category-accordion')?.classList.add('open');

// ========== ПОИСК УСЛУГ ==========
document.getElementById('serviceSearch').addEventListener('input', function() {
    const query = this.value.toLowerCase().trim();
    const accordions = document.querySelectorAll('.category-accordion');
    let foundAny = false;

    accordions.forEach(acc => {
        const items = acc.querySelectorAll('.service-item');
        let categoryHasVisible = false;

        items.forEach(item => {
            const searchData = item.dataset.search;
            if (query === '' || searchData.includes(query)) {
                item.style.display = 'flex';
                categoryHasVisible = true;
                foundAny = true;
            } else {
                item.style.display = 'none';
            }
        });

        acc.style.display = categoryHasVisible ? '' : 'none';
        // Открываем категорию, где есть совпадения
        if (categoryHasVisible && query !== '') {
            acc.classList.add('open');
        }
    });

    document.getElementById('noServicesFound').style.display = foundAny || query === '' ? 'none' : 'block';
    document.getElementById('categoriesContainer').style.display = foundAny || query === '' ? '' : 'none';
});

// ========== ВЫБОР УСЛУГИ ==========
function onServiceChange(checkbox) {
    const serviceItem = checkbox.closest('.service-item');
    const categoryAccordion = checkbox.closest('.category-accordion');
    const catId = checkbox.dataset.category;

    // Визуал выбора
    if (checkbox.checked) {
        serviceItem.classList.add('selected');
    } else {
        serviceItem.classList.remove('selected');
    }

    // Обновление бейджа категории
    updateCategoryBadge(categoryAccordion, catId);

    // Обновление сводки
    updateSummary();

    // Перезагрузка слотов при необходимости
    const dateInput = document.getElementById('appointmentDate');
    if (dateInput.value) {
        loadTimeSlots();
    }
}

function updateCategoryBadge(accordion, catId) {
    const checkedInCategory = document.querySelectorAll(`input[data-category="${catId}"]:checked`).length;
    if (checkedInCategory > 0) {
        accordion.classList.add('has-selected');
        accordion.querySelector('.category-badge').textContent = `Выбрано: ${checkedInCategory}`;
    } else {
        accordion.classList.remove('has-selected');
    }
}

function updateSummary() {
    const checked = document.querySelectorAll('.service-item input:checked');
    let count = checked.length;
    let duration = 0;
    let price = 0;

    checked.forEach(cb => {
        duration += parseInt(cb.dataset.duration);
        price += parseFloat(cb.dataset.price);
    });

    document.getElementById('selectedCount').textContent = count;
    document.getElementById('totalDuration').textContent = duration + ' мин.';
    document.getElementById('totalPrice').textContent = new Intl.NumberFormat('ru-RU').format(price) + ' ₽';
}

// ========== ЗАГРУЗКА СЛОТОВ ВРЕМЕНИ ==========
async function loadTimeSlots() {
    const date = document.getElementById('appointmentDate').value;
    const durationText = document.getElementById('totalDuration').textContent;
    const duration = parseInt(durationText) || 60;

    if (!date || duration === 0) {
        document.getElementById('timeSlotsContainer').style.display = 'none';
        return;
    }

    const container = document.getElementById('timeSlotsContainer');
    const slotsDiv = document.getElementById('timeSlots');
    const loadingDiv = document.getElementById('timeSlotsLoading');
    const messageP = document.getElementById('timeSlotsMessage');

    container.style.display = 'block';
    loadingDiv.style.display = 'block';
    slotsDiv.innerHTML = '';
    document.getElementById('selectedTime').value = '';

    try {
        const response = await fetch(`/includes/get_slots.php?date=${date}&duration=${duration}`);
        const data = await response.json();

        loadingDiv.style.display = 'none';

        if (data.error) {
            messageP.textContent = 'Ошибка: ' + data.error;
            messageP.style.color = '#dc3545';
            return;
        }

        if (data.slots.length === 0) {
            messageP.textContent = data.message || 'На эту дату нет свободного времени';
            messageP.style.color = '#856404';
            return;
        }

        messageP.textContent = data.message || '';
        messageP.style.color = 'var(--gray-500)';

        data.slots.forEach(slot => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'time-slot-btn';
            btn.textContent = slot.time + '–' + slot.end_time;
            btn.addEventListener('click', function() {
                document.querySelectorAll('.time-slot-btn.selected').forEach(b => b.classList.remove('selected'));
                this.classList.add('selected');
                document.getElementById('selectedTime').value = slot.time;
            });
            slotsDiv.appendChild(btn);
        });

    } catch (err) {
        loadingDiv.style.display = 'none';
        messageP.textContent = 'Ошибка загрузки. Проверьте соединение.';
        messageP.style.color = '#dc3545';
        console.error(err);
    }
}

// ========== ВАЛИДАЦИЯ ПЕРЕД ОТПРАВКОЙ ==========
document.getElementById('appointmentForm').addEventListener('submit', function(e) {
    let errors = [];

    const checkedServices = document.querySelectorAll('.service-item input:checked');
    if (checkedServices.length === 0) {
        errors.push('• Выберите хотя бы одну услугу');
    }

    const carSelected = document.querySelector('input[name="car_id"]:checked');
    if (!carSelected) {
        errors.push('• Выберите автомобиль');
    }

    const date = document.getElementById('appointmentDate').value;
    if (!date) {
        errors.push('• Выберите дату визита');
    }

    const time = document.getElementById('selectedTime').value;
    if (!time) {
        errors.push('• Выберите время визита');
    }

    if (errors.length > 0) {
        e.preventDefault();
        alert('Пожалуйста, завершите оформление записи:\n\n' + errors.join('\n'));
    }
});

// ========== ИНИЦИАЛИЗАЦИЯ ==========
// Восстанавливаем состояние при загрузке (если были ошибки и форма перезагрузилась)
document.querySelectorAll('.service-item input:checked').forEach(cb => {
    cb.closest('.service-item').classList.add('selected');
    const catId = cb.dataset.category;
    const acc = cb.closest('.category-accordion');
    updateCategoryBadge(acc, catId);
});
updateSummary();

// Если есть выбранная дата и услуги — загружаем слоты
const savedDate = document.getElementById('appointmentDate').value;
if (savedDate && parseInt(document.getElementById('totalDuration').textContent) > 0) {
    loadTimeSlots();
}
</script>

<?php
require_once 'includes/footer.php';
?>
