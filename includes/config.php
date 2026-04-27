<?php
// config.php - Параметры подключения к БД

// Имя хоста (Open Server по умолчанию localhost)
define('DB_HOST', 'localhost');
// Имя базы данных
define('DB_NAME', 'autokul_sto');
// Пользователь (по умолчанию root)
define('DB_USER', 'root');
// Пароль (по умолчанию пустой)
define('DB_PASS', '');

// Набор символов для соединения
define('DB_CHARSET', 'utf8mb4');

// Функция подключения к БД с использованием PDO (современный и безопасный способ)
function getDBConnection() {
    // Строка подключения (DSN - Data Source Name)
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    
    // Опции для PDO (массив настроек)
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // Выбрасывать исключения при ошибках
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // Результат по умолчанию - ассоциативный массив
        PDO::ATTR_EMULATE_PREPARES   => false,                  // Отключаем эмуляцию подготовленных запросов (для безопасности)
    ];
    
    try {
        // Создаём объект PDO - наше соединение
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;
    } catch (PDOException $e) {
        // В реальном проекте здесь должна быть запись в лог-файл
        // При разработке можно показать ошибку
        die('Ошибка подключения к базе данных: ' . $e->getMessage());
    }
}
?>