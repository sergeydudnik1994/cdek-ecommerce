<?php
header('Content-Type: application/json; charset=utf-8');

// --- УЧЕТНЫЕ ДАННЫЕ СЕРВИСА TRACING 2.0 ---
$user_login = 'apiuser-cdek-ecommerce';
$raw_pass   = '60910f5286789ba520baad4a8137f6d3';

$auth_url    = 'https://auth.api.cdek.ru/web/simpleauth/authorize';
$tracing_url = 'https://tracing.api.cdek.ru/web/v2/order/find';

$track_number = isset($_GET['cdek_number']) ? trim($_GET['cdek_number']) : '';

if (empty($track_number)) {
    echo json_encode(['success' => false, 'message' => 'Введите номер накладной или заказа'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Проверяем локальный кэш токена
$cache_file = sys_get_temp_dir() . '/cdek_simpleauth_token.json';
$token = null;

if (file_exists($cache_file)) {
    $cached = json_decode(file_get_contents($cache_file), true);
    if (!empty($cached['token']) && !empty($cached['expires_at']) && $cached['expires_at'] > time()) {
        $token = $cached['token'];
    }
}

// 2. Функция авторизации в SimpleAuth
function authorizeCdek($auth_url, $user, $pass) {
    // В спецификации Tracing 2.0 требуется md5-хэш пароля в hex-формате
    $hashed_pass = (strlen($pass) === 32 && ctype_xdigit($pass)) ? $pass : md5($pass);

    $payload = json_encode([
        'user'       => $user,
        'hashedPass' => $hashed_pass
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $auth_url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json'
        ],
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

    // Если 32-значный пароль уже был исходным и хэш от хэша не подошел, пробуем md5($pass)
    if ($http_code !== 200 && $hashed_pass === $pass) {
        $payload_retry = json_encode([
            'user'       => $user,
            'hashedPass' => md5($pass)
        ], JSON_UNESCAPED_UNICODE);

        $ch_retry = curl_init();
        curl_setopt_array($ch_retry, [
            CURLOPT_URL            => $auth_url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload_retry,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res = curl_exec($ch_retry);
        $http_code = curl_getinfo($ch_retry, CURLINFO_HTTP_CODE);
        curl_close($ch_retry);
        $data = json_decode($res, true);
    }

    if ($http_code === 200 && !empty($data['token'])) {
        return $data['token'];
    }

    return null;
}

// Получаем токен, если нет в кэше
if (!$token) {
    $token = authorizeCdek($auth_url, $user_login, $raw_pass);
    if ($token) {
        file_put_contents($cache_file, json_encode([
            'token'      => $token,
            'expires_at' => time() + 3300 // кэшируем на 55 минут
        ]));
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Не удалось получить токен авторизации SimpleAuth СДЭК'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 3. Запрос данных отслеживания заказа в Tracing 2.0
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

// Если токен устарел (401/403), сбрасываем кэш и пробуем еще один раз
if ($http_code === 401 || $http_code === 403) {
    if (file_exists($cache_file)) @unlink($cache_file);
    $token = authorizeCdek($auth_url, $user_login, $raw_pass);
    if ($token) {
        file_put_contents($cache_file, json_encode(['token' => $token, 'expires_at' => time() + 3300]));
        list($http_code, $response) = fetchOrderTracing($tracing_url, $token, $track_number);
    }
}

// 4. Парсинг ответа Tracing 2.0
if ($http_code === 200 && !empty($response['result'])) {
    $resData = $response['result'];
    $order   = $resData['order'] ?? [];

    $city_from = $order['sender']['address']['city']['name'] ?? ($order['fromLocation']['city'] ?? 'Пункт отправки');
    $city_to   = $order['recipient']['address']['city']['name'] ?? ($order['toLocation']['city'] ?? 'Пункт назначения');

    // Текущий статус
    $status_groups = $resData['statusGroups'] ?? [];
    $statuses      = $resData['statuses'] ?? [];

    $status_name = 'В обработке';
    $status_code = '';

    if (!empty($status_groups)) {
        // Берем последний актуальный статус из групп
        $last_group = end($status_groups);
        $status_name = $last_group['name'] ?? $status_name;
        $status_code = $last_group['code'] ?? '';
    } elseif (!empty($statuses)) {
        $first_st = reset($statuses);
        $status_name = $first_st['name'] ?? $status_name;
        $status_code = $first_st['code'] ?? '';
    }

    // Формируем историю перемещений
    $history = [];
    if (!empty($statuses)) {
        foreach ($statuses as $st) {
            $dt = '';
            if (!empty($st['dateTime']) || !empty($st['timestamp']) || !empty($st['date'])) {
                $raw_date = $st['dateTime'] ?? ($st['timestamp'] ?? $st['date']);
                $timestamp = strtotime($raw_date);
                if ($timestamp) {
                    // Конвертируем UTC в МСК (+3 часа)
                    $dt = gmdate('d.m.Y H:i', $timestamp + 3 * 3600);
                }
            }

            $city_name = $st['currentCity']['name'] ?? ($st['city'] ?? '');

            $history[] = [
                'name' => $st['name'] ?? ($st['title'] ?? 'Статус обновлен'),
                'date' => $dt,
                'city' => $city_name
            ];
        }
    }

    // Форматируем дату планируемой доставки
    $delivery_date = null;
    if (!empty($resData['deliveryDetail']['deliveryDate'])) {
        $delivery_date = date('d.m.Y', strtotime($resData['deliveryDetail']['deliveryDate']));
    }

    $recipient_name = 'Клиент';
    if (!empty($order['recipient']['name'])) {
        $recipient_name = mb_substr($order['recipient']['name'], 0, 1) . '.***';
    }

    $point = $resData['warehouse']['address'] ?? ($order['recipient']['address']['line'] ?? '');

    echo json_encode([
        'success'        => true,
        'cdek_number'    => $order['number'] ?? $track_number,
        'city_from'      => $city_from,
        'city_to'        => $city_to,
        'status_name'    => $status_name,
        'status_code'    => $status_code,
        'delivery_date'  => $delivery_date,
        'recipient_name' => $recipient_name,
        'delivery_point' => $point,
        'history'        => $history
    ], JSON_UNESCAPED_UNICODE);

} else {
    $msg = 'Накладная с таким номером не найдена. Проверьте правильность ввода.';
    if (!empty($response['errors'][0]['message'])) {
        $msg = $response['errors'][0]['message'];
    } elseif (!empty($response['message'])) {
        $msg = $response['message'];
    }
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
}
