<?php
// admin/services.php - Управление услугами

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth_check.php';

requireAuth('admin');

$page_title = 'Управление услугами — Панель управления';
$pdo = getDBConnection();

// ============================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ (должны быть до вызова)
// ============================================================

/**
 * Валидация изображения услуги
 */
function validateServiceImage($file) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'Файл превышает максимальный размер, установленный сервером.',
            UPLOAD_ERR_FORM_SIZE => 'Файл превышает максимальный размер, указанный в форме.',
            UPLOAD_ERR_PARTIAL => 'Файл был загружен частично.',
            UPLOAD_ERR_NO_FILE => 'Файл не был загружен.',
            UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная папка на сервере.',
            UPLOAD_ERR_CANT_WRITE => 'Ошибка записи файла на диск.',
            UPLOAD_ERR_EXTENSION => 'Загрузка остановлена расширением PHP.',
        ];
        $error_code = $file['error'];
        return [
            'success' => false,
            'message' => $error_messages[$error_code] ?? 'Неизвестная ошибка загрузки.'
        ];
    }
    
    $max_size = 10 * 1024 * 1024;
    if ($file['size'] > $max_size) {
        return [
            'success' => false,
            'message' => 'Размер изображения не должен превышать 10 МБ.'
        ];
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime_type, $allowed_types)) {
        return [
            'success' => false,
            'message' => 'Допустимы только форматы: JPEG, PNG, WebP.'
        ];
    }
    
    $image_info = @getimagesize($file['tmp_name']);
    if ($image_info === false) {
        return [
            'success' => false,
            'message' => 'Файл не является изображением.'
        ];
    }
    
    if ($image_info[0] < 400 || $image_info[1] < 300) {
        return [
            'success' => false,
            'message' => 'Минимальное разрешение: 400x300 пикселей.'
        ];
    }
    
    return [
        'success' => true,
        'message' => 'OK',
        'mime_type' => $mime_type
    ];
}

/**
 * Сохранение изображения услуги
 */
function saveServiceImage($file) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $extension = 'jpg';
    switch ($mime_type) {
        case 'image/png':
            $extension = 'png';
            break;
        case 'image/webp':
            $extension = 'webp';
            break;
    }
    
    $upload_dir = __DIR__ . '/../uploads/services/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $filename = 'service_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $filepath = $upload_dir . $filename;
    
    $source = null;
    switch ($mime_type) {
        case 'image/jpeg':
            $source = @imagecreatefromjpeg($file['tmp_name']);
            break;
        case 'image/png':
            $source = @imagecreatefrompng($file['tmp_name']);
            break;
        case 'image/webp':
            $source = @imagecreatefromwebp($file['tmp_name']);
            break;
    }
    
    if (!$source) {
        return null;
    }
    
    $src_w = imagesx($source);
    $src_h = imagesy($source);
    
    $new_w = 800;
    $new_h = 600;
    
    $ratio_src = $src_w / $src_h;
    $ratio_new = $new_w / $new_h;
    
    if ($ratio_src > $ratio_new) {
        $crop_w = $src_h * $ratio_new;
        $crop_h = $src_h;
        $crop_x = ($src_w - $crop_w) / 2;
        $crop_y = 0;
    } else {
        $crop_w = $src_w;
        $crop_h = $src_w / $ratio_new;
        $crop_x = 0;
        $crop_y = ($src_h - $crop_h) / 2;
    }
    
    $new_image = imagecreatetruecolor($new_w, $new_h);
    
    if ($mime_type == 'image/png') {
        imagealphablending($new_image, false);
        imagesavealpha($new_image, true);
    }
    
    imagecopyresampled(
        $new_image, $source,
        0, 0,
        (int)$crop_x, (int)$crop_y,
        $new_w, $new_h,
        (int)$crop_w, (int)$crop_h
    );
    
    switch ($mime_type) {
        case 'image/jpeg':
            imagejpeg($new_image, $filepath, 85);
            break;
        case 'image/png':
            imagepng($new_image, $filepath, 7);
            break;
        case 'image/webp':
            imagewebp($new_image, $filepath, 85);
            break;
    }
    
    imagedestroy($source);
    imagedestroy($new_image);
    
    return 'uploads/services/' . $filename;
}

/**
 * Построение URL с сохранением фильтров
 */
