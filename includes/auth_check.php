<?php
// includes/auth_check.php
// Проверка авторизации пользователя

// Запускаем сессию, если ещё не запущена
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Проверяет, авторизован ли пользователь.
 * Если нет — перенаправляет на страницу входа.
 * 
 * @param string|null $required_role Если указана роль ('admin', 'mechanic', 'client'),
 *                                   то проверяется ещё и соответствие роли
 */
function requireAuth($required_role = null) {
    // Проверяем, есть ли в сессии ID пользователя
    if (!isset($_SESSION['user_id'])) {
        // Сохраняем URL, куда хотел попасть пользователь
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        
        // Перенаправляем на страницу входа
        header('Location: /login.php');
        exit;
    }
    
    // Если нужна проверка роли
    if ($required_role !== null) {
        // Админ имеет доступ ко всему
        if ($_SESSION['user_role'] === 'admin') {
            return true;
        }
        
        // Проверяем конкретную роль
        if ($_SESSION['user_role'] !== $required_role) {
            // Доступ запрещён
            header('HTTP/1.0 403 Forbidden');
            die('Доступ запрещён. У вас недостаточно прав для просмотра этой страницы.');
        }
    }
    
    return true;
}

/**
 * Проверяет, авторизован ли пользователь (без редиректа).
 * Удобно использовать в шапке для отображения кнопок "Войти" / "Профиль".
 * 
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Проверяет роль текущего пользователя.
 * 
 * @param string $role Роль для проверки
 * @return bool
 */
function hasRole($role) {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === $role;
}

/**
 * Возвращает ID текущего пользователя или null.
 * 
 * @return int|null
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Возвращает роль текущего пользователя или null.
 * 
 * @return string|null
 */
function getCurrentUserRole() {
    return $_SESSION['user_role'] ?? null;
}
?>