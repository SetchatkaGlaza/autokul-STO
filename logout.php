<?php
// logout.php - Безопасный выход из системы

// Запускаем сессию
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Очищаем все данные сессии
$_SESSION = [];

// Если используются сессионные куки — удаляем их
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Уничтожаем сессию
session_destroy();

// Удаляем куку "Запомнить меня", если есть
if (isset($_COOKIE['remember_user'])) {
    setcookie('remember_user', '', time() - 3600, '/');
}

// Перенаправляем на главную страницу
header('Location: /index.php');
exit;
?>