<?php
// profile.php - Личный кабинет клиента

require_once 'includes/config.php';
require_once 'includes/auth_check.php';

// Требуем авторизацию (любая роль)
requireAuth();

$page_title = 'Личный кабинет — Автокул СТО';
$pdo = getDBConnection();

// Получаем полные данные пользователя из БД
$stmt = $pdo->prepare("SELECT id, full_name, email, phone, role, created_at FROM users WHERE id = :id");
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch();

// Если пользователь не найден (редкий случай) — разлогиниваем
if (!$user) {
    header('Location: /logout.php');
    exit;
}

// ========== Определяем активную вкладку ==========
$active_tab = $_GET['tab'] ?? 'profile';
$allowed_tabs = ['profile', 'cars', 'appointments', 'reviews'];
if (!in_array($active_tab, $allowed_tabs)) {
    $active_tab = 'profile';
}

// ========== ОБРАБОТКА ФОРМ ==========
$success_message = '';
$error_message = '';

// --- Сохранение профиля ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    
    // Валидация имени
    if (empty($full_name) || mb_strlen($full_name) < 3) {
        $error_message = 'Имя должно содержать минимум 3 символа';
    } else {
        try {
            // Обновляем основные данные
            $stmt = $pdo->prepare("UPDATE users SET full_name = :name, phone = :phone WHERE id = :id");
            $stmt->execute([
                'name' => $full_name,
                'phone' => $phone ?: null,
                'id' => $user['id']
            ]);
            
            // Обновляем имя в сессии
            $_SESSION['user_name'] = $full_name;
            $user['full_name'] = $full_name;
            $user['phone'] = $phone;
            
            // Если хотят сменить пароль
            if (!empty($current_password) && !empty($new_password)) {
                // Проверяем текущий пароль
                if (password_verify($current_password, $user['password'])) {
                    if (strlen($new_password) >= 6) {
                        $new_hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 10]);
                        $stmt = $pdo->prepare("UPDATE users SET password = :pass WHERE id = :id");
                        $stmt->execute(['pass' => $new_hash, 'id' => $user['id']]);
                        $success_message = 'Профиль и пароль успешно обновлены!';
                    } else {
                        $error_message = 'Новый пароль должен содержать минимум 6 символов';
                    }
                } else {
                    $error_message = 'Текущий пароль указан неверно. Профиль обновлён без смены пароля.';
                }
            } else {
                $success_message = 'Профиль успешно обновлён!';
            }
            
            // Перезагружаем данные пользователя
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
            $stmt->execute(['id' => $user['id']]);
            $user = $stmt->fetch();
            
        } catch (PDOException $e) {
            $error_message = 'Ошибка при обновлении профиля. Попробуйте позже.';
            error_log('Ошибка обновления профиля: ' . $e->getMessage());
        }
    }
}

// --- Добавление автомобиля ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_car') {
    
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $year = intval($_POST['year'] ?? 0);
    $vin = trim($_POST['vin'] ?? '');
    $license_plate = trim($_POST['license_plate'] ?? '');
    
    if (empty($brand) || empty($model)) {
        $error_message = 'Марка и модель автомобиля обязательны';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO cars (user_id, brand, model, year, vin, license_plate) 
                                   VALUES (:uid, :brand, :model, :year, :vin, :plate)");
            $stmt->execute([
                'uid' => $user['id'],
                'brand' => $brand,
                'model' => $model,
                'year' => $year ?: null,
                'vin' => $vin ?: null,
                'plate' => $license_plate ?: null
            ]);
            $success_message = 'Автомобиль успешно добавлен!';
        } catch (PDOException $e) {
            $error_message = 'Ошибка при добавлении автомобиля.';
            error_log('Ошибка добавления авто: ' . $e->getMessage());
        }
    }
}

// --- Удаление автомобиля ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_car') {
    $car_id = intval($_POST['car_id'] ?? 0);
    
    // Проверяем, что авто принадлежит текущему пользователю
    $stmt = $pdo->prepare("SELECT id FROM cars WHERE id = :id AND user_id = :uid");
    $stmt->execute(['id' => $car_id, 'uid' => $user['id']]);
    
    if ($stmt->fetch()) {
        $delStmt = $pdo->prepare("DELETE FROM cars WHERE id = :id");
        $delStmt->execute(['id' => $car_id]);
        $success_message = 'Автомобиль удалён.';
    } else {
        $error_message = 'Автомобиль не найден.';
    }
}

