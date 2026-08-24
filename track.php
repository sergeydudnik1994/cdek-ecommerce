<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$user_login = 'apiuser-cdek-ecommerce';
$raw_pass   = '60910f5286789ba520baad4a8137f6d3';

$auth_url    = 'https://auth.api.cdek.ru/web/simpleauth/authorize';
$tracing_url = 'https://tracing.api.cdek.ru/web/v2/order/find';

$track_number = isset($_GET['cdek_number']) ? trim($_GET['cdek_number']) : '';
$is_debug     = isset($_GET['debug']) && $_GET['debug'] == '1';

if (empty($track_number)) {
    echo json_encode(['success' => false, 'message' => 'Введите номер накладной или заказа'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Проверка кэша токена SimpleAuth
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

// 2. Запрос отслеживания
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

if ($is_debug) {
    echo json_encode(['http_code' => $http_code, 'raw_response' => $response], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 3. Функции точного извлечения
function extractCity($obj) {
    if (empty($obj)) return null;
    if (is_string($obj)) return trim($obj);
    if (is_array($obj)) {
        if (!empty($obj['city'])) {
            $res = extractCity($obj['city']);
            if ($res) return $res;
        }
        if (!empty($obj['cityName']) && is_string($obj['cityName'])) {
            return trim($obj['cityName']);
        }
        if (!empty($obj['address']) && is_array($obj['address'])) {
            $res = extractCity($obj['address']);
            if ($res) return $res;
        }
        if (!empty($obj['deliveryPoint']) && is_array($obj['deliveryPoint'])) {
            $res = extractCity($obj['deliveryPoint']);
            if ($res) return $res;
        }
        if (!empty($obj['location']) && is_array($obj['location'])) {
            $res = extractCity($obj['location']);
            if ($res) return $res;
        }
        if (!empty($obj['name']) && is_string($obj['name'])) {
            $keys = array_keys($obj);
            $hasPersonKeys = false;
            foreach ($keys as $k) {
                if (in_array(strtolower($k), ['fio', 'surname', 'phone', 'email', 'recipient', 'sender'], true)) {
                    $hasPersonKeys = true;
                    break;
                }
            }
            if (!$hasPersonKeys) return trim($obj['name']);
        }
    }
    return null;
}

function searchAddressDeep($data) {
    if (empty($data)) return null;

    // Регулярное выражение поиска паттерна адреса (ул, дом, пр-т, шоссе и т.д.)
    $pattern = '/(?:\b(?:ул|улица|просп|проспект|проезд|пер|переулок|шоссе|ш|наб|набережная|тракт|бульвар|б-р|пл|площадь|кв-л|мкр|микрорайон)\b[\.\s]|(?:д\.|дом)\s*\d+)/ui';

    if (is_string($data)) {
        $val = trim($data);
        if (mb_strlen($val) > 6 && preg_match($pattern, $val)) {
            return $val;
        }
        return null;
    }

    if (is_array($data)) {
        // Проверка составного адреса (street + house)
        if (!empty($data['street']) || (!empty($data['city']) && !empty($data['house']))) {
            $parts = [];
            if (!empty($data['city']) && is_string($data['city'])) $parts[] = 'г. ' . trim($data['city']);
            if (!empty($data['street']) && is_string($data['street'])) $parts[] = 'ул. ' . trim($data['street']);
            if (!empty($data['house']) && is_string($data['house'])) $parts[] = 'д. ' . trim($data['house']);
            if (!empty($data['flat']) && is_string($data['flat'])) $parts[] = 'оф./кв. ' . trim($data['flat']);
            if (count($parts) >= 2) return implode(', ', $parts);
        }

        // Проверка именованных полей адреса
        foreach (['fullAddress', 'formattedAddress', 'address', 'rawAddress', 'name', 'title', 'warehouse', 'office'] as $key) {
            if (!empty($data[$key])) {
                $res = searchAddressDeep($data[$key]);
                if ($res) return $res;
            }
        }

        // Рекурсивный обход всех остальных полей (исключая отправителя)
        foreach ($data as $k => $v) {
            $lower = strtolower((string)$k);
            if (in_array($lower, ['sender', 'fromlocation', 'from_location', 'senderlocation'], true)) {
                continue;
            }
            if (is_array($v)) {
                $res = searchAddressDeep($v);
                if ($res) return $res;
            }
        }
    }
    return null;
}

function extractPvzAddressUniversal($resData, $cityTo = '') {
    if (empty($resData) || !is_array($resData)) return null;

    // 1. Поиск в деталях доставки
    if (!empty($resData['deliveryDetail'])) {
        $addr = searchAddressDeep($resData['deliveryDetail']);
        if ($addr) return $addr;
    }

    // 2. Поиск в статусах (часто адрес ПВЗ пишется в статусе "Готов к выдаче" или "Принят в офисе")
    if (!empty($resData['statuses']) && is_array($resData['statuses'])) {
        for ($i = count($resData['statuses']) - 1; $i >= 0; $i--) {
            $st = $resData['statuses'][$i];
            $addr = searchAddressDeep($st);
            if ($addr) return $addr;
        }
    }

    // 3. Поиск в складах и объекте заказа
    foreach (['warehouse', 'toWarehouse', 'order', 'toLocation'] as $sec) {
        if (!empty($resData[$sec])) {
            $addr = searchAddressDeep($resData[$sec]);
            if ($addr) return $addr;
        }
    }

    // 4. Проверка кодов ПВЗ (если текстовый адрес отсутствует)
    $pvzCode = null;
    if (!empty($resData['deliveryDetail']['deliveryPoint'])) {
        $dp = $resData['deliveryDetail']['deliveryPoint'];
        $pvzCode = is_string($dp) ? $dp : ($dp['code'] ?? null);
    }
    if (!$pvzCode && !empty($resData['order']['deliveryPoint'])) {
        $dp = $resData['order']['deliveryPoint'];
        $pvzCode = is_string($dp) ? $dp : ($dp['code'] ?? null);
    }

    if ($pvzCode && is_string($pvzCode) && preg_match('/^[A-Za-z0-9_-]{3,15}$/', trim($pvzCode))) {
        return "Пункт выдачи " . trim($pvzCode) . ($cityTo ? " ({$cityTo})" : "");
    }

    return null;
}

function extractRecipientName($resData) {
    if (empty($resData) || !is_array($resData)) return null;

    $candidates = [
        $resData['order']['recipient'] ?? null,
        $resData['deliveryDetail']['recipientName'] ?? null,
        $resData['deliveryDetail']['recipient'] ?? null,
        $resData['recipient'] ?? null,
        $resData['client'] ?? null
    ];

    foreach ($candidates as $cand) {
        if (empty($cand)) continue;
        if (is_string($cand) && mb_strlen(trim($cand)) > 2) {
            return trim($cand);
        }
        if (is_array($cand)) {
            foreach (['name', 'fio', 'receiver', 'recipientName', 'clientName'] as $k) {
                if (!empty($cand[$k]) && is_string($cand[$k]) && mb_strlen(trim($cand[$k])) > 2) {
                    return trim($cand[$k]);
                }
            }
        }
    }

    return null;
}

// 4. Формирование ответа
if ($http_code === 200 && !empty($response['result'])) {
    $resData = $response['result'];
    $order   = $resData['order'] ?? [];

    $city_from = extractCity($order['sender'] ?? null) 
              ?: (extractCity($resData['fromLocation'] ?? null) 
              ?: 'Отправитель');

    $city_to = extractCity($order['recipient'] ?? null)
            ?: (extractCity($resData['warehouse'] ?? null)
            ?: (extractCity($resData['deliveryDetail'] ?? null)
            ?: (extractCity($resData['toLocation'] ?? null)
            ?: null)));

    if (!$city_to && !empty($resData['statuses']) && is_array($resData['statuses'])) {
        $lastStatus = end($resData['statuses']);
        if (!empty($lastStatus['currentCity']['name'])) {
            $city_to = trim($lastStatus['currentCity']['name']);
        } elseif (!empty($lastStatus['city']) && is_string($lastStatus['city'])) {
            $city_to = trim($lastStatus['city']);
        }
    }
    $city_to = $city_to ?: 'Пункт назначения';

    // Точный адрес ПВЗ
    $pvz_address = extractPvzAddressUniversal($resData, $city_to) ?: 'Пункт выдачи СДЭК';

    // Получатель (152-ФЗ)
    $raw_recipient = extractRecipientName($resData);
    $recipient_name = 'Данные защищены 152-ФЗ';

    if (!empty($raw_recipient)) {
        if (preg_match('/^[А-ЯЁA-Z]\.[А-ЯЁA-Z]\.[А-ЯЁA-Z]\.?$/u', str_replace(' ', '', $raw_recipient))) {
            $recipient_name = $raw_recipient;
        } else {
            $parts = preg_split('/\s+/u', trim($raw_recipient));
            if (count($parts) >= 3) {
                $recipient_name = $parts[0] . ' ' . 
                                  mb_substr($parts[1], 0, 1, 'UTF-8') . '.' . 
                                  mb_substr($parts[2], 0, 1, 'UTF-8') . '.';
            } elseif (count($parts) === 2) {
                $recipient_name = $parts[0] . ' ' . 
                                  mb_substr($parts[1], 0, 1, 'UTF-8') . '.';
            } else {
                $recipient_name = $raw_recipient;
            }
        }
    }

    $status_groups_raw = $resData['statusGroups'] ?? [];
    $active_status_name = 'В пути';
    $active_status_code = 'IN_PROGRESS';
    $progress_percent = 50;

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
    elseif ($active_status_code === 'IN_PROGRESS' || $active_status_code === 'COURIER_IN_PROGRESS') $progress_percent = 50;
    elseif ($active_status_code === 'READY_FOR_PICK_UP') $progress_percent = 85;
    elseif ($active_status_code === 'DELIVERED') $progress_percent = 100;

    $sub_statuses = [];
    if (!empty($resData['statuses']) && is_array($resData['statuses'])) {
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

    $months = ['', 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    
    $delivery_date_raw = null;
    if (!empty($resData['deliveryDetail'])) {
        foreach (['deliveryDate', 'rescheduledDeliveryDate', 'plannedDeliveryDate'] as $k) {
            if (!empty($resData['deliveryDetail'][$k]) && is_string($resData['deliveryDetail'][$k])) {
                $delivery_date_raw = $resData['deliveryDetail'][$k];
                break;
            }
        }
    }

    $delivery_date_formatted = null;
    if ($delivery_date_raw) {
        $t = strtotime($delivery_date_raw);
        $m_idx = (int)gmdate('n', $t + 3 * 3600);
        $delivery_date_formatted = (int)gmdate('j', $t + 3 * 3600) . ' ' . ($months[$m_idx] ?? '');
    }

    $initial_date_raw = $resData['deliveryDetail']['initialDeliveryDate'] ?? null;
    $initial_date_formatted = $initial_date_raw ? gmdate('d.m.Y', strtotime($initial_date_raw) + 3 * 3600) : null;

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
        'delivery_date'      => $delivery_date_formatted,
        'initial_date'       => $initial_date_formatted,
        'recipient_name'     => $recipient_name,
        'pvz_address'        => $pvz_address
    ], JSON_UNESCAPED_UNICODE);

} else {
    $msg = $response['errors'][0]['message'] ?? ($response['message'] ?? 'Накладная не найдена. Проверьте правильность номера.');
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
}
