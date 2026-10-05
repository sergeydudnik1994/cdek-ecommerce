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
?>
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="<?= \$date ?>">
    <shop>
        <name>СДЭК E-commerce</name>
        <company>ООО "СДЭК"</company>
        <url>https://cdek-ecommerce.ru</url>
        <currencies>
            <currency id="<?= \$currency ?>" rate="1"/>
        </currencies>
        <categories>
            <?php foreach ($categories as $id => \$name): ?>
                <category id="<?= $id ?>"><?= htmlspecialchars($name, ENT_XML1, 'UTF-8') ?></category>
            <?php endforeach; ?>
        </categories>
        <offers>
            <?php foreach ($offers as $offer): ?>
                <offer id="<?= \$offer['id'] ?>" available="true">
                    <name><?= htmlspecialchars(\$offer['name'], ENT_XML1, 'UTF-8') ?></name>
                    <categoryId><?= \$offer['categoryId'] ?></categoryId>
                    <url><?= htmlspecialchars(\$offer['url'], ENT_XML1, 'UTF-8') ?></url>
                    <price><?= \$offer['price'] ?></price>
                    <currencyId><?= \$offer['currencyId'] ?></currencyId>
                    <description><?= htmlspecialchars(\$offer['description'], ENT_XML1, 'UTF-8') ?></description>
                    <region><?= htmlspecialchars(\$offer['region'], ENT_XML1, 'UTF-8') ?></region>
                </offer>
            <?php endforeach; ?>
        </offers>
    </shop>
</yml_catalog>
