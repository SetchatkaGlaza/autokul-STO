<?php
// includes/avatar.php - Функции для работы с аватарами

/**
 * Возвращает URL аватара пользователя.
 * Если аватар не установлен — возвращает дефолтный.
 *
 * @param string|null $avatar_path Путь к аватару из БД
 * @return string URL аватара
 */
function getAvatarUrl($avatar_path = null) {
    if (!empty($avatar_path)) {
        // Поддержка случаев, когда в сессии/БД уже лежит путь с ведущим "/"
        $normalized = ltrim($avatar_path, '/');
        if (file_exists(__DIR__ . '/../' . $normalized)) {
            return '/' . $normalized;
        }
    }
    return '/uploads/avatars/default.png';
}

/**
 * Возвращает HTML-код аватара пользователя (тег img).
 * 
 * @param string|null $avatar_path Путь к аватару из БД
 * @param string $alt Альтернативный текст
 * @param int $size Размер в пикселях
 * @param string $class Дополнительные CSS-классы
 * @return string HTML-код
 */
function getAvatarHtml($avatar_path = null, $alt = 'Аватар', $size = 80, $class = '') {
    $url = getAvatarUrl($avatar_path);
    $size_attr = $size . 'px';
    return '<img src="' . htmlspecialchars($url) . '" 
                 alt="' . htmlspecialchars($alt) . '" 
                 width="' . $size . '" 
                 height="' . $size . '"
                 class="avatar-img ' . htmlspecialchars($class) . '"
                 style="width: ' . $size_attr . '; height: ' . $size_attr . '; object-fit: cover; border-radius: 50%;">';
}

/**
 * Возвращает инициалы пользователя (первая буква имени).
 *
 * @param string $full_name Полное имя
 * @return string Инициал
 */
function getInitials($full_name) {
    $name = trim($full_name);
    if (empty($name)) return '?';
    return mb_strtoupper(mb_substr($name, 0, 1));
}

/**
 * Валидирует загруженный файл аватара.
 * 
 * @param array $file Массив $_FILES['avatar']
 * @return array [success => bool, message => string, path => string|null]
 */
function validateAvatarUpload($file) {
    // Проверка на ошибки загрузки
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
            'message' => $error_messages[$error_code] ?? 'Неизвестная ошибка загрузки.',
            'path' => null
        ];
    }
    
    // Проверка размера (максимум 5 МБ)
    $max_size = 5 * 1024 * 1024; // 5 MB
    if ($file['size'] > $max_size) {
        return [
            'success' => false,
            'message' => 'Размер файла не должен превышать 5 МБ.',
            'path' => null
        ];
    }
    
    // Проверка MIME-типа
    $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types)) {
        return [
            'success' => false,
            'message' => 'Недопустимый формат файла. Разрешены: JPEG, PNG, WebP, GIF.',
            'path' => null
        ];
    }
    
    // Проверка расширения файла
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    
    if (!in_array($extension, $allowed_extensions)) {
        return [
            'success' => false,
            'message' => 'Недопустимое расширение файла. Разрешены: jpg, jpeg, png, webp, gif.',
            'path' => null
        ];
    }
    
    // Проверка, что это действительно изображение
    $image_info = getimagesize($file['tmp_name']);
    if ($image_info === false) {
        return [
            'success' => false,
            'message' => 'Загруженный файл не является изображением.',
            'path' => null
        ];
    }
    
    // Проверка минимальных размеров
    $min_width = 100;
    $min_height = 100;
    if ($image_info[0] < $min_width || $image_info[1] < $min_height) {
        return [
            'success' => false,
            'message' => 'Изображение должно быть не менее ' . $min_width . 'x' . $min_height . ' пикселей.',
            'path' => null
        ];
    }
    
    return [
        'success' => true,
        'message' => 'Файл прошёл проверку.',
        'path' => null,
        'mime_type' => $mime_type,
        'extension' => $extension,
        'width' => $image_info[0],
        'height' => $image_info[1]
    ];
}

/**
 * Сохраняет аватар пользователя.
 * 
 * @param array $file Массив $_FILES['avatar']
 * @param int $user_id ID пользователя
 * @param PDO $pdo Соединение с БД
 * @return array [success => bool, message => string, avatar_url => string|null, avatar_path => string|null]
 */
