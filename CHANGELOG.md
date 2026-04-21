# Changelog

All notable changes to Panth_XmlSitemap will be documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project adheres to [Semantic Versioning](https://semver.org/).

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
