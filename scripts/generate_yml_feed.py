import os
from datetime import datetime, timezone, timedelta
import xml.etree.ElementTree as ET
from xml.dom import minidom

BASE_URL = "https://cdek-ecommerce.ru"
SHOP_NAME = "CDEK E-Commerce"
COMPANY_NAME = "CDEK E-Commerce"

# Стандартные категории услуг логистики
CATEGORIES = [
    {"id": "1", "name": "Курьерские и логистические услуги"},
    {"id": "2", "parentId": "1", "name": "Доставка для интернет-магазинов"},
    {"id": "3", "parentId": "1", "name": "Доставка для маркетплейсов"},
    {"id": "4", "parentId": "1", "name": "Грузоперевозки и спецдоставка"},
    {"id": "5", "parentId": "1", "name": "Корпоративное обслуживание B2B"}
]

# Офферы со строгим соответствием правилам модерации Яндекса
OFFERS = [
    {
        "id": "srv_ecom",
        "category_id": "2",
        "url": f"{BASE_URL}/dogovor/internet-magazin/",
        "price": "205",
        "name": "Курьерская доставка и выдача в ПВЗ для интернет-магазинов",
        "description": "Доставка заказов интернет-магазинов по тарифу «Посылка» (от 205 руб. по городу до 1 кг). Прием наложенного платежа с формированием чеков по 54-ФЗ, забор курьером и выдача через сеть ПВЗ."
    },
    {
        "id": "srv_market",
        "category_id": "3",
        "url": f"{BASE_URL}/dogovor/marketplace/",
        "price": "205",
        "name": "Доставка заказов с маркетплейсов по схемам FBS и DBS",
        "description": "Логистическое обслуживание селлеров Wildberries, Ozon и Яндекс Маркет. Отгрузка отправлений по схемам FBS, rFBS и DBS с автоматической синхронизацией трек-номеров по API."
    },
    {
        "id": "srv_clothes",
        "category_id": "4",
        "url": f"{BASE_URL}/services/dostavka-odezhdy/",
        "price": "250",
        "name": "Доставка одежды и обуви с примеркой и частичным выкупом",
        "description": "Курьерская доставка и обслуживание в пунктах выдачи заказов с возможностью примерки до 15 минут, частичного выкупа позиций и возврата неподошедших товаров."
    },
    {
        "id": "srv_fulf",
        "category_id": "4",
        "url": f"{BASE_URL}/services/fulfillment/",
        "price": "1500",
        "name": "Складское хранение, комплектация и фулфилмент для e-commerce",
        "description": "Комплекс складских операций: приемка партий, ответственное хранение, маркировка товаров Честный Знак, сборка заказов, упаковка и передача в доставку."
    },
    {
        "id": "srv_heavy",
        "category_id": "4",
        "url": f"{BASE_URL}/dogovor/krupnogabarit/",
        "price": "450",
        "name": "Доставка крупногабаритных грузов и оборудования",
        "description": "Транспортировка тяжелых и объемных отправлений по России. Забор с адреса отправителя грузовым транспортом, отслеживание по трек-номеру и доставка до двери получателя."
    },
    {
        "id": "srv_parts",
        "category_id": "4",
        "url": f"{BASE_URL}/dogovor/avtozapchasti/",
        "price": "250",
        "name": "Доставка автозапчастей и комплектующих для интернет-магазинов",
        "description": "Транспортная логистика для магазинов автотоваров и станций техобслуживания: перевозка деталей, кузовных элементов и расходных материалов с кассовым обслуживанием."
    },
    {
        "id": "srv_ip",
        "category_id": "5",
        "url": f"{BASE_URL}/dogovor/ip/",
        "price": "205",
        "name": "Оформление B2B-договора СДЭК для индивидуальных предпринимателей",
        "description": "Подключение ИП к корпоративным тарифам СДЭК: тарифная сетка УСН без начисления НДС 22%, ежемесячная постоплата по счету, доступ к наложенному платежу и интеграции по API."
    },
    {
        "id": "srv_self",
        "category_id": "5",
        "url": f"{BASE_URL}/dogovor/samozanyatye/",
        "price": "205",
        "name": "Оформление договора СДЭК для самозанятых граждан (НПД)",
        "description": "Регистрация договора доставки для плательщиков налога на профессиональный доход. Корпоративные тарифы на отправку посылок, интеграция по API, оплата банковской картой в ЛК."
    },
    {
        "id": "srv_llc",
        "category_id": "5",
        "url": f"{BASE_URL}/dogovor/yur-lica/",
        "price": "205",
        "name": "Оформление B2B-договора СДЭК для юридических лиц (ООО, АО)",
        "description": "Корпоративное обслуживание коммерческих организаций: обмен закрывающими документами через системы ЭДО (Диадок, СБИС), выбор налоговой ставки (УСН или ОСНО с НДС)."
    }
]

def generate_yml():
    msk_tz = timezone(timedelta(hours=3))
    now_iso = datetime.now(msk_tz).strftime("%Y-%m-%dT%H:%M:%S+03:00")

    yml_catalog = ET.Element("yml_catalog", date=now_iso)
    shop = ET.SubElement(yml_catalog, "shop")

    ET.SubElement(shop, "name").text = SHOP_NAME
    ET.SubElement(shop, "company").text = COMPANY_NAME
    ET.SubElement(shop, "url").text = BASE_URL

    currencies = ET.SubElement(shop, "currencies")
    ET.SubElement(currencies, "currency", id="RUB", rate="1")

    categories_el = ET.SubElement(shop, "categories")
    for cat in CATEGORIES:
        attribs = {"id": cat["id"]}
        if "parentId" in cat:
            attribs["parentId"] = cat["parentId"]
        c_el = ET.SubElement(categories_el, "category", attribs)
        c_el.text = cat["name"]

    offers_el = ET.SubElement(shop, "offers")
    for item in OFFERS:
        offer = ET.SubElement(offers_el, "offer", id=item["id"], available="true")
        ET.SubElement(offer, "url").text = item["url"]
        ET.SubElement(offer, "price").text = item["price"]
        ET.SubElement(offer, "currencyId").text = "RUB"
        ET.SubElement(offer, "categoryId").text = item["category_id"]
        # Ссылка на полноразмерное фоновое изображение (1200x630) вместо фавиконки
        ET.SubElement(offer, "picture").text = f"{BASE_URL}/og-image.png"
        ET.SubElement(offer, "name").text = item["name"]
        ET.SubElement(offer, "description").text = item["description"]

    xml_str = ET.tostring(yml_catalog, encoding="utf-8")
    parsed_xml = minidom.parseString(xml_str)
    pretty_xml = parsed_xml.toprettyxml(indent="  ", encoding="utf-8")

    output_path = os.path.join(os.getcwd(), "services.yml")
    with open(output_path, "wb") as f:
        f.write(pretty_xml)

    print(f"services.yml успешно обновлен: {len(OFFERS)} услуг подготовлены по стандартам модерации.")

if __name__ == "__main__":
    generate_yml()