function saveAvatar($file, $user_id, $pdo) {
    // Валидация
    $validation = validateAvatarUpload($file);
    if (!$validation['success']) {
        return [
            'success' => false,
            'message' => $validation['message'],
            'avatar_url' => null,
            'avatar_path' => null
        ];
    }
    
    try {
        // Получаем текущий аватар пользователя
        $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = :id");
        $stmt->execute(['id' => $user_id]);
        $old_avatar = $stmt->fetchColumn();
        
        // Создаём директорию, если её нет
        $upload_dir = __DIR__ . '/../uploads/avatars/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // Генерируем уникальное имя файла
        $extension = $validation['extension'];
        $filename = 'avatar_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $filepath = $upload_dir . $filename;
        
        // Создаём изображение из загруженного файла
        $source_image = null;
        switch ($validation['mime_type']) {
            case 'image/jpeg':
                $source_image = imagecreatefromjpeg($file['tmp_name']);
                break;
            case 'image/png':
                $source_image = imagecreatefrompng($file['tmp_name']);
                break;
            case 'image/webp':
                $source_image = imagecreatefromwebp($file['tmp_name']);
                break;
            case 'image/gif':
                $source_image = imagecreatefromgif($file['tmp_name']);
                break;
        }
        
        if (!$source_image) {
            return [
                'success' => false,
                'message' => 'Не удалось обработать изображение.',
                'avatar_url' => null,
                'avatar_path' => null
            ];
        }
        
        // Получаем размеры исходного изображения
        $src_width = imagesx($source_image);
        $src_height = imagesy($source_image);
        
        // Создаём квадратное изображение 300x300
        $avatar_size = 300;
        $avatar_image = imagecreatetruecolor($avatar_size, $avatar_size);
        
        // Включаем прозрачность для PNG/GIF/WebP
        if ($validation['mime_type'] == 'image/png' || $validation['mime_type'] == 'image/webp' || $validation['mime_type'] == 'image/gif') {
            imagealphablending($avatar_image, false);
            imagesavealpha($avatar_image, true);
            $transparent = imagecolorallocatealpha($avatar_image, 0, 0, 0, 127);
            imagefill($avatar_image, 0, 0, $transparent);
        }
        
        // Обрезаем квадрат из центра исходного изображения
        $min_side = min($src_width, $src_height);
        $src_x = ($src_width - $min_side) / 2;
        $src_y = ($src_height - $min_side) / 2;
        
        // Копируем и масштабируем
        imagecopyresampled(
            $avatar_image, $source_image,
            0, 0,               // Координаты на новом изображении
            (int)$src_x, (int)$src_y,  // Координаты на исходном
            $avatar_size, $avatar_size, // Размер нового
            (int)$min_side, (int)$min_side  // Размер копируемой области
        );
        
        // Сохраняем в нужном формате
        switch ($validation['mime_type']) {
            case 'image/jpeg':
                imagejpeg($avatar_image, $filepath, 90);
                break;
            case 'image/png':
                imagepng($avatar_image, $filepath, 8);
                break;
            case 'image/webp':
                imagewebp($avatar_image, $filepath, 90);
                break;
            case 'image/gif':
                imagegif($avatar_image, $filepath);
                break;
        }
        
        // Освобождаем память
        imagedestroy($source_image);
        imagedestroy($avatar_image);
        
        // Обновляем путь в базе данных
        $relative_path = 'uploads/avatars/' . $filename;
        $stmt = $pdo->prepare("UPDATE users SET avatar = :avatar WHERE id = :id");
        $stmt->execute([
            'avatar' => $relative_path,
            'id' => $user_id
        ]);
        
        // Удаляем старый аватар, если он не дефолтный
        if (!empty($old_avatar) && $old_avatar !== 'uploads/avatars/default.png') {
            $old_path = __DIR__ . '/../' . $old_avatar;
            if (file_exists($old_path)) {
                @unlink($old_path);
            }
        }
        
        return [
            'success' => true,
            'message' => 'Аватар успешно обновлён.',
            'avatar_url' => '/' . $relative_path,
            'avatar_path' => $relative_path
        ];
        
    } catch (Exception $e) {
        error_log('Ошибка сохранения аватара: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Ошибка при сохранении аватара. Попробуйте позже.',
            'avatar_url' => null,
            'avatar_path' => null
        ];
    }
}

/**
 * Удаляет аватар пользователя и устанавливает дефолтный.
 * 
 * @param int $user_id ID пользователя
 * @param PDO $pdo Соединение с БД
 * @return array [success => bool, message => string]
 */
function deleteAvatar($user_id, $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = :id");
        $stmt->execute(['id' => $user_id]);
        $current_avatar = $stmt->fetchColumn();
        
        // Обновляем БД
        $stmt = $pdo->prepare("UPDATE users SET avatar = NULL WHERE id = :id");
        $stmt->execute(['id' => $user_id]);
        
        // Удаляем файл
        if (!empty($current_avatar) && $current_avatar !== 'uploads/avatars/default.png') {
            $filepath = __DIR__ . '/../' . $current_avatar;
            if (file_exists($filepath)) {
                @unlink($filepath);
            }
        }
        
        return [
            'success' => true,
            'message' => 'Аватар удалён. Установлен аватар по умолчанию.'
        ];
    } catch (Exception $e) {
        error_log('Ошибка удаления аватара: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Ошибка при удалении аватара.'
        ];
    }
}
?>
