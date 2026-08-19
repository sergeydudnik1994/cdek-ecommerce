<?php
header('Content-Type: application/json; charset=utf-8');

$login    = 'apiuser-cdek-ecommerce';
$password = '60910f5286789ba520baad4a8137f6d3';

$track_number = isset($_GET['cdek_number']) ? trim($_GET['cdek_number']) : '';

if (empty($track_number)) {
    echo json_encode(['success' => false, 'message' => 'Введите номер накладной или заказа'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Функция запроса OAuth токена
function getCdekToken($baseUrl, $client_id, $client_secret) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $baseUrl . '/v2/oauth/token',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $client_id,
            'client_secret' => $client_secret
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        CURLOPT_USERAGENT      => 'CDEK-Ecommerce-Client/1.0',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($res, true);
    return ($code === 200 && !empty($json['access_token'])) ? $json['access_token'] : null;
}

// 1. Пробуем боевой контур, если нет — тестовый
$hosts = ['https://api.cdek.ru', 'https://api.edu.cdek.ru'];
$active_host = null;
$token = null;

foreach ($hosts as $host) {
    $token = getCdekToken($host, $login, $password);
    if ($token) {
        $active_host = $host;
        break;
    }
}

// 2. Если OAuth не подошел, пробуем прямой запрос через Tracing API (Basic Auth)
if (!$token) {
    $ch_direct = curl_init();
    curl_setopt_array($ch_direct, [
        CURLOPT_URL            => 'https://api.cdek.ru/v2/orders?cdek_number=' . urlencode($track_number),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => "$login:$password",
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'CDEK-Ecommerce-Client/1.0',
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $direct_res = curl_exec($ch_direct);
    $direct_code = curl_getinfo($ch_direct, CURLINFO_HTTP_CODE);
    curl_close($ch_direct);

    $direct_data = json_decode($direct_res, true);
    if ($direct_code === 200 && !empty($direct_data['entity'])) {
        renderOrder($direct_data['entity'], $track_number);
        exit;
    }

    echo json_encode([
        'success' => false,
        'message' => 'Не удалось авторизовать учетную запись в шлюзе СДЭК. Проверьте в инструкции тип авторизации (OAuth2 или Basic Auth).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. Запрос заказа через полученный OAuth токен
$ch_order = curl_init();
curl_setopt_array($ch_order, [
    CURLOPT_URL            => $active_host . '/v2/orders?cdek_number=' . urlencode($track_number),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json'
    ],
    CURLOPT_USERAGENT      => 'CDEK-Ecommerce-Client/1.0',
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => false
]);

$order_res = curl_exec($ch_order);
$http_code = curl_getinfo($ch_order, CURLINFO_HTTP_CODE);
curl_close($ch_order);

$order_data = json_decode($order_res, true);

if ($http_code === 200 && !empty($order_data['entity'])) {
    renderOrder($order_data['entity'], $track_number);
} else {
    $msg = $order_data['requests'][0]['errors'][0]['message'] ?? 'Накладная не найдена. Проверьте номер отправления.';
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
}

function renderOrder($entity, $track_number) {
    $statuses = $entity['statuses'] ?? [];
    echo json_encode([
        'success'        => true,
        'cdek_number'    => $entity['cdek_number'] ?? $track_number,
        'city_from'      => $entity['from_location']['city'] ?? 'Город отправки',
        'city_to'        => $entity['to_location']['city'] ?? 'Город назначения',
        'status_name'    => !empty($statuses) ? $statuses[0]['name'] : 'Принят к доставке',
        'status_code'    => !empty($statuses) ? $statuses[0]['code'] : '',
        'delivery_date'  => isset($entity['delivery_detail']['delivery_date']) ? date('d.m.Y', strtotime($entity['delivery_detail']['delivery_date'])) : null,
        'recipient_name' => isset($entity['recipient']['name']) ? mb_substr($entity['recipient']['name'], 0, 1) . '.***' : 'Получатель',
        'delivery_point' => $entity['delivery_point'] ?? ($entity['to_location']['address'] ?? ''),
        'history'        => array_map(function($st) {
            return [
                'name' => $st['name'] ?? '',
                'date' => isset($st['date_time']) ? date('d.m.Y H:i', strtotime($st['date_time'])) : '',
                'city' => $st['city'] ?? ''
            ];
        }, $statuses)
    ], JSON_UNESCAPED_UNICODE);
}
