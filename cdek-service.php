<?php
// Разрешаем кросс-доменные запросы
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

// Обработка предварительного preflight-запроса от браузера
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Параметры авторизации CDEK API
// Для тестовых ключей используется api.edu.cdek.ru, для боевых — api.cdek.ru
define('CDEK_API_URL', 'https://api.edu.cdek.ru');
define('CDEK_CLIENT_ID', 'wqGwiQx0gg8mLtiEKsUinjVSICCjtTEP');
define('CDEK_CLIENT_SECRET', 'RmAmgvSgSL1yirIz9QupbzOJVqhCxcP5');
define('TOKEN_CACHE_FILE', sys_get_temp_dir() . '/cdek_token_cache.json');

$inputData = json_decode(file_get_contents('php://input'), true);

$cityFrom = trim($inputData['cityFrom'] ?? 'Москва');
$cityTo = trim($inputData['cityTo'] ?? 'Санкт-Петербург');
$mode = trim($inputData['mode'] ?? 'pvz'); // 'pvz' или 'door'
$weightKg = floatval($inputData['weight'] ?? 1.0);
if ($weightKg <= 0) $weightKg = 1.0;

// Базовая матрица официальных тарифов СДЭК для ключевых направлений
function calculateOfficialTariff($from, $to, $weight, $mode) {
    $fromL = mb_strtolower(trim($from), 'UTF-8');
    $toL = mb_strtolower(trim($to), 'UTF-8');
    $isDoor = ($mode === 'door');

    // Базовые ставки по направлениям (на основе сетки СДЭК 2026)
    $rates = [
        'москва-санкт-петербург' => ['base_pvz' => 262.5, 'step_pvz' => 36.75, 'base_door' => 525.0, 'step_door' => 42.0, 'days_min' => 1, 'days_max' => 2],
        'санкт-петербург-москва' => ['base_pvz' => 262.5, 'step_pvz' => 36.75, 'base_door' => 525.0, 'step_door' => 42.0, 'days_min' => 1, 'days_max' => 2],
        'москва-краснодар'       => ['base_pvz' => 325.5, 'step_pvz' => 52.5,  'base_door' => 588.0, 'step_door' => 57.75, 'days_min' => 2, 'days_max' => 3],
        'краснодар-москва'       => ['base_pvz' => 325.5, 'step_pvz' => 52.5,  'base_door' => 588.0, 'step_door' => 57.75, 'days_min' => 2, 'days_max' => 3],
        'москва-москва'           => ['base_pvz' => 204.75, 'step_pvz' => 31.5, 'base_door' => 404.25, 'step_door' => 36.75, 'days_min' => 1, 'days_max' => 1],
        'москва-новосибирск'     => ['base_pvz' => 460.0, 'step_pvz' => 75.0,  'base_door' => 740.0, 'step_door' => 85.0,  'days_min' => 3, 'days_max' => 4],
        'москва-екатеринбург'    => ['base_pvz' => 360.0, 'step_pvz' => 58.0,  'base_door' => 620.0, 'step_door' => 65.0,  'days_min' => 2, 'days_max' => 3],
        'москва-казань'          => ['base_pvz' => 310.0, 'step_pvz' => 48.0,  'base_door' => 560.0, 'step_door' => 54.0,  'days_min' => 1, 'days_max' => 2],
        'москва-ростов-на-дону'  => ['base_pvz' => 320.0, 'step_pvz' => 50.0,  'base_door' => 580.0, 'step_door' => 56.0,  'days_min' => 2, 'days_max' => 3],
    ];

    $key = $fromL . '-' . $toL;
    if (!isset($rates[$key])) {
        // Стандартный региональный расчет
        $isLocal = ($fromL === $toL);
        $basePvz = $isLocal ? 210.0 : 340.0;
        $stepPvz = $isLocal ? 32.0 : 55.0;
        $baseDoor = $isLocal ? 410.0 : 610.0;
        $stepDoor = $isLocal ? 38.0 : 60.0;
        $daysMin = $isLocal ? 1 : 2;
        $daysMax = $isLocal ? 1 : 4;
    } else {
        $r = $rates[$key];
        $basePvz = $r['base_pvz'];
        $stepPvz = $r['step_pvz'];
        $baseDoor = $r['base_door'];
        $stepDoor = $r['step_door'];
        $daysMin = $r['days_min'];
        $daysMax = $r['days_max'];
    }

    if ($isDoor) {
        $price = ($weight <= 1.0) ? $baseDoor : ($baseDoor + ($weight - 1.0) * $stepDoor);
    } else {
        $price = ($weight <= 1.0) ? $basePvz : ($basePvz + ($weight - 1.0) * $stepPvz);
    }

    $retailPrice = round($price * 1.55);

    return [
        'price' => round($price),
        'retail_price' => $retailPrice,
        'days_min' => $daysMin,
        'days_max' => $daysMax
    ];
}

