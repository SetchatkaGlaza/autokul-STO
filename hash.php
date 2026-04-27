<?php
// hash.php - Генератор хеша пароля
$password = 'Password123321'; // Твой пароль
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
echo "Пароль: " . $password . "<br>";
echo "Хеш: " . $hash . "<br>";

// Проверка
if (password_verify($password, $hash)) {
    echo "✅ Хеш корректен! Можно использовать в SQL.";
} else {
    echo "❌ Ошибка хеширования!";
}
?>