function buildUrl($params = []) {
    $get = $_GET;
    foreach ($params as $key => $value) {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            unset($get[$key]);
        } else {
            $get[$key] = $value;
        }
    }
    $get = array_filter($get, function($v) { 
        return $v !== '' && $v !== 0 && $v !== '0'; 
    });
    $url = '/admin/services.php';
    if (!empty($get)) {
        $url .= '?' . http_build_query($get);
    }
    return $url;
}

// ============================================================
// AJAX-ОБРАБОТЧИКИ
// ============================================================

// AJAX: получение данных услуги для редактирования
if (isset($_GET['edit']) && isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    $edit_id = intval($_GET['edit']);
    $stmt = $pdo->prepare("SELECT * FROM services WHERE id = :id");
    $stmt->execute(['id' => $edit_id]);
    $service = $stmt->fetch();
    if ($service) {
        echo json_encode($service, JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Услуга не найдена'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// AJAX: переключение активности услуги
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_active') {
    $service_id = intval($_POST['service_id'] ?? 0);
    
    $stmt = $pdo->prepare("SELECT is_active FROM services WHERE id = :id");
    $stmt->execute(['id' => $service_id]);
    $current = $stmt->fetchColumn();
    
    $new_state = $current ? 0 : 1;
    $stmt = $pdo->prepare("UPDATE services SET is_active = :state WHERE id = :id");
    $stmt->execute(['state' => $new_state, 'id' => $service_id]);
    
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'is_active' => (bool)$new_state], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    header('Location: /admin/services.php');
    exit;
}

// ============================================================
// ОБРАБОТКА POST-ЗАПРОСОВ
// ============================================================

$message = '';
$message_type = '';

// Сохранение услуги (добавление/редактирование)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_service') {
    $service_id = intval($_POST['service_id'] ?? 0);
    $category_id = intval($_POST['category_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = floatval(str_replace(',', '.', $_POST['price'] ?? 0));
    $duration = intval($_POST['duration'] ?? 60);
    
    $errors = [];
    
    if ($category_id <= 0) $errors[] = 'Выберите категорию';
    if (empty($name)) $errors[] = 'Введите название услуги';
    if (mb_strlen($name) > 200) $errors[] = 'Название не должно превышать 200 символов';
    if ($price <= 0) $errors[] = 'Укажите корректную цену (больше 0)';
    if ($duration <= 0) $errors[] = 'Укажите длительность (минимум 1 минута)';
    if ($duration > 1440) $errors[] = 'Длительность не может быть больше 24 часов';
    
    // Обработка изображения
    $image_path = null;
    if (isset($_FILES['service_image']) && $_FILES['service_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $image_validation = validateServiceImage($_FILES['service_image']);
        if ($image_validation['success']) {
            $image_path = saveServiceImage($_FILES['service_image']);
            if (!$image_path) {
                $errors[] = 'Ошибка при сохранении изображения.';
            }
        } else {
            $errors[] = $image_validation['message'];
        }
    }
    
    if (empty($errors)) {
        try {
            if ($service_id > 0) {
                // Редактирование
                if ($image_path) {
                    $stmt = $pdo->prepare("SELECT image FROM services WHERE id = :id");
                    $stmt->execute(['id' => $service_id]);
                    $old_image = $stmt->fetchColumn();
                    
                    $stmt = $pdo->prepare("
                        UPDATE services 
                        SET category_id = :cat, name = :name, description = :desc, 
                            image = :img, price = :price, duration = :dur 
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        'cat' => $category_id, 'name' => $name, 'desc' => $description,
                        'img' => $image_path, 'price' => $price, 'dur' => $duration,
                        'id' => $service_id
                    ]);
                    
                    if (!empty($old_image) && file_exists(__DIR__ . '/../' . $old_image)) {
                        @unlink(__DIR__ . '/../' . $old_image);
                    }
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE services 
                        SET category_id = :cat, name = :name, description = :desc, 
                            price = :price, duration = :dur 
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        'cat' => $category_id, 'name' => $name, 'desc' => $description,
                        'price' => $price, 'dur' => $duration, 'id' => $service_id
                    ]);
                }
                $message = 'Услуга "' . htmlspecialchars($name) . '" успешно обновлена!';
                $message_type = 'success';
            } else {
                // Добавление
                $stmt = $pdo->prepare("
                    INSERT INTO services (category_id, name, description, image, price, duration, is_active) 
                    VALUES (:cat, :name, :desc, :img, :price, :dur, 1)
                ");
                $stmt->execute([
                    'cat' => $category_id, 'name' => $name, 'desc' => $description,
                    'img' => $image_path, 'price' => $price, 'dur' => $duration
                ]);
                $new_id = $pdo->lastInsertId();
                $message = 'Услуга "' . htmlspecialchars($name) . '" успешно добавлена!';
                $message_type = 'success';
            }
        } catch (PDOException $e) {
            $message = 'Ошибка базы данных: ' . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        $message = 'Пожалуйста, исправьте ошибки:<br>- ' . implode('<br>- ', $errors);
        $message_type = 'error';
    }
}

// Удаление услуги
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_service') {
    $service_id = intval($_POST['service_id'] ?? 0);
    
    try {
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM appointment_services WHERE service_id = :id");
        $check_stmt->execute(['id' => $service_id]);
        $linked_count = $check_stmt->fetchColumn();
        
        if ($linked_count > 0) {
            $stmt = $pdo->prepare("UPDATE services SET is_active = 0 WHERE id = :id");
            $stmt->execute(['id' => $service_id]);
            $message = 'Услуга деактивирована (имеет ' . $linked_count . ' связанных записей).';
            $message_type = 'warning';
        } else {
            // Удаляем изображение
            $stmt = $pdo->prepare("SELECT image FROM services WHERE id = :id");
            $stmt->execute(['id' => $service_id]);
            $old_image = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("DELETE FROM services WHERE id = :id");
            $stmt->execute(['id' => $service_id]);
            
            if (!empty($old_image) && file_exists(__DIR__ . '/../' . $old_image)) {
                @unlink(__DIR__ . '/../' . $old_image);
            }
            
            $message = 'Услуга успешно удалена.';
            $message_type = 'success';
        }
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            $stmt = $pdo->prepare("UPDATE services SET is_active = 0 WHERE id = :id");
            $stmt->execute(['id' => $service_id]);
            $message = 'Услуга деактивирована (есть связанные данные).';
            $message_type = 'warning';
        } else {
            $message = 'Ошибка при удалении: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// ============================================================
// ФИЛЬТРАЦИЯ И ПАГИНАЦИЯ
// ============================================================

$filter_category = isset($_GET['category']) ? intval($_GET['category']) : 0;
$search = trim($_GET['search'] ?? '');
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 12;

$categories = $pdo->query("SELECT * FROM categories ORDER BY id")->fetchAll();

$where = [];
$params = [];

if ($filter_category > 0) {
    $where[] = "s.category_id = :cat";
    $params['cat'] = $filter_category;
}

if (!empty($search)) {
    $where[] = "(s.name LIKE :search1 OR s.description LIKE :search2)";
    $params['search1'] = '%' . $search . '%';
    $params['search2'] = '%' . $search . '%';
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$count_sql = "SELECT COUNT(*) FROM services s $where_clause";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_services = $count_stmt->fetchColumn();
$total_pages = ceil($total_services / max($per_page, 1));
$offset = ($page - 1) * $per_page;

$query = "
    SELECT s.*, c.name AS category_name
    FROM services s
    JOIN categories c ON s.category_id = c.id
    $where_clause
    ORDER BY c.id, s.is_active DESC, s.name ASC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$services = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        :root { --sidebar-width: 250px; }

        .admin-wrapper {
            display: flex;
            min-height: calc(100vh - var(--header-height));
        }

        .admin-sidebar {
            width: var(--sidebar-width);
            background: #212121;
            color: white;
            flex-shrink: 0;
            padding: 20px 0;
            position: sticky;
            top: var(--header-height);
            height: calc(100vh - var(--header-height));
            overflow-y: auto;
        }

        .sidebar-title {
            padding: 0 20px 20px;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255,255,255,0.5);
            font-weight: 600;
        }

        .sidebar-nav { display: flex; flex-direction: column; }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            color: rgba(255,255,255,0.75);
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 500;
            border-left: 3px solid transparent;
        }

        .sidebar-nav a:hover {
            background: rgba(255,255,255,0.05);
            color: white;
        }

        .sidebar-nav a.active {
            background: rgba(211, 47, 47, 0.2);
            color: white;
            border-left-color: #d32f2f;
        }

        .sidebar-nav a .nav-badge {
            margin-left: auto;
            background: #d32f2f;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
        }

        .sidebar-divider {
            border: none;
            border-top: 1px solid rgba(255,255,255,0.1);
            margin: 10px 0;
        }

        .admin-content {
            flex: 1;
            padding: 24px;
            background: #f5f5f5;
            min-width: 0;
        }

        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .admin-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: #212121;
        }

        .btn {
            display: inline-block;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }

        .btn-primary { background: #d32f2f; color: white; }
        .btn-primary:hover { background: #b71c1c; }
        .btn-outline { background: white; color: #d32f2f; border: 1px solid #d32f2f; }
        .btn-outline:hover { background: #d32f2f; color: white; }
        .btn-sm { padding: 5px 12px; font-size: 12px; border-radius: 6px; }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }

        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .alert-warning { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; }

        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 16px 20px;
            border: 1px solid #e0e0e0;
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: #616161;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .filter-group input,
        .filter-group select {
            padding: 8px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }
        .filter-group input:focus,
        .filter-group select:focus {
            border-color: #d32f2f;
            box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.08);
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .service-card-admin {
            background: white;
            border-radius: 12px;
            border: 1px solid #e0e0e0;
            padding: 20px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .service-card-admin:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
            border-color: #d32f2f;
        }

        .service-card-admin.inactive {
            opacity: 0.5;
            background: #fafafa;
        }

        .service-card-admin.inactive:hover {
            opacity: 0.8;
        }

        .service-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }

        .service-category-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            background: #e3f2fd;
            color: #1565c0;
        }

        .service-card-admin h3 {
            font-size: 16px;
            font-weight: 600;
            color: #212121;
            margin: 8px 0;
            line-height: 1.3;
        }

        .service-card-admin .description {
            font-size: 13px;
            color: #9e9e9e;
            margin-bottom: 12px;
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .service-card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-top: 1px solid #f5f5f5;
            padding-top: 12px;
        }

        .service-price {
            font-size: 20px;
            font-weight: 700;
            color: #d32f2f;
        }

        .service-duration {
            font-size: 13px;
            color: #9e9e9e;
        }

        .service-actions {
            display: flex;
            gap: 6px;
            position: absolute;
            top: 16px;
            right: 16px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .service-card-admin:hover .service-actions {
            opacity: 1;
        }

        .service-actions button {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: 1px solid #e0e0e0;
            background: white;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .service-actions button:hover {
            border-color: #d32f2f;
            color: #d32f2f;
        }

        .inactive-badge {
            position: absolute;
            top: 12px;
            left: 12px;
            background: #f8d7da;
            color: #721c24;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .pagination a,
        .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 13px;
            text-decoration: none;
        }
        .pagination a { background: white; border: 1px solid #e0e0e0; color: #616161; }
        .pagination a:hover { border-color: #d32f2f; color: #d32f2f; }
        .pagination .active { background: #d32f2f; color: white; border-color: #d32f2f; }
        .pagination .disabled { opacity: 0.4; pointer-events: none; }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-overlay.show { display: flex; }

        .modal {
            background: white;
            border-radius: 14px;
            padding: 30px;
            width: 100%;
            max-width: 560px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }

        .modal h2 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
            color: #212121;
        }

        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #616161;
            margin-bottom: 4px;
        }
        .form-group label .required { color: #d32f2f; }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            outline: none;
            transition: all 0.3s ease;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #d32f2f;
            box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.08);
        }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 10px;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #9e9e9e;
        }

        @media (max-width: 768px) {
            .admin-wrapper { flex-direction: column; }
            .admin-sidebar {
                width: 100%;
                height: auto;
                position: static;
                padding: 10px 0;
            }
            .sidebar-nav { flex-direction: row; overflow-x: auto; gap: 2px; }
            .sidebar-nav a {
                border-left: none;
                border-bottom: 3px solid transparent;
                white-space: nowrap;
                padding: 10px 14px;
                font-size: 13px;
            }
            .sidebar-nav a.active {
                border-left: none;
                border-bottom-color: #d32f2f;
            }
            .admin-content { padding: 14px; }
            .services-grid { grid-template-columns: 1fr; }
            .filters-card { flex-direction: column; }
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../includes/header.php'; ?>

    <div class="admin-wrapper">
        
        <aside class="admin-sidebar">
            <div class="sidebar-title">Панель управления</div>
            <nav class="sidebar-nav">
                <a href="/admin/index.php">Дашборд</a>
                <a href="/admin/appointments.php">Записи</a>
                <a href="/admin/services.php" class="active">Услуги</a>
                <a href="/admin/categories.php">Категории</a>
                <a href="/admin/reviews.php">Отзывы</a>
                <a href="/admin/users.php">Клиенты</a>
                <hr class="sidebar-divider">
                <a href="/index.php">На сайт</a>
                <a href="/logout.php">Выйти</a>
            </nav>
        </aside>

        <main class="admin-content">
            
            <div class="admin-header">
                <h1>Управление услугами</h1>
                <button class="btn btn-primary" onclick="openModal()">Добавить услугу</button>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <form method="GET" action="/admin/services.php" class="filters-card">
                <div class="filter-group" style="flex: 1; min-width: 180px;">
                    <label>Поиск</label>
                    <input type="text" name="search" placeholder="Название услуги..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-group" style="min-width: 180px;">
                    <label>Категория</label>
                    <select name="category" onchange="this.form.submit()">
                        <option value="0">Все категории</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo $filter_category === (int)$cat['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group" style="align-self: flex-end;">
                    <button type="submit" class="btn btn-primary btn-sm">Применить</button>
                    <a href="/admin/services.php" class="btn btn-outline btn-sm">Сбросить</a>
                </div>
            </form>

            <?php if (count($services) > 0): ?>
                <div class="services-grid">
                    <?php foreach ($services as $svc): ?>
                        <div class="service-card-admin <?php echo !$svc['is_active'] ? 'inactive' : ''; ?>" id="service-<?php echo $svc['id']; ?>">
                            <?php if (!$svc['is_active']): ?>
                                <span class="inactive-badge">Неактивна</span>
                            <?php endif; ?>
                            
                            <div class="service-actions">
                                <button onclick="toggleActive(<?php echo $svc['id']; ?>)" 
                                        title="<?php echo $svc['is_active'] ? 'Деактивировать' : 'Активировать'; ?>">
                                    <?php echo $svc['is_active'] ? '&#128065;' : '&#128274;'; ?>
                                </button>
                                <button onclick="editService(<?php echo $svc['id']; ?>)" title="Редактировать">&#9998;</button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить услугу &laquo;<?php echo htmlspecialchars(addslashes($svc['name'])); ?>&raquo;?')">
                                    <input type="hidden" name="action" value="delete_service">
                                    <input type="hidden" name="service_id" value="<?php echo $svc['id']; ?>">
                                    <button type="submit" title="Удалить" style="color: #dc3545;">&#128465;</button>
                                </form>
                            </div>

                            <div class="service-card-header">
                                <span class="service-category-badge"><?php echo htmlspecialchars($svc['category_name']); ?></span>
                            </div>
                            <h3><?php echo htmlspecialchars($svc['name']); ?></h3>
                            <p class="description"><?php echo htmlspecialchars($svc['description'] ?: 'Без описания'); ?></p>
                            <div class="service-card-footer">
                                <span class="service-price"><?php echo number_format($svc['price'], 0, ',', ' '); ?> руб.</span>
                                <span class="service-duration"><?php echo $svc['duration']; ?> мин.</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="<?php echo buildUrl(['page' => $page - 1]); ?>">&larr;</a>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $total_pages; $i++): 
                            if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)):
                        ?>
                            <?php if ($i == $page): ?>
                                <span class="active"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="<?php echo buildUrl(['page' => $i]); ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                            <span>...</span>
                        <?php endif; endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="<?php echo buildUrl(['page' => $page + 1]); ?>">&rarr;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="empty-state">
                    <div style="font-size: 48px; margin-bottom: 12px;">&#128295;</div>
                    <p>Услуг не найдено</p>
                    <small>Добавьте первую услугу или измените параметры поиска</small>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <div class="modal-overlay" id="serviceModal">
        <div class="modal">
            <h2 id="modalTitle">Добавить услугу</h2>
            <form method="POST" action="/admin/services.php" id="serviceForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_service">
                <input type="hidden" name="service_id" id="serviceId" value="0">
                
                <div class="form-group">
                    <label>Категория <span class="required">*</span></label>
                    <select name="category_id" id="categoryId" required>
                        <option value="">Выберите категорию</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Название <span class="required">*</span></label>
                    <input type="text" name="name" id="serviceName" placeholder="Например: Замена масла" required>
                </div>
                
                <div class="form-group">
                    <label>Описание</label>
                    <textarea name="description" id="serviceDescription" placeholder="Подробное описание услуги..."></textarea>
                </div>

                <div class="form-group">
                    <label>Изображение услуги</label>
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
                        <img id="serviceImagePreview" src="/uploads/services/default-service.png" 
                             alt="Превью" 
                             style="width: 160px; height: 120px; object-fit: cover; border-radius: 8px; border: 1px solid #e0e0e0;">
                        <div>
                            <input type="file" name="service_image" id="serviceImageInput" 
                                   accept="image/jpeg,image/png,image/webp"
                                   style="display: none;" 
                                   onchange="previewServiceImage(this)">
                            <button type="button" class="btn btn-sm btn-outline" 
                                    onclick="document.getElementById('serviceImageInput').click();">
                                Выбрать изображение
                            </button>
                            <p style="font-size: 11px; color: #9e9e9e; margin-top: 4px;">
                                JPEG, PNG или WebP. Рекомендуемый размер: 800x600 пикселей.
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Цена (руб.) <span class="required">*</span></label>
                        <input type="number" name="price" id="servicePrice" placeholder="3500" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label>Длительность (мин.) <span class="required">*</span></label>
                        <input type="number" name="duration" id="serviceDuration" placeholder="60" min="1" required>
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal()">Отмена</button>
                    <button type="submit" class="btn btn-primary">Сохранить</button>
                </div>
            </form>
        </div>
    </div>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>

    <script>
    function openModal(serviceId) {
        serviceId = serviceId || null;
        const modal = document.getElementById('serviceModal');
        const title = document.getElementById('modalTitle');
        const form = document.getElementById('serviceForm');
        
        form.reset();
        document.getElementById('serviceId').value = '0';
        document.getElementById('serviceImagePreview').src = '/uploads/services/default-service.png';
        
        if (serviceId) {
            title.textContent = 'Редактировать услугу';
            fetch('/admin/services.php?edit=' + serviceId + '&ajax=1')
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.error) {
                        alert(data.error);
                        return;
                    }
                    document.getElementById('serviceId').value = data.id;
                    document.getElementById('categoryId').value = data.category_id;
                    document.getElementById('serviceName').value = data.name;
                    document.getElementById('serviceDescription').value = data.description || '';
                    document.getElementById('servicePrice').value = data.price;
                    document.getElementById('serviceDuration').value = data.duration;
                    
                    var preview = document.getElementById('serviceImagePreview');
                    if (data.image) {
                        preview.src = '/' + data.image;
                    } else {
                        preview.src = '/uploads/services/default-service.png';
                    }
                })
                .catch(function(err) {
                    console.error('Ошибка загрузки услуги:', err);
                });
        } else {
            title.textContent = 'Добавить услугу';
        }
        
        modal.classList.add('show');
    }

    function closeModal() {
        document.getElementById('serviceModal').classList.remove('show');
    }

    document.getElementById('serviceModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeModal();
    });

    function editService(serviceId) {
        openModal(serviceId);
    }

    function previewServiceImage(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('serviceImagePreview').src = e.target.result;
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    async function toggleActive(serviceId) {
        try {
            var formData = new FormData();
            formData.append('action', 'toggle_active');
            formData.append('service_id', serviceId);

            var response = await fetch('/admin/services.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            var data = await response.json();
            
            if (data.success) {
                var card = document.getElementById('service-' + serviceId);
                if (data.is_active) {
                    card.classList.remove('inactive');
                    var badge = card.querySelector('.inactive-badge');
                    if (badge) badge.remove();
                } else {
                    card.classList.add('inactive');
                    if (!card.querySelector('.inactive-badge')) {
                        var badge = document.createElement('span');
                        badge.className = 'inactive-badge';
                        badge.textContent = 'Неактивна';
                        card.insertBefore(badge, card.firstChild);
                    }
                }
            }
        } catch (err) {
            console.error('Ошибка:', err);
        }
    }
    </script>
</body>
</html>