// --- Отмена записи ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_appointment') {
    $appointment_id = intval($_POST['appointment_id'] ?? 0);
    
    // Проверяем, что запись принадлежит пользователю и ещё не отменена/выполнена
    $stmt = $pdo->prepare("SELECT id, status FROM appointments WHERE id = :id AND user_id = :uid");
    $stmt->execute(['id' => $appointment_id, 'uid' => $user['id']]);
    $appointment = $stmt->fetch();
    
    if ($appointment && in_array($appointment['status'], ['pending', 'confirmed'])) {
        $updStmt = $pdo->prepare("UPDATE appointments SET status = 'cancelled' WHERE id = :id");
        $updStmt->execute(['id' => $appointment_id]);
        $success_message = 'Запись успешно отменена.';
    } else {
        $error_message = 'Невозможно отменить эту запись.';
    }
}

// --- Отправка отзыва ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_review') {
    $appointment_id = intval($_POST['appointment_id'] ?? 0);
    $rating = intval($_POST['rating'] ?? 0);
    $text = trim($_POST['review_text'] ?? '');
    
    if ($rating < 1 || $rating > 5) {
        $error_message = 'Поставьте оценку от 1 до 5';
    } elseif (empty($text)) {
        $error_message = 'Напишите текст отзыва';
    } else {
        try {
            // Проверяем, что запись принадлежит пользователю и выполнена
            $stmt = $pdo->prepare("SELECT id FROM appointments WHERE id = :id AND user_id = :uid AND status = 'completed'");
            $stmt->execute(['id' => $appointment_id, 'uid' => $user['id']]);
            
            if ($stmt->fetch() || $appointment_id === 0) {
                // Если appointment_id = 0, значит отзыв без привязки к записи
                $stmt = $pdo->prepare("INSERT INTO reviews (user_id, appointment_id, rating, text, is_approved) 
                                       VALUES (:uid, :aid, :rating, :text, 0)");
                $stmt->execute([
                    'uid' => $user['id'],
                    'aid' => $appointment_id > 0 ? $appointment_id : null,
                    'rating' => $rating,
                    'text' => $text
                ]);
                $success_message = 'Спасибо! Отзыв отправлен на модерацию и скоро появится на сайте.';
            } else {
                $error_message = 'Вы можете оставить отзыв только после выполнения услуги.';
            }
        } catch (PDOException $e) {
            $error_message = 'Ошибка при отправке отзыва.';
            error_log('Ошибка отзыва: ' . $e->getMessage());
        }
    }
}

// ========== ЗАГРУЖАЕМ ДАННЫЕ (зависит от вкладки) ==========

// Автомобили пользователя
$cars = [];
if ($active_tab === 'cars' || $active_tab === 'profile') {
    $cars = $pdo->prepare("SELECT * FROM cars WHERE user_id = :uid ORDER BY id DESC")->execute(['uid' => $user['id']]) 
             ? $pdo->prepare("SELECT * FROM cars WHERE user_id = :uid ORDER BY id DESC")->fetchAll() : [];
    // Исправим: выполним запрос правильно
    $stmt = $pdo->prepare("SELECT * FROM cars WHERE user_id = :uid ORDER BY id DESC");
    $stmt->execute(['uid' => $user['id']]);
    $cars = $stmt->fetchAll();
}

