# Changelog

All notable changes to Panth_XmlSitemap will be documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.3] — 2026-04-21

### Fixed

- **Every system-config toggle was dead.** `Helper/Config.php` still
  read the pre-extraction paths `panth_seo/sitemap/*` while the admin
  UI writes to the new `panth_xml_sitemap/*` tree declared in
  `system.xml`. Flipping any field in the XML Sitemap config section
  did nothing. Every XML_SITEMAP_* constant rewritten to the new path:
  - General: `general/enabled`, `general/homepage_optimization`
  - Generation: `generation/shard_size`, `generation/gzip`,
    `generation/xsl_enabled`, `generation/exclude_out_of_stock`,
    `generation/exclude_noindex`
  - Hreflang: `hreflang/include_hreflang`
  - Media: `media/include_images`, `media/product_image_source`,
    `media/include_video`
  - Ping: `ping/ping_google`, `ping/ping_bing`
  - Additional: `additional/additional_links`,
    `additional/additional_links_changefreq`,
    `additional/additional_links_priority`
- `Model\Sitemap\SearchEnginePinger`, `VideoContributor`,
  `AdditionalLinksContributor` and `Builder` had the same stale paths
  hard-coded in private consts / arrays — all rewritten to match.

## [1.0.2] — 2026-04-21

### Fixed

- **`ProductImageSource` source model missing.** The 1.0.1 release left
  `system.xml` field `product_image_source` pointing at
  `Panth\XmlSitemap\Model\Config\Source\ProductImageSource` which was
  never ported from `Panth_AdvancedSEO`. The admin System Configuration
  page for `panth_xml_sitemap` threw
  `ReflectionException: Class "Panth\XmlSitemap\Model\Config\Source\ProductImageSource" does not exist`
  on render. Added the class with 3 options (base_image / small_image /
  thumbnail).
- **Empty Store View column on the Profile grid.** Magento's base
  `Magento\Store\Ui\Component\Listing\Column\Store` renders nothing for
  integer `store_id = 0` (All Store Views) because its emptiness check
  trips on 0. Introduced `Panth\XmlSitemap\Ui\Component\Listing\Column\Store`
  that normalises `store_id` to a single-element array before delegating
  to the parent renderer — same pattern Panth_Crosslinks uses.

## [1.0.1] — 2026-04-21

### Fixed

- Admin controllers under `Controller/Adminhtml/Profile/` referenced the
  pre-extraction `Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction`
  which no longer exists — admin CRUD pages failed to generate
  interceptors during `setup:di:compile`. Each controller now extends
  the module-local `Panth\XmlSitemap\Controller\Adminhtml\AbstractAction`.
- `Controller\Adminhtml\Profile\Rebuild` imported the renamed cron
  class under its old name `Panth\XmlSitemap\Cron\SitemapRebuild`;
  updated to `Panth\XmlSitemap\Cron\Rebuild` (aliased as `RebuildCron`
  to avoid the local class-name collision).

## [1.0.0] — 2026-04-21

### Added

- Initial release, extracted from Panth_AdvancedSEO 1.1.0.
- Per-store sitemap profile CRUD with 17 configurable fields (base URL,
  max URLs per file, gzip, hreflang, images, videos, auto-split, etc.)
- 7 entity-type contributors: Product, Category, CmsPage, LandingPage,
  Blog, Video, AdditionalLinks + HreflangContributor + ImageContributor.
- `Panth\XmlSitemap\Api\BuilderInterface` — shard-aware sitemap generator.
- `Panth\XmlSitemap\Model\Sitemap\ShardWriter` — writes individual
  sitemap shards, `IndexWriter` — writes the top-level sitemap index.
- `DeltaTracker` — records last-modified timestamps so subsequent cron
  runs only regenerate changed entities.
- `SearchEnginePinger` — posts to Google / Bing ping endpoints on
  successful generation.
- `Cron\Rebuild` — nightly regeneration (default schedule `0 2 * * *`).
- Async shard generation via AMQP topic `panth_xml_sitemap.shard`.
- CLI command `panth:seo:sitemap:generate [--profile-id=N]`.
- Frontend endpoint `/panth-sitemap.xml` served by module controller
  via url_rewrite (`xml_sitemap/sitemap/index`).

### Cross-module

- Shares table names `panth_seo_sitemap_profile` + `panth_seo_sitemap_shard`
  with the legacy Panth_AdvancedSEO declaration for zero-migration upgrade.

### Notes

- Admin route: `panth_xml_sitemap`. Frontend route: `xml_sitemap`.
- Sequence: Panth_Core, Magento_Store, Magento_Backend, Magento_Catalog,
  Magento_Cms, Magento_UrlRewrite.
