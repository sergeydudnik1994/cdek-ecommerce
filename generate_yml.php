<?php
header('Content-Type: text/xml; charset=utf-8');

\$date = date('Y-m-d\TH:i:sP');
\$currency = 'RUB';

\$categories = [
    1 => 'Доставка и логистика',
    2 => 'Складские услуги',
    3 => 'Дополнительные услуги'
];

\$offers = [
    [
        'id' => '1001',
        'name' => 'Доставка посылки по России',
        'categoryId' => 1,
        'url' => 'https://cdek-ecommerce.ru/dogovor/',
        'price' => 500,
        'currencyId' => \$currency,
        'description' => 'Стандартная доставка посылки по России. Срок доставки от 2 до 7 дней в зависимости от региона.',
        'region' => 'Россия'
    ],
    [
        'id' => '1002',
        'name' => 'Экспресс-доставка по городу',
        'categoryId' => 1,
        'url' => 'https://cdek-ecommerce.ru/dogovor/',
        'price' => 1200,
        'currencyId' => \$currency,
        'description' => 'Экспресс-доставка по городу в течение 24 часов. Услуга доступна в крупных городах РФ.',
        'region' => 'Россия'
    ],
    [
        'id' => '1003',
        'name' => 'Складское хранение груза',
        'categoryId' => 2,
        'url' => 'https://cdek-ecommerce.ru/dogovor/',
        'price' => 300,
        'currencyId' => \$currency,
        'description' => 'Складское хранение груза до 30 дней. Стоимость указана за сутки хранения.',
        'region' => 'Россия'
    ],
    [
        'id' => '1004',
        'name' => 'Страхование груза',
        'categoryId' => 3,
        'url' => 'https://cdek-ecommerce.ru/dogovor/',
        'price' => 150,
        'currencyId' => \$currency,
        'description' => 'Страхование груза на время транспортировки. Страховая сумма рассчитывается от объявленной стоимости груза.',
        'region' => 'Россия'
    ],
    [
        'id' => '1005',
        'name' => 'Упаковка посылки',
        'categoryId' => 3,
        'url' => 'https://cdek-ecommerce.ru/dogovor/',
        'price' => 200,
        'currencyId' => \$currency,
        'description' => 'Профессиональная упаковка посылки для безопасной транспортировки.',
        'region' => 'Россия'
    ]
];

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
echo '<yml_catalog date="' . \$date . '">' . PHP_EOL;
echo '    <shop>' . PHP_EOL;
echo '        <name>СДЭК E-commerce</name>' . PHP_EOL;
echo '        <company>ООО "СДЭК"</company>' . PHP_EOL;
echo '        <url>https://cdek-ecommerce.ru</url>' . PHP_EOL;
echo '        <currencies>' . PHP_EOL;
echo '            <currency id="' . \$currency . '" rate="1"/>' . PHP_EOL;
echo '        </currencies>' . PHP_EOL;
echo '        <categories>' . PHP_EOL;

foreach ($categories as $id => \$name) {
    echo '            <category id="' . $id . '">' . htmlspecialchars($name, ENT_XML1, 'UTF-8') . '</category>' . PHP_EOL;
}

echo '        </categories>' . PHP_EOL;
echo '        <offers>' . PHP_EOL;

foreach ($offers as $offer) {
    echo '            <offer id="' . \$offer['id'] . '" available="true">' . PHP_EOL;
    echo '                <name>' . htmlspecialchars(\$offer['name'], ENT_XML1, 'UTF-8') . '</name>' . PHP_EOL;
    echo '                <categoryId>' . \$offer['categoryId'] . '</categoryId>' . PHP_EOL;
    echo '                <url>' . htmlspecialchars(\$offer['url'], ENT_XML1, 'UTF-8') . '</url>' . PHP_EOL;
    echo '                <price>' . \$offer['price'] . '</price>' . PHP_EOL;
    echo '                <currencyId>' . \$offer['currencyId'] . '</currencyId>' . PHP_EOL;
    echo '                <description>' . htmlspecialchars(\$offer['description'], ENT_XML1, 'UTF-8') . '</description>' . PHP_EOL;
    echo '                <region>' . htmlspecialchars(\$offer['region'], ENT_XML1, 'UTF-8') . '</region>' . PHP_EOL;
    echo '            </offer>' . PHP_EOL;
}

echo '        </offers>' . PHP_EOL;
