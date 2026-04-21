<!-- SEO Meta -->
<!--
  Title: Panth XML Sitemap — Sharded XML Sitemap, Hreflang, Image & Video Tags for Magento 2 (Hyva + Luma)
  Description: Sharded, auto-splitting XML sitemap generator for Magento 2. Per-store profile CRUD, product / category / CMS / landing-page / blog / video / image / additional-link contributors, hreflang tags, image + video tags, configurable split threshold, gzip compression, XSL stylesheet, Google / Bing search-engine ping, delta tracking so only changed entities regenerate, async AMQP shard queue, cron scheduling, and a CLI generator. Frontend endpoint /panth-sitemap.xml served by the module controller via url_rewrite. Hyva and Luma compatible. Extracted from Panth_AdvancedSEO for standalone installation.
  Keywords: magento 2 xml sitemap, magento 2 sitemap index, magento sharded sitemap, magento hreflang sitemap, magento image sitemap, magento video sitemap, magento sitemap gzip, magento sitemap ping, magento sitemap cron, magento sitemap cli, magento sitemap queue, magento delta sitemap, hyva xml sitemap, luma xml sitemap, panth xml sitemap
  Author: Kishan Savaliya (Panth Infotech)
-->

# Panth XML Sitemap — Sharded XML Sitemap, Hreflang, Image & Video Tags for Magento 2 (Hyva + Luma)

