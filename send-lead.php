<?php
header('Content-Type: application/json');

// Разрешаем только POST-запросы
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// --- НАСТРОЙКИ ПОЧТЫ YANDEX ---
$smtp_user = 'cdek-marketplace.ru@yandex.ru';
$smtp_pass = 'wpqknugvsmytuizk'; 
$to_email  = 'sv.dudnik@cdek.ru';

// --- НАСТРОЙКИ MAX ---
$botToken = 'f9LHodD0cOIh0czuiBUxkVLlSvsx7WpGcnRcDQEc3VCNqNJ5CtFyQLbxrLdir1CsXtxnayTCWnTB52NxS_-U';
$userId   = '175449457';

// Получаем данные из формы
$company       = isset($_POST['company']) ? trim($_POST['company']) : 'Не указано';
$phone         = isset($_POST['phone']) ? trim($_POST['phone']) : '';
$messenger     = isset($_POST['messenger']) ? trim($_POST['messenger']) : 'Не указан';
$platform      = isset($_POST['platform']) ? trim($_POST['platform']) : 'E-commerce (Главная)';
$comment       = isset($_POST['comment']) ? trim($_POST['comment']) : '';
$contract_raw  = isset($_POST['contract_status']) ? trim($_POST['contract_status']) : '';

if (empty($phone)) {
    echo json_encode(['success' => false, 'message' => 'Заполните номер телефона']);
    exit;
}

// Расшифровываем статус договора
$contract_text = "Не указано";
if ($contract_raw === 'new') {
    $contract_text = "Нет договора";
} elseif ($contract_raw === 'inactive') {
    $contract_text = "Есть, но не отправлял 6+ месяцев";
} elseif ($contract_raw === 'active') {
    $contract_text = "Есть действующий (попытка обхода)";
}

// Формируем текст сообщения
$text  = "🔥 Новая заявка cdek-ecommerce.ru!\n\n";
$text .= "🏢 Имя / Компания: " . htmlspecialchars($company) . "\n";
$text .= "📞 Телефон: " . htmlspecialchars($phone) . "\n";
$text .= "💬 Способ связи: " . htmlspecialchars($messenger) . "\n";
$text .= "📝 Наличие договора: " . $contract_text . "\n";
$text .= "📦 CMS / Маркетплейс: " . htmlspecialchars($platform) . "\n";

if (!empty($comment)) {
    $text .= "✏️ Комментарий: " . htmlspecialchars($comment) . "\n";
}

$text .= "\n🌐 Источник: cdek-ecommerce.ru";


// ==========================================
// 1. ОТПРАВКА НА ПОЧТУ ЧЕРЕЗ SMTP YANDEX
// ==========================================
$subject = 'Новая заявка cdek-ecommerce: ' . htmlspecialchars($company);

$mail_body  = "To: <$to_email>\r\n";
$mail_body .= "From: <$smtp_user>\r\n";
$mail_body .= "Subject: =?utf-8?B?" . base64_encode($subject) . "?=\r\n";
$mail_body .= "Date: " . date("r") . "\r\n";
$mail_body .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
$mail_body .= $text;

$stream = fopen('php://memory', 'r+');
fwrite($stream, $mail_body);
rewind($stream);

$ch_mail = curl_init();
curl_setopt_array($ch_mail, [
    CURLOPT_URL            => 'smtps://smtp.yandex.ru:465',
    CURLOPT_MAIL_FROM      => "<$smtp_user>",
    CURLOPT_MAIL_RCPT      => ["<$to_email>"],
    CURLOPT_USERNAME       => $smtp_user,
    CURLOPT_PASSWORD       => $smtp_pass,
    CURLOPT_USE_SSL        => CURLUSESSL_ALL,
    CURLOPT_UPLOAD         => true,
    CURLOPT_READDATA       => $stream,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0
]);

curl_exec($ch_mail);
curl_close($ch_mail);
fclose($stream);


// ==========================================
// 2. ОТПРАВКА В MAX BOT API
// ==========================================
$url = "https://platform-api2.max.ru/messages?user_id=" . $userId;
$postData = json_encode([
    'text' => $text
]);

$ch_max = curl_init();
curl_setopt_array($ch_max, [
    CURLOPT_URL            => $url,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: ' . $botToken,
        'Content-Type: application/json'
    ],
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0
]);

$response = curl_exec($ch_max);
$curlError = curl_error($ch_max);
$httpCode = curl_getinfo($ch_max, CURLINFO_HTTP_CODE);
curl_close($ch_max);

if ($curlError) {
    echo json_encode(['success' => false, 'message' => 'Ошибка сети при отправке: ' . $curlError]);
    exit;
}

if ($httpCode !== 200) {
    echo json_encode(['success' => false, 'message' => 'Ошибка API Max (код ' . $httpCode . '): ' . $response]);
    exit;
}

echo json_encode(['success' => true]);
