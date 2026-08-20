import urllib.request
import xml.etree.ElementTree as ET
import json
import os

ENDPOINTS = [
    ("IndexNow Central Hub", "https://api.indexnow.org/indexnow"),
    ("Yandex", "https://yandex.com/indexnow"),
    ("Microsoft Bing", "https://www.bing.com/indexnow"),
]

DOMAIN = "cdek-ecommerce.ru"
KEY = "cdek2026ecommercekey"
KEY_LOCATION = f"https://{DOMAIN}/cdek2026ecommercekey.txt"

# 1. Базовый резервный список URL
urls = [
    f"https://{DOMAIN}/",
    f"https://{DOMAIN}/calculator/",
    f"https://{DOMAIN}/tracking/",
    f"https://{DOMAIN}/policy/",
    f"https://{DOMAIN}/integrations/dbs/",
    f"https://{DOMAIN}/integrations/tilda/",
    f"https://{DOMAIN}/integrations/bitrix/",
    f"https://{DOMAIN}/integrations/moysklad/",
    f"https://{DOMAIN}/integrations/insales/",
    f"https://{DOMAIN}/integrations/woocommerce/",
    f"https://{DOMAIN}/integrations/opencart/",
    f"https://{DOMAIN}/integrations/1c/",
    f"https://{DOMAIN}/integrations/api/",
    f"https://{DOMAIN}/services/dostavka-odezhdy/",
    f"https://{DOMAIN}/services/fulfillment/"
]

# 2. Подтягиваем все URL из локального sitemap.xml, если он существует
sitemap_path = os.path.join(os.path.dirname(__file__), "..", "sitemap.xml")
if os.path.exists(sitemap_path):
    try:
        tree = ET.parse(sitemap_path)
        root = tree.getroot()
        # Парсим пространство имен sitemap
        namespaces = {'ns': 'http://www.sitemaps.org/schemas/sitemap/0.9'}
        sitemap_urls = [elem.text.strip() for elem in root.findall('.//ns:loc', namespaces)]
        if sitemap_urls:
            urls = list(set(urls + sitemap_urls))
            print(f"📦 Загружено {len(sitemap_urls)} URL из sitemap.xml")
    except Exception as e:
        print(f"⚠️ Ошибка парсинга sitemap.xml: {e}")

# Ограничение IndexNow: не более 10 000 URL за один запрос
urls = sorted(list(set(urls)))[:10000]

payload = {
    "host": DOMAIN,
    "key": KEY,
    "keyLocation": KEY_LOCATION,
    "urlList": urls
}

data = json.dumps(payload).encode("utf-8")
headers = {"Content-Type": "application/json; charset=utf-8"}

print(f"🚀 Отправка {len(urls)} URL в поисковые системы...")

for name, endpoint in ENDPOINTS:
    req = urllib.request.Request(endpoint, data=data, headers=headers)
    try:
        with urllib.request.urlopen(req) as response:
            code = response.getcode()
            if code in (200, 202):
                print(f"✅ {name}: Успешно принято ({code})")
            else:
                print(f"⚠️ {name}: Ответ {code}")
    except Exception as e:
        print(f"❌ {name}: Ошибка ({e})")
