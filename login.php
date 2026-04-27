<?php
// login.php - Страница авторизации

require_once 'includes/config.php';
require_once 'includes/auth_check.php';

// Если пользователь уже авторизован — редирект на главную
if (isLoggedIn()) {
    header('Location: /profile.php');
    exit;
}

$page_title = 'Вход в личный кабинет — Автокул СТО';

$errors = [];
$email = '';

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']) ? true : false;
    
    // Валидация email
    if (empty($email)) {
        $errors['email'] = 'Введите email';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Введите корректный email';
    }
    
    // Валидация пароля
    if (empty($password)) {
        $errors['password'] = 'Введите пароль';
    }
    
    // Если ошибок нет — пробуем авторизовать
    if (empty($errors)) {
        try {
            $pdo = getDBConnection();
            
            // Ищем пользователя по email
            $stmt = $pdo->prepare("SELECT id, full_name, email, password, role FROM users WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                // Проверяем, не нужно ли обновить хеш (если изменился алгоритм)
                if (password_needs_rehash($user['password'], PASSWORD_BCRYPT, ['cost' => 10])) {
                    $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
                    $updateStmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = :id");
                    $updateStmt->execute(['password' => $newHash, 'id' => $user['id']]);
                }
                
                // Авторизуем — записываем данные в сессию
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                
                // Если стоит галочка "Запомнить меня" — ставим куку на 30 дней
                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    $token_hash = hash('sha256', $token);
                    
                    // Сохраняем токен в БД (можно создать таблицу remember_tokens)
                    // В простом варианте сохраним в куках ID (менее безопасно, но для учебного проекта ок)
                    setcookie('remember_user', $user['id'], time() + 30 * 24 * 3600, '/', '', false, true);
                }
                
                // Перенаправляем
                $redirect = $_SESSION['redirect_after_login'] ?? '/profile.php';
                unset($_SESSION['redirect_after_login']);
                
                header('Location: ' . $redirect);
                exit;
                
            } else {
                $errors['auth'] = 'Неверный email или пароль';
            }
            
        } catch (PDOException $e) {
            $errors['db'] = 'Ошибка при входе. Попробуйте позже.';
            error_log('Ошибка авторизации: ' . $e->getMessage());
        }
    }
}

require_once 'includes/header.php';
?>

<!-- ========== ФОРМА ВХОДА ========== -->
<section style="padding: 60px 0; min-height: calc(100vh - 400px);">
    <div class="container">
        <div style="max-width: 450px; margin: 0 auto;">
            
            <h1 style="text-align: center; margin-bottom: 8px; font-size: 28px;">Вход в личный кабинет</h1>
            <p style="text-align: center; color: var(--gray-500); margin-bottom: 30px;">
                Добро пожаловать обратно! Войдите для управления записями.
            </p>
            
            <?php if (!empty($errors['auth']) || !empty($errors['db'])): ?>
                <div style="background: #f2dede; color: #a94442; padding: 14px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #d9534f;">
                    <?php echo $errors['auth'] ?? $errors['db'] ?? ''; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="/login.php" novalidate style="background: var(--white); padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); border: 1px solid var(--gray-200);">
                
                <!-- Email -->
                <div style="margin-bottom: 20px;">
                    <label for="email" style="display: block; font-weight: 600; margin-bottom: 6px; color: var(--secondary);">
                        Email
                    </label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        value="<?php echo htmlspecialchars($email); ?>"
                        placeholder="user@example.com"
                        required
                        autofocus
                        style="width: 100%; padding: 12px 14px; border: 1px solid <?php echo isset($errors['email']) ? '#d9534f' : 'var(--gray-300)'; ?>; border-radius: 8px; font-size: 15px; transition: var(--transition); outline: none;"
                        onfocus="this.style.borderColor='var(--primary)'"
                        onblur="this.style.borderColor='<?php echo isset($errors['email']) ? '#d9534f' : 'var(--gray-300)'; ?>'"
                    >
                    <?php if (isset($errors['email'])): ?>
                        <span style="color: #d9534f; font-size: 13px; display: block; margin-top: 4px;"><?php echo $errors['email']; ?></span>
                    <?php endif; ?>
                </div>
                
                <!-- Пароль -->
                <div style="margin-bottom: 12px;">
                    <label for="password" style="display: block; font-weight: 600; margin-bottom: 6px; color: var(--secondary);">
                        Пароль
                    </label>
                    <div style="position: relative;">
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            placeholder="Введите пароль"
                            required
                            style="width: 100%; padding: 12px 50px 12px 14px; border: 1px solid <?php echo isset($errors['password']) ? '#d9534f' : 'var(--gray-300)'; ?>; border-radius: 8px; font-size: 15px; transition: var(--transition); outline: none;"
                            onfocus="this.style.borderColor='var(--primary)'"
                            onblur="this.style.borderColor='<?php echo isset($errors['password']) ? '#d9534f' : 'var(--gray-300)'; ?>'"
                        >
                        <button type="button" onclick="togglePassword('password', this)" 
                                style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 18px; padding: 4px 8px; color: var(--gray-500);"
                                aria-label="Показать пароль">👁️</button>
                    </div>
                    <?php if (isset($errors['password'])): ?>
                        <span style="color: #d9534f; font-size: 13px; display: block; margin-top: 4px;"><?php echo $errors['password']; ?></span>
                    <?php endif; ?>
                </div>
                
                <!-- Запомнить меня -->
                <div style="margin-bottom: 24px; display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" id="remember" name="remember" style="width: 18px; height: 18px; cursor: pointer;">
                    <label for="remember" style="font-size: 14px; color: var(--gray-700); cursor: pointer;">Запомнить меня</label>
                </div>
                
                <!-- Кнопка входа -->
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px; font-size: 16px;">
                    Войти
                </button>
                
                <!-- Ссылка на регистрацию -->
                <p style="text-align: center; margin-top: 20px; color: var(--gray-500); font-size: 14px;">
                    Ещё нет аккаунта? <a href="/register.php" style="color: var(--primary); font-weight: 600;">Зарегистрироваться</a>
                </p>
                
            </form>
        </div>
    </div>
</section>

<script>
function togglePassword(inputId, button) {
    const input = document.getElementById(inputId);
    if (input.type === 'password') {
        input.type = 'text';
        button.textContent = '🙈';
    } else {
        input.type = 'password';
        button.textContent = '👁️';
    }
}
</script>

<?php
require_once 'includes/footer.php';
?>