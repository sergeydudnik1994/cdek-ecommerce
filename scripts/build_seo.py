import os
import json
import sys
from datetime import date

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
ROOT_DIR = os.path.abspath(os.path.join(SCRIPT_DIR, ".."))

DOMAIN = "https://cdek-ecommerce.ru"
TODAY = date.today().isoformat()

def generate_html(p):
    breadcrumbs_schema = {
        "@context": "https://schema.org",
        "@type": "BreadcrumbList",
        "itemListElement": [
            {
                "@type": "ListItem",
                "position": 1,
                "name": "Главная",
                "item": f"{DOMAIN}/"
            },
            {
                "@type": "ListItem",
                "position": 2,
                "name": p["category_name"],
                "item": f"{DOMAIN}{p['category_url']}"
            },
            {
                "@type": "ListItem",
                "position": 3,
                "name": p["page_name"],
                "item": f"{DOMAIN}/{p['slug']}/"
            }
        ]
    }

    faq_schema = {
        "@context": "https://schema.org",
        "@type": "FAQPage",
        "mainEntity": [
            {
                "@type": "Question",
                "name": item["q"],
                "acceptedAnswer": {
                    "@type": "Answer",
                    "text": item["a"]
                }
            } for item in p.get("faq", [])
        ]
    }

    features_html = "".join([f"""
      <li class="flex items-start gap-3">
        <span class="w-6 h-6 rounded-full bg-[#1AB248]/10 text-[#1AB248] flex items-center justify-center flex-shrink-0 mt-0.5 font-bold text-sm">✓</span>
        <span class="text-slate-700 text-sm sm:text-base leading-relaxed">{f}</span>
      </li>
    """ for f in p["features"]])

    faq_html = "".join([f"""
      <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm">
        <h4 class="text-base font-bold text-slate-900 mb-2">{item['q']}</h4>
        <p class="text-sm text-slate-600 leading-relaxed">{item['a']}</p>
      </div>
    """ for item in p.get("faq", [])])

    return f"""<!DOCTYPE html>
<html lang="ru" class="scroll-smooth">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{p['title']}</title>
  <meta name="description" content="{p['description']}" />
  <link rel="canonical" href="{DOMAIN}/{p['slug']}/" />

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>body {{ font-family: 'Roboto', sans-serif; }}</style>

  <!-- Schema.org Microdata -->
  <script type="application/ld+json">
  {json.dumps(breadcrumbs_schema, ensure_ascii=False)}
  </script>
  <script type="application/ld+json">
  {json.dumps(faq_schema, ensure_ascii=False)}
  </script>
</head>
<body class="bg-[#F5F8FE] text-slate-900 min-h-screen flex flex-col antialiased">

  <!--# include virtual="/components/header.html" -->

  <main class="flex-grow max-w-5xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12 space-y-8">
    
    <!-- Хлебные крошки в UI -->
    <nav class="flex items-center gap-2 text-xs sm:text-sm text-slate-400">
      <a href="/" class="hover:text-[#1AB248] transition">Главная</a>
      <span>/</span>
      <span>{p['category_name']}</span>
      <span>/</span>
      <span class="text-slate-700 font-semibold">{p['page_name']}</span>
    </nav>

    <!-- Главный блок -->
    <div class="bg-white rounded-3xl p-6 sm:p-10 shadow-[0_4px_20px_rgba(0,0,0,0.04)] border border-slate-200/80 space-y-6">
      <span class="inline-block px-3.5 py-1.5 rounded-full text-xs font-bold uppercase tracking-wider bg-emerald-50 text-[#1AB248] border border-emerald-200">
        {p['badge']}
      </span>
      <h1 class="text-2xl sm:text-4xl font-extrabold text-slate-900 tracking-tight leading-tight">
        {p['h1']}
      </h1>
      <p class="text-slate-600 text-base sm:text-lg leading-relaxed">
        {p['description']}
      </p>

      <div class="pt-4 border-t border-slate-100">
        <h3 class="text-lg font-bold text-slate-900 mb-4">Возможности и преимущества решения:</h3>
        <ul class="space-y-3">
          {features_html}
        </ul>
      </div>

      <div class="pt-6">
        <a href="/#steps" class="inline-flex items-center justify-center px-8 py-4 bg-[#1AB248] hover:bg-[#158E3A] text-white font-bold text-base rounded-2xl shadow-lg shadow-[#1AB248]/25 transition transform active:scale-95">
          Подключить со скидкой до 50%
        </a>
      </div>
    </div>

    <!-- Вопросы и ответы -->
    <div class="space-y-4">
      <h2 class="text-xl sm:text-2xl font-bold text-slate-900">Часто задаваемые вопросы</h2>
      <div class="space-y-3">
        {faq_html}
      </div>
    </div>

  </main>

  <!--# include virtual="/components/footer.html" -->

</body>
</html>"""

def build_all():
    data_path = os.path.join(SCRIPT_DIR, "pages_data.json")
    if not os.path.exists(data_path):
        print(f"Error: {data_path} not found")
        sys.exit(1)

    with open(data_path, "r", encoding="utf-8") as f:
        pages = json.load(f)

    all_urls = [f"{DOMAIN}/", f"{DOMAIN}/tracking/"]

    for p in pages:
        dir_path = os.path.join(ROOT_DIR, p["slug"])
        os.makedirs(dir_path, exist_ok=True)
        file_path = os.path.join(dir_path, "index.html")
        
        with open(file_path, "w", encoding="utf-8") as out:
            out.write(generate_html(p))
            
        all_urls.append(f"{DOMAIN}/{p['slug']}/")
        print(f"✓ Generated: {file_path}")

    # Обновление sitemap.xml
    sitemap_path = os.path.join(ROOT_DIR, "sitemap.xml")
    sitemap_content = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">']
    for url in all_urls:
        priority = "1.0" if url == f"{DOMAIN}/" else ("0.9" if "tracking" in url else "0.8")
        sitemap_content.append(f"""  <url>
    <loc>{url}</loc>
    <lastmod>{TODAY}</lastmod>
    <changefreq>weekly</changefreq>
    <priority>{priority}</priority>
  </url>""")
    sitemap_content.append('</urlset>')

    with open(sitemap_path, "w", encoding="utf-8") as sm:
        sm.write("\n".join(sitemap_content))
    print(f"✓ sitemap.xml updated with {len(all_urls)} URLs")

if __name__ == "__main__":
    build_all()
