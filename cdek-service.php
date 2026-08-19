<?php
header('Content-Type: application/json; charset=utf-8');

// Параметры авторизации CDEK API v2.0
// Для боевого договора используй: https://api.cdek.ru
// Для тестовой песочницы: https://api.edu.cdek.ru
define('CDEK_API_URL', 'https://api.cdek.ru');
define('CDEK_CLIENT_ID', 'wqGwiQx0gg8mLtiEKsUinjVSICCjtTEP');
define('CDEK_CLIENT_SECRET', 'RmAmgvSgSL1yirIz9QupbzOJVqhCxcP5');
define('TOKEN_CACHE_FILE', sys_get_temp_dir() . '/cdek_token_cache.json');

// Чтение входных данных от калькулятора
$inputData = json_decode(file_get_contents('php://input'), true);

if (!$inputData) {
    http_response_code(400);
    echo json_encode(['error' => 'Неверный формат входных данных']);
    exit;
}

$cityFrom = trim($inputData['cityFrom'] ?? '');
$cityTo = trim($inputData['cityTo'] ?? '');
$tariffCode = intval($inputData['tariffCode'] ?? 136);
$goods = $inputData['goods'] ?? [['weight' => 1000, 'length' => 10, 'width' => 10, 'height' => 10]];

if (empty($cityFrom) || empty($cityTo)) {
    http_response_code(400);
    echo json_encode(['error' => 'Укажите города отправления и назначения']);
    exit;
}

// 1. Получение OAuth Bearer токена с кэшированием
function getCdekToken() {
    if (file_exists(TOKEN_CACHE_FILE)) {
        $cached = json_decode(file_get_contents(TOKEN_CACHE_FILE), true);
        if ($cached && isset($cached['access_token']) && isset($cached['expires_at']) && time() < ($cached['expires_at'] - 60)) {
            return $cached['access_token'];
        }
    }

    $ch = curl_init(CDEK_API_URL . '/v2/oauth/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => CDEK_CLIENT_ID,
        'client_secret' => CDEK_CLIENT_SECRET
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['access_token'])) {
            $data['expires_at'] = time() + intval($data['expires_in']);
            @file_put_contents(TOKEN_CACHE_FILE, json_encode($data));
            return $data['access_token'];
        }
    }
    return null;
}

// 2. Определение кода города в базе СДЭК
function getCityCode($cityName, $token) {
    $ch = curl_init(CDEK_API_URL . '/v2/location/cities?city=' . urlencode($cityName) . '&size=1');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    if (is_array($data) && !empty($data[0]['code'])) {
        return $data[0]['code'];
    }
    return null;
}

$token = getCdekToken();
if (!$token) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка авторизации в API СДЭК']);
    exit;
}

$codeFrom = getCityCode($cityFrom, $token);
$codeTo = getCityCode($cityTo, $token);

if (!$codeFrom || !$codeTo) {
    http_response_code(404);
    echo json_encode(['error' => 'Город не найден в базе СДЭК']);
    exit;
}

// 3. Запрос расчета по тарифу
$calcPayload = [
    'tariff_code' => $tariffCode,
    'from_location' => ['code' => $codeFrom],
    'to_location' => ['code' => $codeTo],
    'packages' => $goods
];

$ch = curl_init(CDEK_API_URL . '/v2/calculator/tariff');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($calcPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$calcResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$calcResult = json_decode($calcResponse, true);

if ($httpCode === 200 && isset($calcResult['total_sum'])) {
    echo json_encode([
        'result' => [
            'price' => $calcResult['total_sum'],
            'deliveryPeriodMin' => $calcResult['period_min'] ?? 1,
            'deliveryPeriodMax' => $calcResult['period_max'] ?? 3
        ]
    ]);
} else {
    http_response_code(400);
    $errorMsg = 'Не удалось рассчитать стоимость по тарифу';
    if (!empty($calcResult['errors'][0]['message'])) {
        $errorMsg = $calcResult['errors'][0]['message'];
    }
    echo json_encode(['error' => $errorMsg, 'details' => $calcResult]);
}
