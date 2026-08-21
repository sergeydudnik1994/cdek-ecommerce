<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// --- НАСТРОЙКИ ПОЧТЫ YANDEX ---
$smtp_user   = 'cdek-marketplace.ru@yandex.ru';
$smtp_pass   = 'wpqknugvsmytuizk'; 
$to_email    = 'sv.dudnik@cdek.ru';
$site_domain = 'cdek-ecommerce.ru';

// --- НАСТРОЙКИ MAX ---
$botToken = 'f9LHodD0cOIh0czuiBUxkVLlSvsx7WpGcnRcDQEc3VCNqNJ5CtFyQLbxrLdir1CsXtxnayTCWnTB52NxS_-U';
$userId   = '175449457';

// --- СБОР ВСЕХ ПОЛЕЙ ИЗ ОБЕИХ ФОРМ ---
$phone         = isset($_POST['phone']) ? trim($_POST['phone']) : '';
$entity_type   = isset($_POST['entity_type']) ? trim($_POST['entity_type']) : '';
$inn           = isset($_POST['inn']) ? trim($_POST['inn']) : '';
$company       = isset($_POST['company_name']) ? trim($_POST['company_name']) : (isset($_POST['company']) ? trim($_POST['company']) : 'Не указано');
$email         = isset($_POST['email']) ? trim($_POST['email']) : '';
$messenger     = isset($_POST['messenger']) ? trim($_POST['messenger']) : 'Не указан';
$contract_raw  = isset($_POST['contract_status']) ? trim($_POST['contract_status']) : '';
$platform      = isset($_POST['platform']) ? trim($_POST['platform']) : '';
$tax_system    = isset($_POST['tax_system']) ? trim($_POST['tax_system']) : '';
$fact_address  = isset($_POST['fact_address']) ? trim($_POST['fact_address']) : '';
$legal_address = isset($_POST['legal_address']) ? trim($_POST['legal_address']) : '';
$rs            = isset($_POST['rs']) ? trim($_POST['rs']) : '';
$bik           = isset($_POST['bik']) ? trim($_POST['bik']) : '';
$shop_url      = isset($_POST['shop_url']) ? trim($_POST['shop_url']) : '';
$comment       = isset($_POST['comment']) ? trim($_POST['comment']) : '';

if (empty($phone)) {
    echo json_encode(['success' => false, 'message' => 'Заполните номер телефона']);
    exit;
}

// Определение типа заявки
$is_contract_form = (!empty($inn) || !empty($entity_type) || !empty($email));
$form_name = $is_contract_form ? '📋 Полная анкета договора (/dogovor/)' : '⚡ Быстрая заявка (Главная)';

// Расшифровка статуса договора
$contract_text = "Не указано";
if ($contract_raw === 'new') {
    $contract_text = "Нет договора (Новый клиент)";
} elseif ($contract_raw === 'inactive') {
    $contract_text = "Есть, не отправлял 6+ месяцев";
} elseif ($contract_raw === 'active') {
    $contract_text = "Есть действующий (отправляет)";
}

// --- ФОРМИРОВАНИЕ ЕДИНОГО ТЕКСТА ---
$text  = "🔥 НОВАЯ ЗАЯВКА С САЙТА: " . strtoupper($site_domain) . "\n";
$text .= "📌 Тип формы: " . $form_name . "\n";
$text .= "━━━━━━━━━━━━━━━━━━━━━\n\n";

if ($is_contract_form) {
    $text .= "🏢 Форма бизнеса: " . ($entity_type ?: 'ИП') . "\n";
    $text .= "🔢 ИНН: " . ($inn ?: 'Не указан') . "\n";
    $text .= "👤 ФИО / Организация: " . $company . "\n";
    $text .= "📞 Телефон: " . $phone . "\n";
    $text .= "✉️ Email: " . ($email ?: 'Не указан') . "\n";
    $text .= "💬 Способ связи: " . $messenger . "\n";
    $text .= "📝 Статус договора: " . $contract_text . "\n";
    $text .= "📊 Налоговый режим: " . ($tax_system ?: 'УСН / Спецрежимы') . "\n\n";

    if (!empty($fact_address) || !empty($legal_address) || !empty($rs) || !empty($bik) || !empty($shop_url)) {
        $text .= "📦 РЕКВИЗИТЫ ДЛЯ ДОГОВОРА:\n";
        if (!empty($fact_address))  $text .= "📍 ПВЗ для сдачи/возвратов: " . $fact_address . "\n";
        if (!empty($legal_address)) $text .= "🏠 Юр. адрес / Регистрация: " . $legal_address . "\n";
        if (!empty($rs))            $text .= "💳 Расчетный счет: " . $rs . "\n";
        if (!empty($bik))           $text .= "🏦 БИК Банка: " . $bik . "\n";
        if (!empty($shop_url))      $text .= "🌐 Магазин / Маркетплейс: " . $shop_url . "\n";
        $text .= "\n";
    }
} else {
    $text .= "🏢 ИНН / Компания: " . $company . "\n";
    $text .= "📞 Телефон: " . $phone . "\n";
    $text .= "💬 Способ связи: " . $messenger . "\n";
    $text .= "📝 Статус договора: " . $contract_text . "\n";
    if (!empty($platform)) $text .= "📦 Платформа / CMS: " . $platform . "\n";
}

if (!empty($comment)) {
    $text .= "✏️ Комментарий: " . $comment . "\n\n";
}

$text .= "━━━━━━━━━━━━━━━━━━━━━\n";
$text .= "🌐 Домен: https://" . $site_domain;

// ==========================================
// 1. ОТПРАВКА НА ПОЧТУ ЧЕРЕЗ SMTP YANDEX
// ==========================================
$subject = 'Заявка [' . $site_domain . '] ' . ($inn ? "ИНН $inn - " : '') . $company;

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
$postData = json_encode(['text' => $text]);

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
$httpCode = curl_getinfo($ch_max, CURLINFO_HTTP_CODE);
curl_close($ch_max);

if ($httpCode !== 200) {
    echo json_encode(['success' => false, 'message' => 'Ошибка отправки в MAX (код ' . $httpCode . ')']);
    exit;
}

echo json_encode(['success' => true]);
