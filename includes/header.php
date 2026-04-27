<?php
// includes/header.php - Общая шапка сайта (официальный стиль)
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/avatar.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Автокул СТО — профессиональный ремонт и техническое обслуживание автомобилей в Вологде. Онлайн-запись, диагностика, ремонт.">
    <meta name="keywords" content="автосервис, СТО, ремонт автомобилей, техобслуживание, диагностика, Вологда">
    <title><?php echo $page_title ?? 'Автокул СТО — Ремонт и обслуживание автомобилей'; ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        /* ==========================================
           СТИЛИ ШАПКИ (ОФИЦИАЛЬНЫЙ ДЕЛОВОЙ СТИЛЬ)
           ========================================== */
        
        .site-header {
            background: #ffffff;
            border-bottom: 1px solid #e8eaed;
            position: sticky;
            top: 0;
            z-index: 1000;
            height: 72px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .header-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* ========== ЛОГОТИП ========== */
        .header-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            flex-shrink: 0;
        }

        .header-logo-icon {
            width: 42px;
            height: 42px;
            background: #1a1a1a;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .header-logo-icon::before {
            content: '';
            width: 22px;
            height: 14px;
            background: #cc3333;
            border-radius: 3px;
            position: absolute;
        }

        .header-logo-icon::after {
            content: '';
            width: 10px;
            height: 10px;
            background: #ffffff;
            border-radius: 50%;
            position: absolute;
            top: 7px;
            left: 8px;
        }

        .header-logo-text {
            display: flex;
            flex-direction: column;
            line-height: 1.1;
        }

        .header-logo-name {
            font-size: 21px;
            font-weight: 700;
            color: #1a1a1a;
            letter-spacing: -0.3px;
        }

        .header-logo-name span {
            color: #cc3333;
        }

        .header-logo-sub {
            font-size: 10px;
            color: #777;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            font-weight: 500;
        }

        /* ========== НАВИГАЦИЯ ========== */
        .header-nav {
            display: flex;
            align-items: center;
            gap: 2px;
        }

        .header-nav-link {
            padding: 10px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            color: #444;
            text-decoration: none;
            transition: all 0.2s ease;
            position: relative;
            white-space: nowrap;
        }

        .header-nav-link:hover {
            color: #1a1a1a;
            background: #f5f5f5;
        }

        .header-nav-link.active {
            color: #cc3333;
            background: #fef5f5;
        }

        .header-nav-link.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 16px;
            right: 16px;
            height: 2px;
            background: #cc3333;
            border-radius: 1px;
        }

        /* ========== ПРАВАЯ ЧАСТЬ ========== */
        .header-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        /* Телефон */
        .header-phone {
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            color: #1a1a1a;
            font-weight: 600;
            font-size: 15px;
            white-space: nowrap;
            padding: 6px 0;
        }

        .header-phone:hover {
            color: #cc3333;
        }

        .header-phone-icon {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .header-phone-icon svg {
            width: 16px;
            height: 16px;
            fill: #cc3333;
        }

        /* Кнопки авторизации */
        .header-auth {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
            white-space: nowrap;
            letter-spacing: 0.2px;
        }

        .btn-outline-dark {
            background: transparent;
            color: #444;
            border: 1.5px solid #ccc;
        }

        .btn-outline-dark:hover {
            border-color: #1a1a1a;
            color: #1a1a1a;
            background: #fafafa;
        }

        .btn-accent {
            background: #cc3333;
            color: #ffffff;
            border: 1.5px solid #cc3333;
        }

        .btn-accent:hover {
            background: #b22d2d;
            border-color: #b22d2d;
            box-shadow: 0 2px 8px rgba(204, 51, 51, 0.25);
        }

        .btn-sm {
            padding: 7px 14px;
            font-size: 12px;
        }

        /* Профиль пользователя */
        .header-user {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #1a1a1a;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .header-user-avatar:hover {
            background: #cc3333;
        }

        .header-user-name {
            font-size: 13px;
            font-weight: 600;
            color: #1a1a1a;
            text-decoration: none;
        }

        .header-user-name:hover {
            color: #cc3333;
        }

        .header-logout {
            font-size: 13px;
            color: #888;
            text-decoration: none;
            padding: 4px 8px;
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .header-logout:hover {
            color: #cc3333;
            background: #fef5f5;
        }

        .header-admin-link {
            font-size: 12px;
            color: #cc3333;
            text-decoration: none;
            padding: 5px 10px;
            border: 1px solid #f5d5d5;
            border-radius: 4px;
            transition: all 0.2s ease;
            font-weight: 600;
        }

        .header-admin-link:hover {
            background: #cc3333;
            color: #ffffff;
            border-color: #cc3333;
        }

        /* Мобильное меню */
        .header-mobile-toggle {
            display: none;
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            border-radius: 6px;
        }

        .header-mobile-toggle:hover {
            background: #f5f5f5;
        }

        .header-mobile-toggle span {
            display: block;
            width: 22px;
            height: 2px;
            background: #1a1a1a;
            margin: 5px 0;
            border-radius: 1px;
            transition: all 0.3s ease;
        }

        /* ========== АДАПТИВНОСТЬ ========== */
        @media (max-width: 1024px) {
            .header-phone {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .site-header {
                height: 60px;
            }

            .header-nav {
                display: none;
                position: absolute;
                top: 60px;
                left: 0;
                right: 0;
                background: #ffffff;
                flex-direction: column;
                padding: 8px 16px;
                border-bottom: 2px solid #e8eaed;
                box-shadow: 0 8px 20px rgba(0,0,0,0.08);
                gap: 2px;
            }

            .header-nav.open {
                display: flex;
            }

            .header-nav-link {
                padding: 12px 16px;
                border-radius: 6px;
            }

            .header-nav-link.active::after {
                display: none;
            }

            .header-nav-link.active {
                border-left: 3px solid #cc3333;
                padding-left: 13px;
            }

            .header-mobile-toggle {
                display: block;
            }

            .header-auth .btn {
                padding: 8px 14px;
                font-size: 12px;
            }

            .header-user-name {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .header-logo-name {
                font-size: 17px;
            }

            .header-logo-icon {
                width: 34px;
                height: 34px;
            }

            .header-auth {
                gap: 4px;
            }

            .header-auth .btn {
                padding: 6px 10px;
                font-size: 11px;
            }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="header-container">
            
            <!-- Логотип -->
            <a href="/index.php" class="header-logo">
                <div class="header-logo-icon"></div>
                <div class="header-logo-text">
                    <span class="header-logo-name">Автокул <span>СТО</span></span>
                    <span class="header-logo-sub">Автосервис в Вологде</span>
                </div>
            </a>

            <!-- Навигация -->
            <nav class="header-nav" id="mainNav">
                <a href="/index.php" class="header-nav-link <?php echo $current_page === 'index' ? 'active' : ''; ?>">
                    Главная
                </a>
                <a href="/services.php" class="header-nav-link <?php echo $current_page === 'services' ? 'active' : ''; ?>">
                    Услуги и цены
                </a>
                <a href="/appointment.php" class="header-nav-link <?php echo $current_page === 'appointment' ? 'active' : ''; ?>">
                    Онлайн-запись
                </a>
                <a href="/reviews.php" class="header-nav-link <?php echo $current_page === 'reviews' ? 'active' : ''; ?>">
                    Отзывы
                </a>
                <a href="/contacts.php" class="header-nav-link <?php echo $current_page === 'contacts' ? 'active' : ''; ?>">
                    Контакты
                </a>
            </nav>

            <!-- Правая часть -->
            <div class="header-right">
                
                <!-- Телефон -->
                <a href="tel:+79001234567" class="header-phone">
                    <span class="header-phone-icon">
                        <svg viewBox="0 0 24 24">
                            <path d="M6.62 10.79a15.05 15.05 0 006.59 6.59l2.2-2.2a1 1 0 011.01-.24 11.36 11.36 0 003.58.57 1 1 0 011 1V20a1 1 0 01-1 1A17 17 0 013 4a1 1 0 011-1h3.5a1 1 0 011 1 11.36 11.36 0 00.57 3.58 1 1 0 01-.25 1.01l-2.2 2.2z"/>
                        </svg>
                    </span>
                    +7 (900) 123-45-67
                </a>
                
                <!-- Авторизация -->
                <div class="header-auth">
                    <?php if (isLoggedIn()): ?>
    <?php 
        // Получаем аватар пользователя из сессии или БД
        $header_avatar = $_SESSION['user_avatar'] ?? null;
        if (!$header_avatar) {
            // Загружаем из БД если нет в сессии
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = :id");
            $stmt->execute(['id' => $_SESSION['user_id']]);
            $header_avatar = $stmt->fetchColumn();
            $_SESSION['user_avatar'] = $header_avatar;
        }
        $avatar_url = getAvatarUrl($header_avatar);
    ?>
    <a href="/profile.php" class="header-user-avatar" title="Личный кабинет" style="background: none; padding: 0; width: 36px; height: 36px;">
        <img src="<?php echo htmlspecialchars($avatar_url); ?>" 
             alt="<?php echo htmlspecialchars($_SESSION['user_name']); ?>" 
             width="36" height="36"
             style="width: 36px; height: 36px; object-fit: cover; border-radius: 50%; border: 2px solid #e0e0e0;">
    </a>
    <a href="/profile.php" class="header-user-name">
        <?php echo htmlspecialchars(mb_substr($_SESSION['user_name'], 0, 15)); ?>
    </a>
    
    <?php if (hasRole('admin') || hasRole('mechanic')): ?>
        <a href="/admin/" class="header-admin-link">Управление</a>
    <?php endif; ?>
    
    <a href="/logout.php" class="header-logout">Выйти</a>
    
<?php else: ?>
                        <a href="/login.php" class="btn btn-outline-dark">Войти</a>
                        <a href="/register.php" class="btn btn-accent">Регистрация</a>
                    <?php endif; ?>
                </div>

                <!-- Мобильное меню -->
                <button class="header-mobile-toggle" id="mobileMenuToggle" aria-label="Открыть меню">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>
        </div>
    </header>

    <main>
        <script>
            // Мобильное меню
            document.getElementById('mobileMenuToggle').addEventListener('click', function() {
                document.getElementById('mainNav').classList.toggle('open');
            });

            // Закрытие меню при клике на ссылку
            document.querySelectorAll('#mainNav .header-nav-link').forEach(link => {
                link.addEventListener('click', () => {
                    document.getElementById('mainNav').classList.remove('open');
                });
            });

            // Закрытие меню при клике вне его
            document.addEventListener('click', function(e) {
                const nav = document.getElementById('mainNav');
                const toggle = document.getElementById('mobileMenuToggle');
                if (!nav.contains(e.target) && !toggle.contains(e.target)) {
                    nav.classList.remove('open');
                }
            });
        </script>