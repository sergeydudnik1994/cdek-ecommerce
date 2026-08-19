<?php
header('Content-Type: application/json; charset=utf-8');

$user_login = 'apiuser-cdek-ecommerce';
$raw_pass   = '60910f5286789ba520baad4a8137f6d3';

$auth_url    = 'https://auth.api.cdek.ru/web/simpleauth/authorize';
$tracing_url = 'https://tracing.api.cdek.ru/web/v2/order/find';

$track_number = isset($_GET['cdek_number']) ? trim($_GET['cdek_number']) : '';

if (empty($track_number)) {
    echo json_encode(['success' => false, 'message' => 'Введите номер накладной или заказа'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Проверка кэша токена
$cache_file = sys_get_temp_dir() . '/cdek_simpleauth_token.json';
$token = null;

if (file_exists($cache_file)) {
    $cached = json_decode(file_get_contents($cache_file), true);
    if (!empty($cached['token']) && !empty($cached['expires_at']) && $cached['expires_at'] > time()) {
        $token = $cached['token'];
    }
}

function authorizeCdek($auth_url, $user, $pass) {
    $hashed_pass = (strlen($pass) === 32 && ctype_xdigit($pass)) ? $pass : md5($pass);
    $payload = json_encode(['user' => $user, 'hashedPass' => $hashed_pass], JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $auth_url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_USERAGENT      => 'CDEK-Ecommerce-Tracing/2.0',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($res, true);
    return ($http_code === 200 && !empty($data['token'])) ? $data['token'] : null;
}

if (!$token) {
    $token = authorizeCdek($auth_url, $user_login, $raw_pass);
    if ($token) {
        file_put_contents($cache_file, json_encode(['token' => $token, 'expires_at' => time() + 3300]));
    } else {
        echo json_encode(['success' => false, 'message' => 'Ошибка авторизации в шлюзе СДЭК'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 2. Запрос трекинга
function fetchOrderTracing($tracing_url, $token, $track_number) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $tracing_url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['orderNumber' => $track_number]),
        CURLOPT_HTTPHEADER     => [
            'X-Auth-Token: ' . $token,
            'X-User-Lang: rus',
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_USERAGENT      => 'CDEK-Ecommerce-Tracing/2.0',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$http_code, json_decode($res, true)];
}

list($http_code, $response) = fetchOrderTracing($tracing_url, $token, $track_number);

if ($http_code === 401 || $http_code === 403) {
    if (file_exists($cache_file)) @unlink($cache_file);
    $token = authorizeCdek($auth_url, $user_login, $raw_pass);
    if ($token) {
        file_put_contents($cache_file, json_encode(['token' => $token, 'expires_at' => time() + 3300]));
        list($http_code, $response) = fetchOrderTracing($tracing_url, $token, $track_number);
    }
}

// 3. Обработка данных
if ($http_code === 200 && !empty($response['result'])) {
    $resData = $response['result'];
    $order   = $resData['order'] ?? [];

    $city_from = $order['sender']['address']['city']['name'] 
              ?? $order['fromLocation']['city'] 
              ?? 'Пункт отправления';

    $city_to = $order['recipient']['address']['city']['name'] 
            ?? $order['toLocation']['city'] 
            ?? $resData['warehouse']['city'] 
            ?? $resData['deliveryDetail']['deliveryPoint']['city']
            ?? 'Пункт назначения';

    // Обработка групп статусов (этапов)
    $status_groups_raw = $resData['statusGroups'] ?? [];
    $active_status_name = 'Создан';
    $active_status_code = 'CREATED';
    $progress_percent = 25;

    $groups_formatted = [];
    foreach ($status_groups_raw as $g) {
        $is_future = !empty($g['future']);
        $dt = '';
        if (!empty($g['timestamp'])) {
            $dt = gmdate('d.m.Y', strtotime($g['timestamp']) + 3 * 3600);
        }

        if (!$is_future) {
            $active_status_name = $g['name'] ?? $active_status_name;
            $active_status_code = $g['code'] ?? $active_status_code;
        }

        $groups_formatted[] = [
            'code'      => $g['code'] ?? '',
            'name'      => $g['name'] ?? '',
            'future'    => $is_future,
            'date'      => $dt,
            'comment'   => $g['comment'] ?? ''
        ];
    }

    if ($active_status_code === 'CREATED') $progress_percent = 15;
    elseif ($active_status_code === 'IN_PROGRESS' || $active_status_code === 'COURIER_IN_PROGRESS') $progress_percent = 55;
    elseif ($active_status_code === 'READY_FOR_PICK_UP') $progress_percent = 85;
    elseif ($active_status_code === 'DELIVERED') $progress_percent = 100;

    // Детальная история
    $sub_statuses = [];
    if (!empty($resData['statuses'])) {
        foreach ($resData['statuses'] as $st) {
            $dt = '';
            if (!empty($st['dateTime']) || !empty($st['timestamp'])) {
                $raw = $st['dateTime'] ?? $st['timestamp'];
                $dt = gmdate('d.m.Y H:i', strtotime($raw) + 3 * 3600);
            }
            $sub_statuses[] = [
                'name' => $st['name'] ?? ($st['title'] ?? 'Обновление статуса'),
                'date' => $dt,
                'city' => $st['currentCity']['name'] ?? ($st['city'] ?? '')
            ];
        }
    }

    // Даты доставки
    $delivery_date = null;
    if (!empty($resData['deliveryDetail']['deliveryDate'])) {
        $delivery_date = gmdate('d августа', strtotime($resData['deliveryDetail']['deliveryDate']) + 3 * 3600);
    }
    $initial_date = null;
    if (!empty($resData['deliveryDetail']['initialDeliveryDate'])) {
        $initial_date = gmdate('d.m.Y', strtotime($resData['deliveryDetail']['initialDeliveryDate']) + 3 * 3600);
    }

    // Получатель и адрес ПВЗ
    $recipient = $order['recipient']['name'] ?? ($resData['deliveryDetail']['recipientName'] ?? 'Получатель');
    $parts = explode(' ', trim($recipient));
    if (count($parts) >= 2) {
        $recipient = $parts[0] . ' ' . mb_substr($parts[1], 0, 1) . '.' . (isset($parts[2]) ? mb_substr($parts[2], 0, 1) . '.' : '');
    }

    $pvz_address = $resData['warehouse']['address'] 
                ?? $resData['deliveryDetail']['deliveryPoint']['address'] 
                ?? $order['recipient']['address']['line'] 
                ?? ($order['recipient']['address']['formatted'] ?? 'Уточняется');

    echo json_encode([
        'success'            => true,
        'cdek_number'        => $order['number'] ?? $track_number,
        'city_from'          => $city_from,
        'city_to'            => $city_to,
        'status_name'        => $active_status_name,
        'status_code'        => $active_status_code,
        'progress_percent'   => $progress_percent,
        'groups'             => $groups_formatted,
        'sub_statuses'       => $sub_statuses,
        'delivery_date'      => $delivery_date,
        'initial_date'       => $initial_date,
        'recipient_name'     => $recipient,
        'pvz_address'        => $pvz_address
    ], JSON_UNESCAPED_UNICODE);

} else {
    $msg = $response['errors'][0]['message'] ?? ($response['message'] ?? 'Накладная не найдена. Проверьте правильность номера.');
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
}
