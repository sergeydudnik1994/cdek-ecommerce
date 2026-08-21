import os
from datetime import datetime
import xml.etree.ElementTree as ET

BASE_URL = "https://cdek-ecommerce.ru"
ROOT_DIR = "."
EXCLUDE_DIRS = {"components", ".github", "scripts", "node_modules", ".git"}
EXCLUDE_FILES = {"404.html", "google", "yandex"}

urls = []
today = datetime.now().strftime("%Y-%m-%d")

for root, dirs, files in os.walk(ROOT_DIR):
    dirs[:] = [d for d in dirs if d not in EXCLUDE_DIRS and not d.startswith(".")]
    
    for file in files:
        if not file.endswith(".html"):
            continue
        if any(exc in file for exc in EXCLUDE_FILES):
            continue

        rel_path = os.path.relpath(os.path.join(root, file), ROOT_DIR).replace("\\", "/")
        
        # Формирование чистых URL
        if rel_path == "index.html":
            loc = f"{BASE_URL}/"
            priority = "1.0"
        elif rel_path.endswith("index.html"):
            slug = os.path.dirname(rel_path).replace("\\", "/").strip("/")
            loc = f"{BASE_URL}/{slug}/"
            priority = "0.9" if slug == "dogovor" else "0.8"
        else:
            slug = rel_path[:-5].strip("/")
            loc = f"{BASE_URL}/{slug}/"
            priority = "0.7"

        # Принудительная защита от двойных слэшей в теле URL
        domain_part = loc[:8]
        path_part = loc[8:].replace("//", "/")
        while "//" in path_part:
            path_part = path_part.replace("//", "/")
        loc = domain_part + path_part

        urls.append({"loc": loc, "lastmod": today, "priority": priority})

# Удаление возможных дублей
unique_urls = []
seen = set()
for item in urls:
    if item["loc"] not in seen:
        seen.add(item["loc"])
        unique_urls.append(item)

# Сортировка по приоритету
unique_urls.sort(key=lambda x: float(x["priority"]), reverse=True)

# Формирование структуры XML
urlset = ET.Element("urlset", xmlns="http://www.sitemaps.org/schemas/sitemap/0.9")

for item in unique_urls:
    url_elem = ET.SubElement(urlset, "url")
    ET.SubElement(url_elem, "loc").text = item["loc"]
    ET.SubElement(url_elem, "lastmod").text = item["lastmod"]
    ET.SubElement(url_elem, "changefreq").text = "weekly"
    ET.SubElement(url_elem, "priority").text = item["priority"]

tree = ET.ElementTree(urlset)
ET.indent(tree, space="  ", level=0)

with open("sitemap.xml", "wb") as f:
    tree.write(f, encoding="utf-8", xml_declaration=True)

print(f"✓ sitemap.xml успешно сгенерирован. Всего страниц: {len(unique_urls)}")
