<?php
header('Content-Type: application/json; charset=utf-8');

// --- УЧЕТНЫЕ ДАННЫЕ АПИ-TRACING СДЭК ---
$cdek_client_id     = 'apiuser-cdek-ecommerce';
$cdek_client_secret = '60910f5286789ba520baad4a8137f6d3';
$auth_url           = 'https://api.cdek.ru/v2/oauth/token';
$api_url            = 'https://api.cdek.ru/v2/orders';

$track_number = isset($_GET['cdek_number']) ? trim($_GET['cdek_number']) : '';

if (empty($track_number)) {
    echo json_encode(['success' => false, 'message' => 'Введите номер накладной или заказа'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Проверка кэшированного токена (действует 1 час, обновляем за 5 минут до истечения)
$token_cache_file = sys_get_temp_dir() . '/cdek_token_cache.json';
$access_token = null;

if (file_exists($token_cache_file)) {
    $cached = json_decode(file_get_contents($token_cache_file), true);
    if (!empty($cached['token']) && !empty($cached['expires_at']) && $cached['expires_at'] > time()) {
        $access_token = $cached['token'];
    }
}

// 2. Получение нового OAuth Bearer токена, если кэш пуст
if (!$access_token) {
    $ch_token = curl_init();
    curl_setopt_array($ch_token, [
        CURLOPT_URL            => $auth_url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $cdek_client_id,
            'client_secret' => $cdek_client_secret
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $token_res = curl_exec($ch_token);
    curl_close($ch_token);

    $token_data = json_decode($token_res, true);
    
    if (!empty($token_data['access_token'])) {
        $access_token = $token_data['access_token'];
        $expires_in = $token_data['expires_in'] ?? 3600;
        file_put_contents($token_cache_file, json_encode([
            'token' => $access_token,
            'expires_at' => time() + $expires_in - 300
        ]));
    } else {
        $err_msg = $token_data['error_description'] ?? 'Ошибка авторизации в шлюзе СДЭК';
        echo json_encode(['success' => false, 'message' => $err_msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 3. Запрос информации по накладной
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
    $statuses = $entity['statuses'] ?? [];

    $result = [
        'success'        => true,
        'cdek_number'    => $entity['cdek_number'] ?? $track_number,
        'city_from'      => $entity['from_location']['city'] ?? 'Пункт отправки',
        'city_to'        => $entity['to_location']['city'] ?? 'Пункт назначения',
        'status_name'    => !empty($statuses) ? $statuses[0]['name'] : 'Принят',
        'status_code'    => !empty($statuses) ? $statuses[0]['code'] : '',
        'delivery_date'  => isset($entity['delivery_detail']['delivery_date']) ? date('d.m.Y', strtotime($entity['delivery_detail']['delivery_date'])) : null,
        'recipient_name' => isset($entity['recipient']['name']) ? mb_substr($entity['recipient']['name'], 0, 1) . '.***' : 'Клиент',
        'delivery_point' => $entity['delivery_point'] ?? ($entity['to_location']['address'] ?? ''),
        'history'        => array_map(function($st) {
            return [
                'name' => $st['name'] ?? '',
                'date' => isset($st['date_time']) ? date('d.m.Y H:i', strtotime($st['date_time'])) : '',
                'city' => $st['city'] ?? ''
            ];
        }, $statuses)
    ];
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} else {
    $msg = $order_data['requests'][0]['errors'][0]['message'] ?? 'Накладная не найдена в базе СДЭК. Проверьте правильность номера.';
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
}