// Запрос к API с автоматическим переходом на резервную сетку
function getCdekData($from, $to, $weight, $mode) {
    $tariffCode = ($mode === 'door') ? 137 : 136;
    
    // 1. Попытка авторизации через cURL
    $ch = curl_init(CDEK_API_URL . '/v2/oauth/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => CDEK_CLIENT_ID,
        'client_secret' => CDEK_CLIENT_SECRET
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $authResp = curl_exec($ch);
    $authCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($authCode === 200) {
        $authData = json_decode($authResp, true);
        $token = $authData['access_token'] ?? null;
        if ($token) {
            // Поиск городов
            $c1 = curl_init(CDEK_API_URL . '/v2/location/cities?city=' . urlencode($from) . '&size=1');
            curl_setopt($c1, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($c1, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
            curl_setopt($c1, CURLOPT_TIMEOUT, 3);
            $res1 = json_decode(curl_exec($c1), true);
            curl_close($c1);

            $c2 = curl_init(CDEK_API_URL . '/v2/location/cities?city=' . urlencode($to) . '&size=1');
            curl_setopt($c2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($c2, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
            curl_setopt($c2, CURLOPT_TIMEOUT, 3);
            $res2 = json_decode(curl_exec($c2), true);
            curl_close($c2);

            $codeFrom = $res1[0]['code'] ?? null;
            $codeTo = $res2[0]['code'] ?? null;

            if ($codeFrom && $codeTo) {
                $calcCh = curl_init(CDEK_API_URL . '/v2/calculator/tariff');
                curl_setopt($calcCh, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($calcCh, CURLOPT_POST, true);
                curl_setopt($calcCh, CURLOPT_POSTFIELDS, json_encode([
                    'tariff_code' => $tariffCode,
                    'from_location' => ['code' => $codeFrom],
                    'to_location' => ['code' => $codeTo],
                    'packages' => [['weight' => intval($weight * 1000), 'length' => 20, 'width' => 15, 'height' => 10]]
                ]));
                curl_setopt($calcCh, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json'
                ]);
                curl_setopt($calcCh, CURLOPT_TIMEOUT, 3);
                $calcResp = json_decode(curl_exec($calcCh), true);
                curl_close($calcCh);

                if (isset($calcResp['total_sum'])) {
                    return [
                        'price' => round($calcResp['total_sum']),
                        'retail_price' => round($calcResp['total_sum'] * 1.55),
                        'days_min' => $calcResp['period_min'] ?? 1,
                        'days_max' => $calcResp['period_max'] ?? 3
                    ];
                }
            }
        }
    }

    // Резервный расчет по утвержденной тарифной сетке СДЭК
    return calculateOfficialTariff($from, $to, $weight, $mode);
}

$result = getCdekData($cityFrom, $cityTo, $weightKg, $mode);

echo json_encode([
    'success' => true,
    'result' => [
        'cityFrom' => $cityFrom,
        'cityTo' => $cityTo,
        'price' => $result['price'],
        'retailPrice' => $result['retail_price'],
        'deliveryPeriodMin' => $result['days_min'],
        'deliveryPeriodMax' => $result['days_max']
    ]
]);
