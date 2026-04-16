# Find-iQ — OpenCart 3 / OcStore integration module

[![Version](https://img.shields.io/badge/version-0.6-blue)](https://github.com/find-iq/find-iq_CMS_opencart/releases)
[![License](https://img.shields.io/badge/license-Apache%202.0-green)](LICENSE)
[![OpenCart](https://img.shields.io/badge/OpenCart-3.x-orange)](https://www.opencart.com/)

Official module that connects **[Find-iQ](https://find-iq.com)** smart search
and analytics to OpenCart 3 / OcStore shops. It syncs products and categories
with the Find-iQ platform, embeds the live-search widget on the storefront and
exposes a full webhook API for managing indexing.

**See it live:** [demo.find-iq.com](https://demo.find-iq.com) — live demo of the analytics admin panel.

---

## What Find-iQ gives you

- Lightning-fast search with typo tolerance, morphology, synonyms and multilingual support
- AI-powered similar products suggestions
- Autocomplete and query history suggestions
- Flexible filters, search across titles, categories, descriptions and attributes
- Keyboard layout & transliteration tolerant
- Priority boosts for products, brands and categories
- Detailed analytics: popular queries, user behaviour, zero-result queries, conversions
- Analytics export and BI integration
- All search processing runs on Find-iQ servers — your shop stays fast under load
- High-load ready (up to 100 000 requests/second)

---

## Requirements

- OpenCart 3.0.x or OcStore 3.0.x
- PHP 7.4 or newer
- MySQL / MariaDB
- PHP extensions: cURL, GD (both ship with stock PHP)
- `shell_exec` enabled — used to launch the background sync worker

---

## Installation

1. Download the latest release: [find_iq.3.x.ocmod.zip](https://github.com/find-iq/find-iq_CMS_opencart/releases/latest)
2. In OpenCart admin open **Extensions → Extension Installer** and upload the ZIP
3. Open **Extensions → Modifications** and click **Refresh** (the sync icon)
4. Open **Extensions → Modules**, find **Find-iQ Integration** and click **Install**

---

## Configuration

1. Register your shop in the [Find-iQ admin panel](https://app.find-iq.com) — you get a secret token
2. Open **Find-iQ Integration** in OpenCart admin
3. Paste the token into the **Token** field and save
4. Adjust fast / full reindex intervals to match your workload
5. Pick an image processor — **Built-in (GD)** is recommended; use **OpenCart** if you need the native image library
6. Run the first full sync via the webhook (copy-paste URL from the Documentation tab)

---

## Webhook API

Base URL: `https://your-shop/index.php?route=find_iq/webhook&secret=TOKEN`

| Action | Purpose |
|---|---|
| `start&mode=full&actions=categories,products` | Full sync — new products, changed products and reindex |
| `start&mode=fast&actions=products` | Fast sync — price and stock only |
| `status` | Current sync progress |
| `stop` | Halt the sync after the current batch |
| `frontend` | Refresh the search widget JS / CSS |

Ready-to-copy URLs with the token pre-filled appear on the **Documentation**
tab of the module admin page.

Full parameter reference: [Wiki → Webhook API](https://github.com/find-iq/find-iq_CMS_opencart/wiki/Webhook-API).

---

## Admin panel features

- Module status and current version with upstream check
- Cron log viewer with clear and download buttons
- Ready-to-copy webhook URLs with a one-click copy button
- Image resize size selector (200 / 400 / 600 px)
- Fast / full reindex interval settings
- Image processor choice (GD or OpenCart)

---

## Contacts & resources

- 👉 Website: [find-iq.com](https://find-iq.com)
- 📈 Admin panel: [app.find-iq.com](https://app.find-iq.com)
- 🧪 Analytics demo: [demo.find-iq.com](https://demo.find-iq.com)
- 📄 [API documentation](https://api.find-iq.com/v.2/api/public/docs)
- ✈️ Contact us: [@find_iq_com_bot](https://t.me/find_iq_com_bot)
- 🔵 [Telegram channel](https://t.me/find_iq)
- 🎞 [YouTube channel](https://www.youtube.com/channel/UCu-jD1QWrJcvnxl6dqwCPPg)
- 🐱 [GitHub](https://github.com/find-iq)

---

## Changelog

See [GitHub Releases](https://github.com/find-iq/find-iq_CMS_opencart/releases).

Current version: **0.6** — fire-and-forget webhook, self-respawn worker, built-in GD image resizer, quick webhook URLs in the admin.

---

## License

Distributed under the [Apache 2.0](LICENSE) license.
