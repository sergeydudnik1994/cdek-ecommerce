import os
from datetime import datetime, timezone, timedelta
import xml.etree.ElementTree as ET
from xml.dom import minidom

# Базовые настройки магазина / компании
BASE_URL = "https://cdek-ecommerce.ru"
SHOP_NAME = "CDEK E-Commerce"
COMPANY_NAME = "CDEK E-Commerce"

# Список категорий услуг
CATEGORIES = [
    {"id": "1", "name": "Логистика и доставка"},
    {"id": "2", "parentId": "1", "name": "Доставка для интернет-магазинов"},
    {"id": "3", "parentId": "1", "name": "Доставка для маркетплейсов"},
    {"id": "4", "parentId": "1", "name": "Специальные услуги доставки"},
    {"id": "5", "parentId": "1", "name": "B2B договоры"}
]

# Список предложений (услуг) с точным соответствием посадочным страницам и ценам
OFFERS = [
    {
        "id": "srv_ecom",
        "category_id": "2",
        "url": f"{BASE_URL}/dogovor/internet-magazin/",
        "price": "205",
        "name": "Доставка для интернет-магазинов со скидкой до 50%",
        "description": "B2B-договор СДЭК для онлайн-магазинов: льготный тариф «Посылка» от 205 руб., наложенный платеж по 54-ФЗ, интеграция с CMS и доставка через 5000+ ПВЗ и курьером."
    },
    {
        "id": "srv_market",
        "category_id": "3",
        "url": f"{BASE_URL}/dogovor/marketplace/",
        "price": "205",
        "name": "Логистика для маркетплейсов по схемам FBS и DBS",
        "description": "Официальная доставка СДЭК для продавцов Wildberries, Ozon и Яндекс Маркет. Отгрузка заказов по схемам FBS, rFBS и DBS с передачей трек-номеров по API."
    },
    {
        "id": "srv_clothes",
        "category_id": "4",
        "url": f"{BASE_URL}/services/dostavka-odezhdy/",
        "price": "250",
        "name": "Доставка одежды и обуви с примеркой и частичным выкупом",
        "description": "Специализированный сервис СДЭК для магазинов одежды: примерка в ПВЗ и на дому до 15 минут, частичный выкуп товаров, бесплатный возврат непринятых позиций."
    },
    {
        "id": "srv_fulf",
        "category_id": "4",
        "url": f"{BASE_URL}/services/fulfillment/",
        "price": "1500",
        "name": "Фулфилмент под ключ для e-commerce и селлеров",
        "description": "Комплексный фулфилмент СДЭК: ответственное хранение на складе класса А, маркировка Честный Знак, комплектация, упаковка и оперативная отправка заказов."
    },
    {
        "id": "srv_heavy",
        "category_id": "4",
        "url": f"{BASE_URL}/dogovor/krupnogabarit/",
        "price": "450",
        "name": "Доставка крупногабаритных грузов (КГТ) по России",
        "description": "Магистральная и курьерская доставка крупногабаритных грузов и оборудования по специальным тарифам B2B с забором с вашего склада."
    },
    {
        "id": "srv_parts",
        "category_id": "4",
        "url": f"{BASE_URL}/dogovor/avtozapchasti/",
        "price": "250",
        "name": "Доставка автозапчастей и агрегатов для СТО и магазинов",
        "description": "Корпоративная логистика для автобизнеса: экспресс-доставка запчастей, кузовных деталей и расходных материалов без переплаты НДС по сетке УСН."
    },
    {
        "id": "srv_ip",
        "category_id": "5",
        "url": f"{BASE_URL}/dogovor/ip/",
        "price": "205",
        "name": "Заключение договора СДЭК для ИП онлайн за 15 минут",
        "description": "Официальное оформление договора СДЭК для индивидуальных предпринимателей: тарифная сетка УСН без НДС 22%, постоплата раз в месяц, наложенный платеж 54-ФЗ."
    },
    {
        "id": "srv_self",
        "category_id": "5",
        "url": f"{BASE_URL}/dogovor/samozanyatye/",
        "price": "205",
        "name": "Договор СДЭК для самозанятых (плательщиков НПД)",
        "description": "Дистанционное подключение самозанятых к корпоративным тарифам СДЭК: скидка до 50% на отправку посылок, интеграция по API, оплата картой в ЛК."
    },
    {
        "id": "srv_llc",
        "category_id": "5",
        "url": f"{BASE_URL}/dogovor/yur-lica/",
        "price": "205",
        "name": "B2B-договор СДЭК для юридических лиц и ООО",
        "description": "Корпоративное обслуживание юрлиц: документооборот через Диадок и СБИС (ЭДО), выбор налоговой сетки (УСН / ОСНО с НДС), персональный менеджер."
    }
]

def generate_yml():
    # Текущее время в формате ISO-8601 с часовым поясом МСК (+03:00)
    msk_tz = timezone(timedelta(hours=3))
    now_iso = datetime.now(msk_tz).strftime("%Y-%m-%dT%H:%M:%S+03:00")

    # Корневой тег yml_catalog
    yml_catalog = ET.Element("yml_catalog", date=now_iso)
    shop = ET.SubElement(yml_catalog, "shop")

    # Метаданные магазина
    ET.SubElement(shop, "name").text = SHOP_NAME
    ET.SubElement(shop, "company").text = COMPANY_NAME
    ET.SubElement(shop, "url").text = BASE_URL

    # Валюта
    currencies = ET.SubElement(shop, "currencies")
    ET.SubElement(currencies, "currency", id="RUB", rate="1")

    # Категории
    categories_el = ET.SubElement(shop, "categories")
    for cat in CATEGORIES:
        attribs = {"id": cat["id"]}
        if "parentId" in cat:
            attribs["parentId"] = cat["parentId"]
        c_el = ET.SubElement(categories_el, "category", attribs)
        c_el.text = cat["name"]

    # Офферы (услуги)
    offers_el = ET.SubElement(shop, "offers")
    for item in OFFERS:
        offer = ET.SubElement(offers_el, "offer", id=item["id"], available="true")
        ET.SubElement(offer, "url").text = item["url"]
        ET.SubElement(offer, "price").text = item["price"]
        ET.SubElement(offer, "currencyId").text = "RUB"
        ET.SubElement(offer, "categoryId").text = item["category_id"]
        ET.SubElement(offer, "picture").text = f"{BASE_URL}/favicon.png"
        ET.SubElement(offer, "name").text = item["name"]
        ET.SubElement(offer, "description").text = item["description"]

    # Форматирование XML с отступами и заголовком
    xml_str = ET.tostring(yml_catalog, encoding="utf-8")
    parsed_xml = minidom.parseString(xml_str)
    pretty_xml = parsed_xml.toprettyxml(indent="  ", encoding="utf-8")

    # Сохранение в корень проекта
    output_path = os.path.join(os.getcwd(), "services.yml")
    with open(output_path, "wb") as f:
        f.write(pretty_xml)

    print(f"✅ services.yml успешно сгенерирован: {len(OFFERS)} услуг записано.")

if __name__ == "__main__":
    generate_yml()