// Записи пользователя
$appointments = [];
if ($active_tab === 'appointments' || $active_tab === 'reviews') {
    $stmt = $pdo->prepare("
        SELECT a.*, 
               c.brand, c.model, c.license_plate,
               GROUP_CONCAT(s.name SEPARATOR ', ') AS services_list
        FROM appointments a
        JOIN cars c ON a.car_id = c.id
        LEFT JOIN appointment_services aps ON a.id = aps.appointment_id
        LEFT JOIN services s ON aps.service_id = s.id
        WHERE a.user_id = :uid
        GROUP BY a.id
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");
    $stmt->execute(['uid' => $user['id']]);
    $appointments = $stmt->fetchAll();
}

// Отзывы пользователя
$reviews = [];
if ($active_tab === 'reviews') {
    $stmt = $pdo->prepare("
        SELECT r.*, a.appointment_date, s.name AS service_name
        FROM reviews r
        LEFT JOIN appointments a ON r.appointment_id = a.id
        LEFT JOIN appointment_services aps ON a.id = aps.appointment_id
        LEFT JOIN services s ON aps.service_id = s.id
        WHERE r.user_id = :uid
        ORDER BY r.created_at DESC
    ");
    $stmt->execute(['uid' => $user['id']]);
    $reviews = $stmt->fetchAll();
}

// Статистика
$stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
    FROM appointments WHERE user_id = :uid
");
$stats_stmt->execute(['uid' => $user['id']]);
$stats = $stats_stmt->fetch();

// Подключаем шапку
require_once 'includes/header.php';
?>

<!-- ========== СТИЛИ ДЛЯ ЛИЧНОГО КАБИНЕТА ========== -->
<style>
    /* Контейнер ЛК */
    .profile-container {
        display: flex;
        gap: 30px;
        max-width: var(--max-width);
        margin: 40px auto;
        padding: 0 20px;
    }
    
    /* Боковое меню */
    .profile-sidebar {
        width: 260px;
        flex-shrink: 0;
    }
    
    .profile-sidebar-card {
        background: var(--white);
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        border: 1px solid var(--gray-200);
        overflow: hidden;
        margin-bottom: 20px;
    }
    
    .profile-user-info {
        padding: 24px;
        text-align: center;
        background: linear-gradient(135deg, var(--secondary), #373737);
        color: var(--white);
    }
    
    .profile-avatar {
        width: 80px;
        height: 80px;
        background: var(--primary);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 32px;
        font-weight: 700;
        margin: 0 auto 12px;
        border: 3px solid rgba(255,255,255,0.3);
    }
    
    .profile-user-info h3 {
        font-size: 18px;
        margin-bottom: 4px;
    }
    
    .profile-role-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        background: rgba(255,255,255,0.2);
        color: var(--white);
        margin-top: 6px;
    }
    
    .profile-nav {
        padding: 8px;
    }
    
    .profile-nav a {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        border-radius: 8px;
        color: var(--gray-700);
        font-weight: 500;
        transition: var(--transition);
        margin-bottom: 2px;
    }
    
    .profile-nav a:hover {
        background: var(--gray-100);
        color: var(--secondary);
    }
    
    .profile-nav a.active {
        background: var(--primary-light);
        color: var(--primary);
        font-weight: 600;
    }
    
    .profile-nav-icon {
        font-size: 18px;
        width: 24px;
        text-align: center;
    }
    
    /* Основной контент */
    .profile-content {
        flex: 1;
        min-width: 0;
    }
    
    .profile-content-card {
        background: var(--white);
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.06);
        border: 1px solid var(--gray-200);
        padding: 30px;
    }
    
    .profile-content-card h2 {
        font-size: 22px;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 2px solid var(--gray-100);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    /* Статистика */
    .stats-mini-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    
    .stat-mini-card {
        background: var(--gray-100);
        border-radius: 10px;
        padding: 16px;
        text-align: center;
    }
    
    .stat-mini-card .stat-number {
        font-size: 28px;
        font-weight: 700;
        color: var(--secondary);
    }
    
    .stat-mini-card .stat-label {
        font-size: 13px;
        color: var(--gray-500);
        margin-top: 2px;
    }
    
    .stat-mini-card.accent {
        background: var(--primary-light);
    }
    
    .stat-mini-card.accent .stat-number {
        color: var(--primary);
    }
    
    /* Формы */
    .form-group {
        margin-bottom: 18px;
    }
    
    .form-group label {
        display: block;
        font-weight: 600;
        margin-bottom: 6px;
        color: var(--secondary);
        font-size: 14px;
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 10px 14px;
        border: 1px solid var(--gray-300);
        border-radius: 8px;
        font-size: 15px;
        font-family: inherit;
        transition: var(--transition);
        outline: none;
    }
    
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.1);
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    
    /* Таблица записей */
    .appointments-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }
    
    .appointments-table th {
        background: var(--gray-100);
        padding: 12px;
        text-align: left;
        font-weight: 600;
        font-size: 13px;
        color: var(--gray-700);
        border-bottom: 2px solid var(--gray-200);
    }
    
    .appointments-table td {
        padding: 12px;
        border-bottom: 1px solid var(--gray-200);
        vertical-align: middle;
    }
    
    .appointments-table tr:hover {
        background: var(--gray-100);
    }
    
    /* Статусы */
    .status-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        white-space: nowrap;
    }
    
    .status-pending {
        background: #fff3cd;
        color: #856404;
    }
    
    .status-confirmed {
        background: #cce5ff;
        color: #004085;
    }
    
    .status-in_progress {
        background: #d4edda;
        color: #155724;
    }
    
    .status-completed {
        background: #d1ecf1;
        color: #0c5460;
    }
    
    .status-cancelled {
        background: #f8d7da;
        color: #721c24;
    }
    
    /* Звёзды рейтинга */
    .stars-input {
        display: flex;
        gap: 6px;
        direction: rtl;
        justify-content: flex-end;
    }
    
    .stars-input input {
        display: none;
    }
    
    .stars-input label {
        font-size: 30px;
        color: var(--gray-300);
        cursor: pointer;
        transition: var(--transition);
    }
    
    .stars-input label:hover,
    .stars-input label:hover ~ label,
    .stars-input input:checked ~ label {
        color: #ffc107;
    }
    
    .stars-display {
        color: #ffc107;
        font-size: 18px;
        letter-spacing: 2px;
    }
    
    /* Адаптивность */
    @media (max-width: 768px) {
        .profile-container {
            flex-direction: column;
        }
        
        .profile-sidebar {
            width: 100%;
        }
        
        .profile-nav {
            display: flex;
            overflow-x: auto;
            gap: 4px;
            padding: 8px 4px;
        }
        
        .profile-nav a {
            white-space: nowrap;
            font-size: 13px;
            padding: 10px 12px;
        }
        
        .form-row {
            grid-template-columns: 1fr;
        }
        
        .appointments-table {
            font-size: 12px;
        }
        
        .appointments-table th,
        .appointments-table td {
            padding: 8px 6px;
        }
    }
