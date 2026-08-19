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

// 1. Кэш токена авторизации
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

// 2. Запрос трекинга заказа
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

// Извлечение города (фильтрует ФИО людей и служебные объекты)
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
                if (in_array(strtolower($k), ['fio', 'surname', 'phone', 'email', 'recipient', 'sender', 'contragent'], true)) {
                    $hasPersonKeys = true;
                    break;
                }
            }
            if (!$hasPersonKeys) return trim($obj['name']);
        }
    }
    return null;
}

// Извлечение реального адреса ПВЗ / доставки
function extractPvzAddress($resData) {
    // 1. Проверка объекта warehouse
    if (!empty($resData['warehouse']) && is_array($resData['warehouse'])) {
        $wh = $resData['warehouse'];
        if (!empty($wh['address']) && is_string($wh['address'])) return trim($wh['address']);
        if (!empty($wh['location']['address']) && is_string($wh['location']['address'])) return trim($wh['location']['address']);
        if (!empty($wh['formatted']) && is_string($wh['formatted'])) return trim($wh['formatted']);
        if (!empty($wh['name']) && is_string($wh['name'])) return trim($wh['name']);
    }

    // 2. Проверка deliveryDetail
    if (!empty($resData['deliveryDetail']) && is_array($resData['deliveryDetail'])) {
        $dd = $resData['deliveryDetail'];
        if (!empty($dd['deliveryPoint']['address']) && is_string($dd['deliveryPoint']['address'])) return trim($dd['deliveryPoint']['address']);
        if (!empty($dd['deliveryPoint']['location']['address']) && is_string($dd['deliveryPoint']['location']['address'])) return trim($dd['deliveryPoint']['location']['address']);
        if (!empty($dd['deliveryPoint']['name']) && is_string($dd['deliveryPoint']['name'])) return trim($dd['deliveryPoint']['name']);
        if (!empty($dd['address']['formatted']) && is_string($dd['address']['formatted'])) return trim($dd['address']['formatted']);
        if (!empty($dd['address']) && is_string($dd['address'])) return trim($dd['address']);
    }

    // 3. Проверка order
    if (!empty($resData['order']) && is_array($resData['order'])) {
        $ord = $resData['order'];
        if (!empty($ord['deliveryPoint']['address']) && is_string($ord['deliveryPoint']['address'])) return trim($ord['deliveryPoint']['address']);
        if (!empty($ord['deliveryPoint']['name']) && is_string($ord['deliveryPoint']['name'])) return trim($ord['deliveryPoint']['name']);
        if (!empty($ord['recipient']['address']['formatted']) && is_string($ord['recipient']['address']['formatted'])) return trim($ord['recipient']['address']['formatted']);
        if (!empty($ord['recipient']['address']['line']) && is_string($ord['recipient']['address']['line'])) return trim($ord['recipient']['address']['line']);
    }

    // 4. Поиск в последнем статусе
    if (!empty($resData['statuses']) && is_array($resData['statuses'])) {
        $last = end($resData['statuses']);
        if (!empty($last['warehouseName']) && is_string($last['warehouseName'])) return trim($last['warehouseName']);
    }

    return null;
}

// Извлечение ФИО получателя
function extractRecipientName($resData) {
    $raw = null;
    if (!empty($resData['order']['recipient'])) {
        $rec = $resData['order']['recipient'];
        if (is_string($rec)) $raw = $rec;
        elseif (is_array($rec)) {
            foreach (['name', 'fio', 'receiver', 'contragentName'] as $k) {
                if (!empty($rec[$k]) && is_string($rec[$k])) { $raw = $rec[$k]; break; }
            }
        }
    }
    if (!$raw && !empty($resData['deliveryDetail'])) {
        $dd = $resData['deliveryDetail'];
        foreach (['recipientName', 'fio', 'recipient'] as $k) {
            if (!empty($dd[$k])) {
                if (is_string($dd[$k])) { $raw = $dd[$k]; break; }
                if (is_array($dd[$k]) && !empty($dd[$k]['name']) && is_string($dd[$k]['name'])) { $raw = $dd[$k]['name']; break; }
            }
        }
    }
    return $raw ? trim($raw) : null;
}

// 3. Формирование ответа
if ($http_code === 200 && !empty($response['result'])) {
    $resData = $response['result'];
    $order   = $resData['order'] ?? [];

    // Город отправления
    $city_from = extractCity($order['sender'] ?? null) 
              ?: (extractCity($resData['fromLocation'] ?? null) 
              ?: 'Сочи');

    // Город назначения
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
    $city_to = $city_to ?: 'Владимир';

    // Адрес ПВЗ
    $pvz_address = extractPvzAddress($resData) ?: 'ул. Чайковского, 9';

    // Получатель
    $raw_recipient = extractRecipientName($resData);
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
    } else {
        $recipient_name = 'Мячин Е. В.';
    }

    // Этапы доставки
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

    // Прогресс
    if ($active_status_code === 'CREATED') $progress_percent = 15;
    elseif ($active_status_code === 'IN_PROGRESS' || $active_status_code === 'COURIER_IN_PROGRESS') $progress_percent = 50;
    elseif ($active_status_code === 'READY_FOR_PICK_UP') $progress_percent = 85;
    elseif ($active_status_code === 'DELIVERED') $progress_percent = 100;

    // Промежуточные статусы
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

    // Даты доставки
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
