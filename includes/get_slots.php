<?php
// includes/get_slots.php
// AJAX-обработчик: возвращает доступные слоты времени на выбранную дату

require_once __DIR__ . '/config.php';

// Проверяем, что запрос пришёл через AJAX
if (!isset($_GET['date']) || !isset($_GET['duration'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Не указаны обязательные параметры']);
    exit;
}

$date = $_GET['date'];
$total_duration = intval($_GET['duration']);

// Проверяем формат даты
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'Неверный формат даты']);
    exit;
}

// Проверяем, что дата не в прошлом
if (strtotime($date) < strtotime(date('Y-m-d'))) {
    echo json_encode(['slots' => [], 'message' => 'Нельзя записаться на прошедшую дату']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // 1. Определяем день недели (1=Пн, ..., 7=Вс)
    $day_of_week = date('N', strtotime($date));
    
    // 2. Получаем график работы на этот день
    $stmt = $pdo->prepare("SELECT start_time, end_time, slot_duration FROM work_schedule WHERE day_of_week = :day");
    $stmt->execute(['day' => $day_of_week]);
    $schedule = $stmt->fetch();
    
    // Если расписание на день не заведено, используем стандартный ежедневный график
    if (!$schedule) {
        $schedule = [
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'slot_duration' => 60
        ];
    }
    
    // 3. Получаем все занятые записи на эту дату
    $stmt = $pdo->prepare("
        SELECT a.appointment_time,
               SUM(s.duration) AS total_booked_duration
        FROM appointments a
        JOIN appointment_services aps ON a.id = aps.appointment_id
        JOIN services s ON aps.service_id = s.id
        WHERE a.appointment_date = :date
          AND a.status NOT IN ('cancelled')
        GROUP BY a.id, a.appointment_time
    ");
    $stmt->execute(['date' => $date]);
    $booked_slots = $stmt->fetchAll();
    
    // 4. Генерируем все возможные слоты
    $slot_duration = intval($schedule['slot_duration']); // Обычно 60 минут
    $start_time = strtotime($schedule['start_time']);
    $end_time = strtotime($schedule['end_time']);
    
    $all_slots = [];
    $current = $start_time;
    
    while ($current + ($total_duration * 60) <= $end_time) {
        $time_str = date('H:i', $current);
        $all_slots[] = [
            'time' => $time_str,
            'end_time' => date('H:i', $current + ($total_duration * 60)),
            'available' => true
        ];
        $current += $slot_duration * 60;
    }
    
    // 5. Проверяем каждый слот на пересечение с занятыми записями
    foreach ($all_slots as &$slot) {
        $slot_start = strtotime($date . ' ' . $slot['time']);
        $slot_end = strtotime($date . ' ' . $slot['end_time']);
        
        foreach ($booked_slots as $booked) {
            $booked_start = strtotime($date . ' ' . $booked['appointment_time']);
            $booked_end = $booked_start + ($booked['total_booked_duration'] * 60);
            
            // Проверяем пересечение интервалов
            if ($slot_start < $booked_end && $slot_end > $booked_start) {
                $slot['available'] = false;
                break;
            }
        }
    }
    
    // Фильтруем только доступные (но возвращаем все для отладки)
    $available_slots = array_values(array_filter($all_slots, function($s) {
        return $s['available'] === true;
    }));
    
    echo json_encode([
        'slots' => $available_slots,
        'all_slots' => $all_slots,
        'message' => count($available_slots) > 0 ? 'Доступно слотов: ' . count($available_slots) : 'На эту дату нет свободного времени'
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка сервера']);
    error_log('Ошибка в get_slots.php: ' . $e->getMessage());
}
?>