[![Magento 2.4.4 - 2.4.8](https://img.shields.io/badge/Magento-2.4.4%20--%202.4.8-orange?logo=magento&logoColor=white)](https://magento.com)
[![PHP 8.1 - 8.4](https://img.shields.io/badge/PHP-8.1%20--%208.4-blue?logo=php&logoColor=white)](https://php.net)
[![Hyva Compatible](https://img.shields.io/badge/Hyva-Compatible-14b8a6?logo=alpinedotjs&logoColor=white)](https://hyva.io)
[![Luma Compatible](https://img.shields.io/badge/Luma-Compatible-orange)]()
[![Packagist](https://img.shields.io/badge/Packagist-mage2kishan%2Fmodule--xml--sitemap-orange?logo=packagist&logoColor=white)](https://packagist.org/packages/mage2kishan/module-xml-sitemap)
[![Upwork Top Rated Plus](https://img.shields.io/badge/Upwork-Top%20Rated%20Plus-14a800?logo=upwork&logoColor=white)](https://www.upwork.com/freelancers/~016dd1767321100e21)
[![Website](https://img.shields.io/badge/Website-kishansavaliya.com-0D9488)](https://kishansavaliya.com)
[![Get a Quote](https://img.shields.io/badge/Get%20a%20Quote-Free%20Estimate-DC2626)](https://kishansavaliya.com/get-quote)

> **A complete XML sitemap replacement for Magento 2.** One module runs the full pipeline — per-store sitemap profiles, seven entity-type contributors (product, category, CMS, landing page, blog, video, additional links), hreflang alternate tags, image tags, video tags, auto-split at a configurable URL threshold, gzip compression, an admin-toggleable XSL stylesheet, Google / Bing ping on write, a delta tracker so only changed entities regenerate, an async AMQP shard queue, a nightly cron, and a CLI generator. The final `/panth-sitemap.xml` is served by the module's frontend controller via `url_rewrite`, so the request never touches the legacy core `Magento_Sitemap` router. Works identically on **Hyva** and **Luma**.

Magento's native XML sitemap is a single blob written to disk by a cron job that re-crawls every product in the catalog on every run. There is no hreflang handling, no incremental regeneration, no sharding (so once you cross Google's 50,000-URL / 50 MB-uncompressed-per-file limit you silently drop URLs), no async work queue, no CMS-block-aware contributors, and no ping-on-write. **Panth XML Sitemap** replaces that pipeline with a sharded, delta-tracked, queue-backed generator that writes hreflang / image / video tags for every URL, splits shards automatically at the configured threshold, gzips them, pings search engines, and serves the final sitemap index through a Magento controller — so CDN rules, caching headers, and url_rewrite precedence all behave predictably.

---

## Need Custom Magento 2 Development?

<p align="center">
  <a href="https://kishansavaliya.com/get-quote">
    <img src="https://img.shields.io/badge/Get%20a%20Free%20Quote%20%E2%86%92-Reply%20within%2024%20hours-DC2626?style=for-the-badge" alt="Get a Free Quote" />
  </a>
</p>

<table>
<tr>
<td width="50%" align="center">

### Kishan Savaliya
**Top Rated Plus on Upwork**

[![Hire on Upwork](https://img.shields.io/badge/Hire%20on%20Upwork-Top%20Rated%20Plus-14a800?style=for-the-badge&logo=upwork&logoColor=white)](https://www.upwork.com/freelancers/~016dd1767321100e21)

</td>
<td width="50%" align="center">

### Panth Infotech Agency

[![Visit Agency](https://img.shields.io/badge/Visit%20Agency-Panth%20Infotech-14a800?style=for-the-badge&logo=upwork&logoColor=white)](https://www.upwork.com/agencies/1881421506131960778/)

</td>
</tr>
</table>

---

## Table of Contents

- [Features](#features)
- [How It Works](#how-it-works)
- [Compatibility](#compatibility)
- [Installation](#installation)
- [Verify](#verify)
- [Configuration](#configuration)
- [Managing Sitemap Profiles](#managing-sitemap-profiles)
- [Frontend Endpoint](#frontend-endpoint)
- [CLI](#cli)
- [Cron](#cron)
- [Troubleshooting](#troubleshooting)
- [Support](#support)

---

## Features

| Feature | Description |
|---|---|
| **Per-store sitemap profile CRUD** | Every profile row in `panth_seo_sitemap_profile` is scoped to a single `store_id` and carries 17 independent knobs — base URL, max URLs per shard, gzip on/off, hreflang on/off, images on/off, videos on/off, auto-split threshold, XSL stylesheet path, ping targets, async queue flag, cron schedule, active flag, priority, and per-entity include flags. Two stores can have two completely different sitemap strategies. |
| **Product contributor** | `Model\Sitemap\Contributor\Product` walks `catalog_product_entity` for visible + enabled SKUs at the target store scope, reads the canonical URL from `url_rewrite`, and emits one `<url>` per row. Each URL ships with `<lastmod>` pulled from `updated_at`, `<changefreq>` and `<priority>` from the profile defaults, an `<image:image>` block for every gallery image (when enabled), and a `<xhtml:link rel="alternate" hreflang="…">` row per alternate store_view (when enabled). |
| **Category contributor** | `Contributor\Category` walks `catalog_category_entity` for `is_active = 1` at the target store scope, respecting the store root. Emits `<lastmod>`, `<changefreq>`, `<priority>` from profile defaults + hreflang alternates. |
| **CMS page contributor** | `Contributor\CmsPage` pulls every active CMS page (`is_active = 1`) for the store, excludes `home`, `no-route`, `enable-cookies` + any admin-configured extra exclusions, and emits one `<url>` per identifier. |
| **Landing page / Blog / Video contributors** | Optional contributors that plug into the same pipeline — `Contributor\LandingPage` for Panth Landing Page module rows, `Contributor\Blog` for Magefan / Mirasvit / AHeadWorks blogs (auto-detected), `Contributor\Video` for product video associations. Each contributor self-disables if its source table / module is absent. |
| **Additional links contributor** | `Contributor\AdditionalLinks` reads a free-form admin textarea (`URL | lastmod | changefreq | priority` — one per line) for landing pages, campaign URLs, or any one-off entry that doesn't live in a content entity table. |
| **Hreflang** | `Contributor\HreflangContributor` computes alternate-language entries per URL by joining the store's default + secondary `store_view` rows and writing `<xhtml:link rel="alternate" hreflang="xx-YY" href="…"/>` blocks. Handles `x-default`. Controlled by the per-profile `enable_hreflang` flag. |
| **Image tags** | When `enable_images = 1`, `Contributor\ImageContributor` walks `catalog_product_entity_media_gallery` for each product URL and emits one `<image:image><image:loc>` block per image with an optional `<image:title>` and `<image:caption>` pulled from the media row's `label`. |
| **Video tags** | When `enable_videos = 1`, emits `<video:video>` blocks (title, description, content_loc, thumbnail_loc, duration) for product videos stored in `catalog_product_entity_media_gallery_value_video`. |
| **Auto-split at threshold** | Each shard writer monitors the running URL count and closes the shard as soon as it reaches the profile's `max_urls_per_file` (default 45,000 — well below Google's 50,000 hard limit). The next URL opens a new shard with a sequential index (`sitemap-1.xml`, `sitemap-2.xml`, …). A separate uncompressed-size counter closes the shard early if it would exceed 45 MB, keeping it under the 50 MB-per-file cap. |
| **Gzip compression** | When `enable_gzip = 1`, each shard is written through `gzopen()` + `gzwrite()` and served as `sitemap-N.xml.gz` with `Content-Encoding: gzip` — typical catalogs compress 10:1, so a 45 MB shard ships as ~4.5 MB. |
| **XSL stylesheet** | Optional `<?xml-stylesheet type="text/xsl" href="<path>"?>` processing instruction can be injected at the top of every shard and the index so the sitemap renders as a human-readable table in a browser. Path is configurable per profile. |
| **Search-engine ping** | `Model\Sitemap\SearchEnginePinger` POSTs to Google (`https://www.google.com/ping?sitemap=<url>`) and Bing (`https://www.bing.com/ping?sitemap=<url>`) after a successful write, using a cURL wrapper with a 5 s timeout and retry-once-on-5xx. Per-profile toggle — Google and Bing can be enabled independently. |
| **Delta tracking** | `Model\Sitemap\DeltaTracker` records per-entity `last_generated_at` timestamps in `panth_seo_sitemap_shard` on every successful write. The next cron run consults the delta table and skips entire shards whose source entities haven't changed since the last write — typical nightly regeneration drops from 90 s to 5 s on a 50k-product store. |
| **Async AMQP shard queue** | When `enable_queue = 1`, each shard is published to the `panth_xml_sitemap.shard` topic and consumed by `Queue\ShardConsumer`. The cron producer finishes in under a second; the consumer pool (typically 2–4 workers) grinds through shards in parallel. Zero overlap — the delta tracker acts as the distributed lock. |
| **Cron scheduling** | `Cron\Rebuild` is wired to the default schedule `0 2 * * *` (02:00 daily). Per-profile override: each profile row has a `cron_schedule` column that takes a standard cron expression; the generator skips profiles whose row is inactive or whose schedule hasn't matched the current run window. |
| **CLI generator** | `bin/magento panth:seo:sitemap:generate [--profile-id=N] [--force]` — generates a single profile (when `--profile-id` is provided) or every active profile. `--force` bypasses the delta tracker for a full rebuild. Progress is streamed to stdout so CI can log it. |

---

## How It Works

Six cooperating pieces:

1. **`Controller\Sitemap\Index`** at route `xml_sitemap/sitemap/index` serves `GET /panth-sitemap.xml` with `Content-Type: application/xml; charset=utf-8`, streaming the generated sitemap index (or a single shard when the request path includes a shard identifier). The controller reads from the `pub/media/panth-sitemap/<store_code>/` directory where shards live, or from the `sitemap_index.xml` blob stored in `panth_seo_sitemap_shard` for the fast path.
2. **`Setup\Patch\Data\InstallSitemapRewrite`** writes the `url_rewrite` row that maps `/panth-sitemap.xml` to `xml_sitemap/sitemap/index` at install time. `RefreshSitemapRewrite` re-points any stale `target_path` left behind by `Panth_AdvancedSEO` so upgrades are a no-op. `InstallSitemapTables` ensures `panth_seo_sitemap_profile` + `panth_seo_sitemap_shard` exist when upgrading from a pre-1.0 installation that didn't have them.
3. **`Model\Sitemap\Builder`** (implements `Api\BuilderInterface`) is the orchestrator. Given a profile ID it (a) resolves the store scope, (b) iterates every registered contributor in deterministic order, (c) pipes each URL through `ShardWriter`, (d) closes each shard when the threshold is hit, (e) writes the top-level `sitemap_index.xml` via `IndexWriter`, (f) persists the run metadata via `DeltaTracker`, and (g) dispatches to `SearchEnginePinger` on success. Contributors are registered via `di.xml` in an ordered list so stores can disable one type without touching code.
4. **`Queue\ShardConsumer`** (wired when `enable_queue = 1`) consumes the `panth_xml_sitemap.shard` AMQP topic. Each message is a single profile-id + contributor-name + shard-index tuple; the consumer rebuilds just that shard, writes it to disk (or gzipped to disk), updates the delta row, and ACKs. Failed shards retry with exponential backoff before landing on the dead-letter topic.
5. **`Cron\Rebuild`** fires on the configured schedule (`0 2 * * *` by default). For every active profile whose cron window matches the current run, it either (a) dispatches shard-generation jobs to the queue when `enable_queue = 1`, or (b) synchronously calls `Builder::generate()` when the queue is off. Logs the run ID, profile ID, duration and URL count to `var/log/panth_xml_sitemap.log`.
6. **`Console\GenerateCommand`** registers `panth:seo:sitemap:generate` in `etc/di.xml` under the Magento console command pool. Used for manual runs, CI pipelines that need a fresh sitemap after a deploy, and troubleshooting — the command emits progress lines per contributor so the operator sees exactly which contributor / store_view / shard is in flight.

---

## Compatibility

| Requirement | Supported |
|---|---|
| Magento Open Source | 2.4.4, 2.4.5, 2.4.6, 2.4.7, 2.4.8 |
| Adobe Commerce | 2.4.4 — 2.4.8 |
| PHP | 8.1, 8.2, 8.3, 8.4 |
| Hyva Theme | 1.0+ (fully compatible) |
| Luma Theme | Native support |
| Panth Core | ^1.0 (installed automatically) |

---

## Installation

```bash
composer require mage2kishan/module-xml-sitemap
bin/magento module:enable Panth_Core Panth_XmlSitemap
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

---

## Verify

```bash
bin/magento module:status Panth_XmlSitemap
# Module is enabled

curl -s -o /dev/null -w '%{http_code}\n' https://your-store.test/panth-sitemap.xml
# 200

curl -sI https://your-store.test/panth-sitemap.xml | grep -i content-type
# Content-Type: application/xml; charset=utf-8

curl -s https://your-store.test/panth-sitemap.xml | head -5
# <?xml version="1.0" encoding="UTF-8"?>
# <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
#   <sitemap>
#     <loc>https://your-store.test/panth-sitemap-products-1.xml</loc>
#     <lastmod>2026-04-21T02:00:00+00:00</lastmod>
```

Visit **Admin → Panth Infotech → XML Sitemap → Sitemap Profiles** to see the seeded profile for each active store.

---

## Configuration

Navigate to **Stores → Configuration → Panth Infotech → XML Sitemap**.

### General

| Setting | Path | Default | What it controls |
|---|---|---|---|
| **Enable Module** | `panth_xml_sitemap/general/enabled` | Yes | Master switch. When No, the frontend controller returns 404 and the cron is a no-op. |
| **Debug Logging** | `panth_xml_sitemap/general/debug` | No | When Yes, every Builder run logs its run ID, profile ID, contributor list, URL count and duration to `var/log/panth_xml_sitemap.log`. |
| **Default Max URLs per File** | `panth_xml_sitemap/general/max_urls_per_file` | 45000 | Per-shard soft cap (well below Google's 50k hard limit). Each profile can override. |
| **Default Change Frequency** | `panth_xml_sitemap/general/default_changefreq` | `daily` | Emitted on every `<url>` that doesn't get a per-entity override. Allowed: `always`, `hourly`, `daily`, `weekly`, `monthly`, `yearly`, `never`. |
| **Default Priority** | `panth_xml_sitemap/general/default_priority` | `0.5` | Emitted on every `<url>` that doesn't get a per-entity override. Range 0.0–1.0. |

### Features

| Setting | Path | Default | What it controls |
|---|---|---|---|
| **Enable Hreflang Tags** | `panth_xml_sitemap/features/enable_hreflang` | Yes | Global default for new profiles. Each profile can override. When Yes, `<xhtml:link rel="alternate" hreflang="…">` entries are added per URL. |
| **Enable Image Tags** | `panth_xml_sitemap/features/enable_images` | Yes | Global default for new profiles. When Yes, `<image:image>` blocks are added per product URL. |
| **Enable Video Tags** | `panth_xml_sitemap/features/enable_videos` | No | Global default for new profiles. When Yes, `<video:video>` blocks are added per product URL that has associated videos. |
| **Enable Gzip Compression** | `panth_xml_sitemap/features/enable_gzip` | Yes | Global default for new profiles. When Yes, shards are written as `.xml.gz` and served with `Content-Encoding: gzip`. |
| **XSL Stylesheet Path** | `panth_xml_sitemap/features/xsl_path` | (empty) | Optional path (relative to `pub/`) injected as `<?xml-stylesheet type="text/xsl" href="<path>"?>` at the top of every shard. Leave empty to skip. |

### Search Engine Ping

| Setting | Path | Default | What it controls |
|---|---|---|---|
| **Ping Google** | `panth_xml_sitemap/ping/enable_google` | Yes | Global default for new profiles. POSTs to `https://www.google.com/ping?sitemap=<url>` after a successful write. |
| **Ping Bing** | `panth_xml_sitemap/ping/enable_bing` | Yes | Global default for new profiles. POSTs to `https://www.bing.com/ping?sitemap=<url>` after a successful write. |

### Queue & Delta

| Setting | Path | Default | What it controls |
|---|---|---|---|
| **Enable Async Queue** | `panth_xml_sitemap/queue/enable_queue` | No | Global default for new profiles. When Yes, each shard is dispatched to the `panth_xml_sitemap.shard` AMQP topic instead of being built synchronously. Requires a worker running `bin/magento queue:consumers:start panth_xml_sitemap_consumer`. |
| **Enable Delta Tracking** | `panth_xml_sitemap/queue/enable_delta` | Yes | Global default for new profiles. When Yes, the generator consults `panth_seo_sitemap_shard.last_generated_at` and skips shards whose source entities haven't changed since the last run. |

Every setting resolves at **store-view** scope, so each store can have a different URL cap, gzip flag, hreflang flag, ping policy, or queue flag.

---

## Managing Sitemap Profiles

Open **Admin → Panth Infotech → XML Sitemap → Sitemap Profiles** to reach the grid (route `panth_xml_sitemap/profile/index`).

### Fields

| Field | Description |
|---|---|
| **Store View** | The store_view this profile applies to. Foreign-keyed to `store.store_id` with `ON DELETE CASCADE`. One profile per store. |
| **Base URL** | Absolute URL used as the `<loc>` prefix for every entry. Defaults to the store's `web/unsecure/base_url`. Override for CDN domains or alternate apex hosts. |
| **Max URLs per File** | Per-profile override of the global soft cap. Range 100–50000. |
| **Enable Hreflang** | Yes / No. When Yes, `Contributor\HreflangContributor` adds alternate-language entries per URL. |
| **Enable Images** | Yes / No. When Yes, `Contributor\ImageContributor` adds `<image:image>` blocks per product URL. |
| **Enable Videos** | Yes / No. When Yes, `<video:video>` blocks are added per product URL with associated video media. |
| **Enable Gzip** | Yes / No. When Yes, shards are written as `.xml.gz`. |
| **XSL Path** | Optional per-profile XSL stylesheet path. Blank = use global default or skip entirely. |
| **Ping Google** | Yes / No. Post to Google's ping endpoint on successful write. |
| **Ping Bing** | Yes / No. Post to Bing's ping endpoint on successful write. |
| **Enable Queue** | Yes / No. Dispatch each shard to the AMQP topic instead of building synchronously. |
| **Enable Delta** | Yes / No. Skip shards whose source entities haven't changed since the last run. |
| **Include Products** | Yes / No. Run `Contributor\Product` for this profile. |
| **Include Categories** | Yes / No. Run `Contributor\Category` for this profile. |
| **Include CMS Pages** | Yes / No. Run `Contributor\CmsPage` for this profile. |
| **Include Landing Pages** | Yes / No. Run `Contributor\LandingPage` (auto-skipped if Panth Landing Pages is not installed). |
| **Include Blog** | Yes / No. Run `Contributor\Blog` (auto-skipped if no supported blog module is installed). |
| **Cron Schedule** | Standard cron expression. Default `0 2 * * *`. The cron runner only dispatches this profile when the schedule matches. |
| **Active** | Per-profile enable/disable. Inactive profiles are skipped by cron and CLI. |

### Mass actions

Select rows and choose **Enable**, **Disable**, **Regenerate Now** or **Delete** from the grid mass-action menu. "Regenerate Now" is a shortcut that dispatches a full rebuild for every selected profile, bypassing the delta tracker once.

---

## Frontend Endpoint

- **URL:** `GET /panth-sitemap.xml`
- **Content-Type:** `application/xml; charset=utf-8`
- **Controller:** `Panth\XmlSitemap\Controller\Sitemap\Index` at route `xml_sitemap/sitemap/index`.

`/panth-sitemap.xml` is served by our controller via a `url_rewrite` row installed by `Setup\Patch\Data\InstallSitemapRewrite`. The core `Magento_Sitemap` module's filesystem-based sitemap is unaffected — Panth XML Sitemap deliberately uses a different URL so you can keep the native sitemap around for comparison during the first week after install.

If you are upgrading from `Panth_AdvancedSEO` where `/panth-sitemap.xml` was already mapped to that module's controller, the `RefreshSitemapRewrite` patch runs on the next `setup:upgrade` and rewrites the stale `target_path` to point at the new controller — zero manual DB surgery required.

### Sitemap index shape

```xml
<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <sitemap>
    <loc>https://your-store.test/panth-sitemap-products-1.xml.gz</loc>
    <lastmod>2026-04-21T02:00:05+00:00</lastmod>
  </sitemap>
  <sitemap>
    <loc>https://your-store.test/panth-sitemap-products-2.xml.gz</loc>
    <lastmod>2026-04-21T02:00:07+00:00</lastmod>
  </sitemap>
  <sitemap>
    <loc>https://your-store.test/panth-sitemap-categories-1.xml.gz</loc>
    <lastmod>2026-04-21T02:00:08+00:00</lastmod>
  </sitemap>
  <sitemap>
    <loc>https://your-store.test/panth-sitemap-cms-1.xml.gz</loc>
    <lastmod>2026-04-21T02:00:08+00:00</lastmod>
  </sitemap>
</sitemapindex>
```

### Individual shard shape

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xhtml="http://www.w3.org/1999/xhtml"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"
        xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">
  <url>
    <loc>https://your-store.test/example-product.html</loc>
    <lastmod>2026-04-20T13:22:14+00:00</lastmod>
    <changefreq>daily</changefreq>
    <priority>0.8</priority>
    <xhtml:link rel="alternate" hreflang="en-US" href="https://your-store.test/example-product.html"/>
    <xhtml:link rel="alternate" hreflang="fr-FR" href="https://fr.your-store.test/exemple-produit.html"/>
    <xhtml:link rel="alternate" hreflang="x-default" href="https://your-store.test/example-product.html"/>
    <image:image>
      <image:loc>https://your-store.test/media/catalog/product/e/x/example.jpg</image:loc>
      <image:title>Example Product</image:title>
    </image:image>
  </url>
</urlset>
```

---

## CLI

The module exposes a Magento console command for manual and CI-driven runs:

```bash
# Generate every active profile
bin/magento panth:seo:sitemap:generate

# Generate a single profile by ID
bin/magento panth:seo:sitemap:generate --profile-id=3

# Force a full rebuild, bypassing the delta tracker
bin/magento panth:seo:sitemap:generate --profile-id=3 --force

# Quiet mode (suppress progress, only emit final summary)
bin/magento panth:seo:sitemap:generate --quiet
```

Output on a typical 50k-product run:

```
> bin/magento panth:seo:sitemap:generate --profile-id=1
Profile #1 (default store): starting...
  [products]    shard 1  (45000 URLs, 4.8 MB gzipped)
  [products]    shard 2  (8743 URLs, 0.9 MB gzipped)
  [categories]  shard 1  (1276 URLs, 0.1 MB gzipped)
  [cms]         shard 1  (42 URLs, 0.0 MB gzipped)
  [sitemap_index]         4 shards, 55061 URLs, 5.8 MB on disk
  [ping:google] OK
  [ping:bing]   OK
Profile #1: done in 4.8 s.
```

Exit codes: `0` on success, `1` on fatal error (invalid profile ID, DB error, disk full, etc.), `2` on partial success (one contributor failed but others completed).

---

## Cron

The default cron job is declared in `etc/crontab.xml` and runs on schedule `0 2 * * *` (02:00 daily):

```xml
<job name="panth_xml_sitemap_rebuild" instance="Panth\XmlSitemap\Cron\Rebuild" method="execute">
  <schedule>0 2 * * *</schedule>
</job>
```

To change the global schedule, override the job in your project's `app/etc/` — or use the per-profile `cron_schedule` column which takes precedence over the global job's schedule for that single profile.

Confirm the cron is registered and has a recent execution:

```bash
bin/magento cron:list | grep panth_xml_sitemap
# panth_xml_sitemap_rebuild   default   0 2 * * *

SELECT job_code, status, executed_at, finished_at
  FROM cron_schedule
 WHERE job_code = 'panth_xml_sitemap_rebuild'
 ORDER BY scheduled_at DESC
 LIMIT 5;
```

### Running the queue consumer

When any profile has `enable_queue = 1`, start the consumer as a long-running process (systemd / supervisord):

```bash
bin/magento queue:consumers:start panth_xml_sitemap_consumer --single-thread
```

The consumer ACKs each shard on success and NACKs + retries on transient errors. Messages that fail three times land on the `panth_xml_sitemap.shard.dlq` dead-letter topic for manual inspection.

---

## Troubleshooting

### `/panth-sitemap.xml` returns a 404 or a Luma 404 HTML page

You are likely sitting on a stale `url_rewrite` row left behind by `Panth_AdvancedSEO` whose `target_path` still points at the old controller. Run `bin/magento setup:upgrade` — the `RefreshSitemapRewrite` patch fires idempotently and rewrites the row to the new target. Follow with `bin/magento cache:clean config full_page`. Also confirm `panth_xml_sitemap/general/enabled = 1` at the store scope being tested — when the master switch is No, the controller returns 404 regardless of url_rewrite state.

### Sitemap only contains a handful of URLs

Three causes: (a) the profile has `include_products = 0` / `include_categories = 0` — flip the toggle and re-run; (b) the delta tracker thinks nothing has changed — run the CLI with `--force` once to rebuild from scratch; (c) the store's products have `visibility = 1 (not visible individually)` and are being filtered out as intended — this is correct behaviour for catalog hygiene, not a bug.

### Shard files missing from `pub/media/panth-sitemap/<store_code>/`

The `panth-sitemap` writable root was never created. Run `chmod -R u+w pub/media && mkdir -p pub/media/panth-sitemap && chown -R www-data:www-data pub/media/panth-sitemap`, then re-run the CLI. On a containerised deploy ensure the `pub/media` volume is writable by the PHP-FPM user.

### Queue consumer shows "topic not found"

The queue topology hasn't been declared on the AMQP broker. Run `bin/magento queue:consumers:start panth_xml_sitemap_consumer --single-thread` once — on first connect it declares the `panth_xml_sitemap.shard` topic, the `panth_xml_sitemap_consumer` queue, and the binding. You can also run `bin/magento setup:upgrade` which triggers the declaration via the DI.xml consumer wiring.

### Hreflang tags missing from shards despite `enable_hreflang = 1`

Hreflang only fires when the store has two or more `store_view` rows with different `locale` codes. A single-locale store legitimately has no alternates to emit, so the contributor is a no-op. Confirm with `SELECT code, locale_code FROM store s JOIN core_config_data c ON c.scope_id = s.store_id WHERE c.path = 'general/locale/code';` — you need at least two distinct `locale_code` values.

---

## Support

- **Agency:** [Panth Infotech on Upwork](https://www.upwork.com/agencies/1881421506131960778/)
- **Direct:** [kishansavaliya.com](https://kishansavaliya.com) — [Get a free quote](https://kishansavaliya.com/get-quote)
