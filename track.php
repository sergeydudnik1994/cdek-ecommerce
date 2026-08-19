<?php
header('Content-Type: application/json');

// --- УЧЕТНЫЕ ДАННЫЕ ИЗ ПИСЬМА ОТ СДЭК ---
$cdek_client_id     = 'ВСТАВЬ_CLIENT_ID_ИЗ_ПИСЬМА';
$cdek_client_secret = 'ВСТАВЬ_CLIENT_SECRET_ИЗ_ПИСЬМА';
$is_test_mode       = false; // true — если тестовый контур, false — боевой

$auth_url = $is_test_mode ? 'https://api.edu.cdek.ru/v2/oauth/token?parameters' : 'https://api.cdek.ru/v2/oauth/token?parameters';
$api_url  = $is_test_mode ? 'https://api.edu.cdek.ru/v2/orders' : 'https://api.cdek.ru/v2/orders';

$track_number = isset($_GET['cdek_number']) ? trim($_GET['cdek_number']) : '';

if (empty($track_number)) {
    echo json_encode(['success' => false, 'message' => 'Введите номер накладной или заказа']);
    exit;
}

// 1. Получение OAuth Bearer токена
$ch_token = curl_init();
curl_setopt_array($ch_token, [
    CURLOPT_URL            => $auth_url,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => $cdek_client_id,
        'client_secret' => $cdek_client_secret
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => false
]);

$token_res = curl_exec($ch_token);
curl_close($ch_token);

$token_data = json_decode($token_res, true);
$access_token = $token_data['access_token'] ?? null;

if (!$access_token) {
    echo json_encode(['success' => false, 'message' => 'Не удалось авторизоваться в шлюзе API СДЭК']);
    exit;
}

// 2. Запрос информации по накладной
$ch_order = curl_init();
curl_setopt_array($ch_order, [
    CURLOPT_URL            => $api_url . '?cdek_number=' . urlencode($track_number),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ],
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => false
]);

$order_res = curl_exec($ch_order);
$http_code = curl_getinfo($ch_order, CURLINFO_HTTP_CODE);
curl_close($ch_order);

$order_data = json_decode($order_res, true);

if ($http_code === 200 && !empty($order_data['entity'])) {
    $entity = $order_data['entity'];
    
    // Формируем чистый и безопасный JSON для интерфейса
    $result = [
        'success'         => true,
        'cdek_number'     => $entity['cdek_number'] ?? $track_number,
        'city_from'       => $entity['from_location']['city'] ?? 'Город отправки',
        'city_to'         => $entity['to_location']['city'] ?? 'Город назначения',
        'status_name'     => $entity['statuses'][0]['name'] ?? 'В обработке',
        'status_code'     => $entity['statuses'][0]['code'] ?? '',
        'delivery_date'   => isset($entity['delivery_detail']['delivery_date']) ? date('d.m.Y', strtotime($entity['delivery_detail']['delivery_date'])) : null,
        'recipient_name'  => isset($entity['recipient']['name']) ? mb_substr($entity['recipient']['name'], 0, 1) . '.***' : 'Получатель',
        'delivery_point'  => $entity['delivery_point'] ?? '',
        'history'         => array_map(function($st) {
            return [
                'name' => $st['name'] ?? '',
                'date' => isset($st['date_time']) ? date('d.m.Y H:i', strtotime($st['date_time'])) : '',
                'city' => $st['city'] ?? ''
            ];
        }, $entity['statuses'] ?? [])
    ];
    echo json_encode($result);
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'Накладная с таким номером не найдена. Проверьте правильность ввода.'
    ]);
}