</style>

<!-- ========== ОСНОВНОЙ КОНТЕНТ ========== -->
<section class="profile-container">
    
    <!-- Боковая панель -->
    <aside class="profile-sidebar">
        <div class="profile-sidebar-card">
            <div class="profile-user-info">
                <div class="profile-avatar">
                    <?php echo mb_substr($user['full_name'], 0, 1); ?>
                </div>
                <h3><?php echo htmlspecialchars($user['full_name']); ?></h3>
                <?php
                $role_names = [
                    'client' => 'Клиент',
                    'admin' => 'Администратор',
                    'mechanic' => 'Механик'
                ];
                ?>
                <span class="profile-role-badge"><?php echo $role_names[$user['role']] ?? $user['role']; ?></span>
            </div>
            <nav class="profile-nav">
                <a href="/profile.php?tab=profile" class="<?php echo $active_tab === 'profile' ? 'active' : ''; ?>">
                    <span class="profile-nav-icon">👤</span> Профиль
                </a>
                <a href="/profile.php?tab=cars" class="<?php echo $active_tab === 'cars' ? 'active' : ''; ?>">
                    <span class="profile-nav-icon">🚗</span> Мои автомобили
                </a>
                <a href="/profile.php?tab=appointments" class="<?php echo $active_tab === 'appointments' ? 'active' : ''; ?>">
                    <span class="profile-nav-icon">📅</span> Мои записи
                    <?php if ($stats['pending'] > 0): ?>
                        <span style="background: var(--primary); color: white; border-radius: 12px; padding: 2px 8px; font-size: 11px; margin-left: auto;">
                            <?php echo $stats['pending']; ?>
                        </span>
                    <?php endif; ?>
                </a>
                <a href="/profile.php?tab=reviews" class="<?php echo $active_tab === 'reviews' ? 'active' : ''; ?>">
                    <span class="profile-nav-icon">⭐</span> Мои отзывы
                </a>
            </nav>
        </div>
        
        <a href="/appointment.php" class="btn btn-primary" style="width: 100%; text-align: center;">
            ✏️ Записаться на сервис
        </a>
    </aside>
    
    <!-- Основная часть -->
    <div class="profile-content">
        
        <!-- Сообщения об успехе/ошибке -->
        <?php if ($success_message): ?>
            <div style="background: #d4edda; color: #155724; padding: 14px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #28a745;">
                ✅ <?php echo $success_message; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div style="background: #f8d7da; color: #721c24; padding: 14px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #dc3545;">
                ⚠️ <?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <?php
        // ========== ВКЛАДКА: ПРОФИЛЬ ==========
        if ($active_tab === 'profile'): 
        ?>
        <div class="profile-content-card">
            <h2>👤 Редактирование профиля</h2>
            
            <form method="POST" action="/profile.php?tab=profile">
                <input type="hidden" name="action" value="update_profile">
                
                <div class="form-group">
                    <label>Email (логин)</label>
                    <input type="email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled 
                           style="background: var(--gray-100); color: var(--gray-500); cursor: not-allowed;">
                    <small style="color: var(--gray-500);">Email изменить нельзя</small>
                </div>
                
                <div class="form-group">
                    <label>Полное имя <span style="color: var(--primary);">*</span></label>
                    <input type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Телефон</label>
                    <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="+7 (900) 123-45-67">
                </div>
                
                <hr style="border: none; border-top: 1px solid var(--gray-200); margin: 24px 0;">
                
                <h3 style="font-size: 16px; margin-bottom: 16px;">🔒 Смена пароля</h3>
                <p style="font-size: 13px; color: var(--gray-500); margin-bottom: 16px;">Оставьте поля пустыми, если не хотите менять пароль.</p>
                
                <div class="form-group">
                    <label>Текущий пароль</label>
                    <input type="password" name="current_password" placeholder="Введите текущий пароль">
                </div>
                
                <div class="form-group">
                    <label>Новый пароль</label>
                    <input type="password" name="new_password" placeholder="Минимум 6 символов" minlength="6">
                </div>
                
                <button type="submit" class="btn btn-primary" style="margin-top: 8px;">💾 Сохранить изменения</button>
            </form>
            
            <div style="margin-top: 20px; padding: 16px; background: var(--gray-100); border-radius: 8px; font-size: 13px; color: var(--gray-700);">
                <strong>📅 Дата регистрации:</strong> <?php echo date('d.m.Y H:i', strtotime($user['created_at'])); ?>
            </div>
        </div>
        
        <?php
        // ========== ВКЛАДКА: АВТОМОБИЛИ ==========
        elseif ($active_tab === 'cars'): 
        ?>
        <div class="profile-content-card">
            <h2>🚗 Мои автомобили</h2>
            
            <?php if (empty($cars)): ?>
                <p style="text-align: center; color: var(--gray-500); padding: 40px;">
                    У вас пока нет добавленных автомобилей. Добавьте первый автомобиль для быстрой записи.
                </p>
            <?php else: ?>
                <div style="display: grid; gap: 16px; margin-bottom: 30px;">
                    <?php foreach ($cars as $car): ?>
                        <div style="background: var(--gray-100); border-radius: 10px; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                            <div>
                                <strong style="font-size: 16px;"><?php echo htmlspecialchars($car['brand'] . ' ' . $car['model']); ?></strong>
                                <?php if ($car['year']): ?>
                                    <span style="color: var(--gray-500);">(<?php echo $car['year']; ?> г.)</span>
                                <?php endif; ?>
                                <br>
                                <?php if ($car['license_plate']): ?>
                                    <span style="font-size: 13px; color: var(--gray-500);">🚘 <?php echo htmlspecialchars($car['license_plate']); ?></span>
                                <?php endif; ?>
                                <?php if ($car['vin']): ?>
                                    <span style="font-size: 13px; color: var(--gray-500); margin-left: 10px;">VIN: <?php echo htmlspecialchars($car['vin']); ?></span>
                                <?php endif; ?>
                            </div>
                            <form method="POST" action="/profile.php?tab=cars" onsubmit="return confirm('Удалить этот автомобиль?')">
                                <input type="hidden" name="action" value="delete_car">
                                <input type="hidden" name="car_id" value="<?php echo $car['id']; ?>">
                                <button type="submit" style="background: none; border: 1px solid #dc3545; color: #dc3545; padding: 6px 14px; border-radius: 6px; cursor: pointer; font-size: 13px; transition: var(--transition);"
                                        onmouseover="this.style.background='#dc3545'; this.style.color='white';"
                                        onmouseout="this.style.background='none'; this.style.color='#dc3545';">Удалить</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <h3 style="font-size: 18px; margin-bottom: 16px;">➕ Добавить автомобиль</h3>
            <form method="POST" action="/profile.php?tab=cars">
                <input type="hidden" name="action" value="add_car">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Марка <span style="color: var(--primary);">*</span></label>
                        <input type="text" name="brand" placeholder="Например: Toyota" required>
                    </div>
                    <div class="form-group">
                        <label>Модель <span style="color: var(--primary);">*</span></label>
                        <input type="text" name="model" placeholder="Например: Camry" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Год выпуска</label>
                        <input type="number" name="year" placeholder="2020" min="1950" max="<?php echo date('Y'); ?>">
                    </div>
                    <div class="form-group">
                        <label>Госномер</label>
                        <input type="text" name="license_plate" placeholder="А123БВ177" maxlength="10">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>VIN-номер</label>
                    <input type="text" name="vin" placeholder="17 символов" maxlength="17">
                </div>
                
                <button type="submit" class="btn btn-primary">➕ Добавить автомобиль</button>
            </form>
        </div>
        
        <?php
        // ========== ВКЛАДКА: ЗАПИСИ ==========
        elseif ($active_tab === 'appointments'): 
        ?>
        <div class="profile-content-card">
            <h2>📅 Мои записи</h2>
            
            <!-- Мини-статистика -->
            <div class="stats-mini-grid">
                <div class="stat-mini-card accent">
                    <div class="stat-number"><?php echo $stats['pending']; ?></div>
                    <div class="stat-label">Ожидают</div>
                </div>
                <div class="stat-mini-card">
                    <div class="stat-number"><?php echo $stats['confirmed']; ?></div>
                    <div class="stat-label">Подтверждены</div>
                </div>
                <div class="stat-mini-card">
                    <div class="stat-number"><?php echo $stats['completed']; ?></div>
                    <div class="stat-label">Выполнены</div>
                </div>
                <div class="stat-mini-card">
                    <div class="stat-number"><?php echo $stats['cancelled']; ?></div>
                    <div class="stat-label">Отменены</div>
                </div>
            </div>
            
            <?php if (empty($appointments)): ?>
                <p style="text-align: center; color: var(--gray-500); padding: 30px;">
                    У вас пока нет записей. <a href="/appointment.php" style="color: var(--primary);">Записаться на сервис?</a>
                </p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="appointments-table">
                        <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Время</th>
                                <th>Автомобиль</th>
                                <th>Услуги</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($appointments as $appt): ?>
                                <tr>
                                    <td><?php echo date('d.m.Y', strtotime($appt['appointment_date'])); ?></td>
                                    <td><?php echo date('H:i', strtotime($appt['appointment_time'])); ?></td>
                                    <td><?php echo htmlspecialchars($appt['brand'] . ' ' . $appt['model']); ?></td>
                                    <td><?php echo htmlspecialchars($appt['services_list'] ?? '—'); ?></td>
                                    <td>
                                        <?php
                                        $status_labels = [
                                            'pending' => 'Ожидает',
                                            'confirmed' => 'Подтверждена',
                                            'in_progress' => 'В работе',
                                            'completed' => 'Выполнена',
                                            'cancelled' => 'Отменена'
                                        ];
                                        $status_class = 'status-' . $appt['status'];
                                        ?>
                                        <span class="status-badge <?php echo $status_class; ?>">
                                            <?php echo $status_labels[$appt['status']] ?? $appt['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (in_array($appt['status'], ['pending', 'confirmed'])): ?>
                                            <form method="POST" action="/profile.php?tab=appointments" onsubmit="return confirm('Вы уверены, что хотите отменить эту запись?')">
                                                <input type="hidden" name="action" value="cancel_appointment">
                                                <input type="hidden" name="appointment_id" value="<?php echo $appt['id']; ?>">
                                                <button type="submit" style="background: none; border: 1px solid #dc3545; color: #dc3545; padding: 4px 10px; border-radius: 6px; cursor: pointer; font-size: 12px;">Отменить</button>
                                            </form>
                                        <?php elseif ($appt['status'] === 'completed'): ?>
                                            <a href="/profile.php?tab=reviews&appointment=<?php echo $appt['id']; ?>" 
                                               style="color: var(--primary); font-size: 13px; font-weight: 500;">Оставить отзыв</a>
                                        <?php else: ?>
                                            <span style="color: var(--gray-500); font-size: 12px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        
        <?php
        // ========== ВКЛАДКА: ОТЗЫВЫ ==========
        elseif ($active_tab === 'reviews'): 
            // Определяем, для какой записи форма отзыва (если перешли по ссылке)
            $review_appointment_id = isset($_GET['appointment']) ? intval($_GET['appointment']) : 0;
        ?>
        <div class="profile-content-card">
            <h2>⭐ Мои отзывы</h2>
            
            <!-- Список моих отзывов -->
            <?php if (empty($reviews)): ?>
                <p style="text-align: center; color: var(--gray-500); padding: 20px;">
                    Вы пока не оставили ни одного отзыва. Отзывы помогают нам становиться лучше!
                </p>
            <?php else: ?>
                <?php foreach ($reviews as $review): ?>
                    <div style="background: var(--gray-100); border-radius: 10px; padding: 16px; margin-bottom: 12px;">
                        <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 8px;">
                            <span class="stars-display">
                                <?php echo str_repeat('★', $review['rating']) . str_repeat('☆', 5 - $review['rating']); ?>
                            </span>
                            <span style="font-size: 12px; color: var(--gray-500);">
                                <?php echo date('d.m.Y H:i', strtotime($review['created_at'])); ?>
                                <?php if ($review['service_name']): ?>
                                    · <?php echo htmlspecialchars($review['service_name']); ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <p style="font-size: 14px; color: var(--gray-700);"><?php echo nl2br(htmlspecialchars($review['text'])); ?></p>
                        <div style="margin-top: 8px;">
                            <?php if ($review['is_approved']): ?>
                                <span style="color: #28a745; font-size: 12px;">✅ Опубликован</span>
                            <?php else: ?>
                                <span style="color: var(--warning); font-size: 12px;">⏳ На модерации</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <!-- Форма нового отзыва -->
            <h3 style="font-size: 18px; margin-top: 30px; margin-bottom: 16px;">✍️ Новый отзыв</h3>
            
            <form method="POST" action="/profile.php?tab=reviews">
                <input type="hidden" name="action" value="add_review">
                
                <div class="form-group">
                    <label>К какой услуге отзыв?</label>
                    <select name="appointment_id">
                        <option value="0">Общий отзыв (без привязки к услуге)</option>
                        <?php foreach ($appointments as $appt): ?>
                            <?php if ($appt['status'] === 'completed'): ?>
                                <option value="<?php echo $appt['id']; ?>" <?php echo $review_appointment_id === (int)$appt['id'] ? 'selected' : ''; ?>>
                                    <?php echo date('d.m.Y', strtotime($appt['appointment_date'])) . ' — ' . htmlspecialchars($appt['services_list'] ?? 'Услуги'); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Оценка <span style="color: var(--primary);">*</span></label>
                    <div class="stars-input">
                        <input type="radio" id="star5" name="rating" value="5">
                        <label for="star5" title="5 звёзд">★</label>
                        <input type="radio" id="star4" name="rating" value="4">
                        <label for="star4" title="4 звезды">★</label>
                        <input type="radio" id="star3" name="rating" value="3">
                        <label for="star3" title="3 звезды">★</label>
                        <input type="radio" id="star2" name="rating" value="2">
                        <label for="star2" title="2 звезды">★</label>
                        <input type="radio" id="star1" name="rating" value="1" required>
                        <label for="star1" title="1 звезда">★</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Текст отзыва <span style="color: var(--primary);">*</span></label>
                    <textarea name="review_text" rows="4" placeholder="Расскажите о вашем опыте..." required></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary">📤 Отправить отзыв</button>
            </form>
        </div>
        
        <?php endif; ?>
    </div>
</section>

<?php
require_once 'includes/footer.php';
?>