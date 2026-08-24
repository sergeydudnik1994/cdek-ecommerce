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

// Вспомогательные функции извлечения данных
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

function formatAddressString($val) {
    if (empty($val)) return null;
    if (is_string($val)) {
        $trimmed = trim($val);
        return (mb_strlen($trimmed) > 3) ? $trimmed : null;
    }
    if (is_array($val)) {
        foreach (['fullAddress', 'formattedAddress', 'formatted', 'rawAddress', 'address', 'line', 'addressComment'] as $k) {
            if (!empty($val[$k]) && is_string($val[$k]) && mb_strlen(trim($val[$k])) > 3) {
                return trim($val[$k]);
            }
        }
        $parts = [];
        if (!empty($val['city']) && is_string($val['city'])) $parts[] = trim($val['city']);
        if (!empty($val['street']) && is_string($val['street'])) $parts[] = trim($val['street']);
        if (!empty($val['house']) && is_string($val['house'])) $parts[] = 'д. ' . trim($val['house']);
        if (!empty($val['flat']) && is_string($val['flat'])) $parts[] = 'кв./оф. ' . trim($val['flat']);
        if (!empty($parts)) return implode(', ', $parts);

        if (!empty($val['location'])) {
            $loc = formatAddressString($val['location']);
            if ($loc) return $loc;
        }
        if (!empty($val['name']) && is_string($val['name']) && mb_strlen(trim($val['name'])) > 3) {
            return trim($val['name']);
        }
    }
    return null;
}

function extractPvzAddress($resData) {
    if (empty($resData) || !is_array($resData)) return null;

    // 1. Блок deliveryDetail (PVZ, DeliveryPoint, Address)
    if (!empty($resData['deliveryDetail']) && is_array($resData['deliveryDetail'])) {
        $dd = $resData['deliveryDetail'];
        foreach (['deliveryPoint', 'pvz', 'pickupPoint', 'warehouse', 'address', 'location', 'toLocation'] as $key) {
            if (!empty($dd[$key])) {
                $addr = formatAddressString($dd[$key]);
                if ($addr) return $addr;
            }
        }
    }

    // 2. Блок warehouse / toWarehouse
    foreach (['warehouse', 'toWarehouse', 'pickupPoint'] as $key) {
        if (!empty($resData[$key])) {
            $addr = formatAddressString($resData[$key]);
            if ($addr) return $addr;
        }
    }

    // 3. Блоки локации назначения
    foreach (['toLocation', 'destination', 'to_location', 'recipientLocation', 'location'] as $key) {
        if (!empty($resData[$key])) {
            $addr = formatAddressString($resData[$key]);
            if ($addr) return $addr;
        }
    }

    // 4. Внутри объекта order
    if (!empty($resData['order']) && is_array($resData['order'])) {
        $ord = $resData['order'];
        foreach (['deliveryPoint', 'pvz', 'warehouse', 'toLocation', 'to_location', 'address'] as $key) {
            if (!empty($ord[$key])) {
                $addr = formatAddressString($ord[$key]);
                if ($addr) return $addr;
            }
        }
        if (!empty($ord['recipient']['address'])) {
            $addr = formatAddressString($ord['recipient']['address']);
            if ($addr) return $addr;
        }
    }

    // 5. Проверка статусов (на случай, если адрес ПВЗ зафиксирован в промежуточном или финальном офисе)
    if (!empty($resData['statuses']) && is_array($resData['statuses'])) {
        for ($i = count($resData['statuses']) - 1; $i >= 0; $i--) {
            $st = $resData['statuses'][$i];
            foreach (['deliveryPoint', 'pvz', 'office', 'warehouse', 'location'] as $sk) {
                if (!empty($st[$sk])) {
                    $addr = formatAddressString($st[$sk]);
                    if ($addr) return $addr;
                }
            }
        }
    }

    // 6. Глубокий рекурсивный поиск адреса (исключая данные отправителя)
    return deepSearchAddress($resData);
}

function deepSearchAddress($array) {
    if (!is_array($array)) return null;

    foreach ($array as $k => $v) {
        $lowerKey = strtolower($k);
        if (in_array($lowerKey, ['sender', 'fromlocation', 'from_location'], true)) continue;

        if (in_array($lowerKey, ['address', 'fulladdress', 'formattedaddress', 'rawaddress'], true) && is_string($v) && mb_strlen(trim($v)) > 5) {
            return trim($v);
        }
        if (in_array($lowerKey, ['deliverypoint', 'pvz', 'warehouse'], true)) {
            $res = formatAddressString($v);
            if ($res && mb_strlen($res) > 3) return $res;
        }
    }

    foreach ($array as $k => $v) {
        $lowerKey = strtolower($k);
        if (in_array($lowerKey, ['sender', 'fromlocation', 'from_location'], true)) continue;
        if (is_array($v)) {
            $res = deepSearchAddress($v);
            if ($res) return $res;
        }
    }
    return null;
}

function extractRecipientName($resData) {
    $raw = null;
    if (!empty($resData['order']['recipient'])) {
        $rec = $resData['order']['recipient'];
        if (is_string($rec)) $raw = $rec;
        elseif (is_array($rec)) {
            foreach (['name', 'fio', 'receiver'] as $k) {
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

// 3. Обработка и нормализация полей
if ($http_code === 200 && !empty($response['result'])) {
    $resData = $response['result'];
    $order   = $resData['order'] ?? [];

    // Город отправления
    $city_from = extractCity($order['sender'] ?? null) 
              ?: (extractCity($resData['fromLocation'] ?? null) 
              ?: 'Отправитель');

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
    $city_to = $city_to ?: 'Пункт назначения';

    // Адрес ПВЗ / доставки
    $pvz_address = extractPvzAddress($resData) ?: 'Пункт выдачи СДЭК';

    // Получатель (оставляем логику 152-ФЗ)
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

    // Этапы доставки (statusGroups)
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

    // Расчет прогресс-бара
    if ($active_status_code === 'CREATED') $progress_percent = 15;
    elseif ($active_status_code === 'IN_PROGRESS' || $active_status_code === 'COURIER_IN_PROGRESS') $progress_percent = 50;
    elseif ($active_status_code === 'READY_FOR_PICK_UP') $progress_percent = 85;
    elseif ($active_status_code === 'DELIVERED') $progress_percent = 100;

    // Промежуточные статусы для аккордеона
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
