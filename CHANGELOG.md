# Changelog

All notable changes to Panth_XmlSitemap will be documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.26] - Blog contributor rewrite

### Fixed
- The blog contributor emitted zero URLs for Mageplaza_Blog: post fields were probed with `method_exists()`, which fails on Mageplaza's magic-getter post model, so every post was silently skipped. Posts are now read through guarded raw table queries.

### Changed
- The contributor is self-contained (module-manager and table-existence guards, no compile-time references to other packages). Magefan URLs honour permalink type, custom routes, suffix and trailing-slash settings; Mageplaza URLs honour the configured route prefix and `.html` suffix; per-post `lastmod` is emitted from the post's update timestamp.
- Legacy Mirasvit Blog (EAV-based `Mirasvit\Blog\Model\Post`) probing was dropped; it cannot run on the supported Magento/PHP range.

## [1.0.25]

### Changed

- Replaced typographic characters (em dashes, curly quotes, ellipsis) with plain ASCII punctuation. No functional changes.

## [1.0.24] - 2026-07-07

### Changed

- Code cleanup: removed redundant inline comments and docblocks from the PHP source. No functional changes.

## [1.0.23] - 2026-06-18

### Changed

- README rewritten to match Panth Infotech gold template structure: added SEO meta comment, badge row with live product URL, Quick Answer block, hire/agency promo, Who Is It For, Key Features grouped by area, Screenshots section, full Configuration table from system.xml, Managing Sitemap Profiles, How It Works, CLI, Cron, FAQ (9 entries), Support table, About Panth Infotech, Quick Links table, and SEO Keywords footer.

## [1.0.22] - 2026-05-13

### Fixed

- **Default profile output_path no longer shadows the `/sitemap` URL
  another module owns.** The previous default - `sitemap/<store_code>`
  (and `sitemap/panth/<store_code>/` in the legacy non-profile build
  path) - created a real `pub/sitemap/` directory. Under the standard
  Magento nginx config (`location / { try_files $uri $uri/ /index.php$is_args$args; }`)
  nginx prefers the directory match over the index.php fallback, so
  a bare `/sitemap` request lands on `pub/sitemap/` and returns
  `403 Forbidden` (autoindex is typically off) - the request never
  reaches Magento, so any frontend router that owned `/sitemap`
  (for example an HTML sitemap landing page) is shadowed.

### Changed

- New default output_path is `xmlsitemap/<store_code>/`, applied
  consistently across:
  - `etc/db_schema.xml` - `output_path` column default
  - `Model/Profile.php` - `getOutputPath()` fallback
  - `Controller/Adminhtml/Profile/Save.php` - empty-input fallback
  - `Ui/Component/Form/DataProvider/ProfileFormDataProvider.php`
    - UI placeholder for new-profile creation
  - `Model/Sitemap/Builder.php` - legacy non-profile generator
- Existing profiles with a custom `output_path` keep their configured
  value untouched. Profiles still on the old default that want to
  reclaim `/sitemap` should re-save the profile (the new default
  fills in) or manually move their `output_path` to `xmlsitemap/{store_code}/`.

## [1.0.21] - 2026-05-13

### Fixed

- **`setup:di:compile` no longer crashes when `module-hreflang` or
  `module-advanced-seo` aren't installed.** `HreflangContributor`
  previously imported `Panth\Hreflang\Api\HreflangResolverInterface`
  and `Panth\AdvancedSEO\Helper\Config` directly as required
  constructor types, but neither sibling module is listed in this
  package's `require`. On a project that installs only
  `mage2kishan/module-xml-sitemap`, Magento DI compile failed with
  `Class "Panth\Hreflang\Api\HreflangResolverInterface" does not
  exist` and every `bin/magento` command crashed thereafter.

### Changed

- New local interface `Panth\XmlSitemap\Api\HreflangResolverInterface`
  (`getAlternates(string $entityType, int $entityId, int $storeId): array`)
  decouples the contributor from the hreflang module.
- New default binding
  `Panth\XmlSitemap\Model\Hreflang\NullHreflangResolver` returns an
  empty alternate set, registered as the `<preference>` for the new
  interface so DI compile resolves cleanly out of the box. Projects
  that want real hreflang output declare their own `<preference>`
  pointing at an adapter over the installed hreflang resolver.
- `HreflangContributor` no longer requires `AdvancedSeoConfig`. The
  existing `panth_xml_sitemap.include_hreflang` store flag already
  guards the contributor; the redundant
  `Panth_AdvancedSEO::isHreflangEnabled()` check was a duplicate gate
  and pulled in a sibling-module helper that doesn't have to be
  present.

## [1.0.20] - 2026-05-13

### Fixed

- **Disabled / not-visible / unassigned products no longer leak into
  the sitemap.** `url_rewrite` rows survive after a product is
  disabled, flipped to "Not Visible Individually" or removed from a
  website, so leaning on the rewrite table alone was emitting URLs
  Magento would 404 (or that should never have been indexable in the
  first place). `ProductContributor` and `ImageContributor` now
  mirror the native sitemap query: enabled status (store-scoped with
  admin fallback), visibility in catalog/both (2, 4), and an active
  `catalog_product_website` row for the store's website.
- **Inactive categories no longer appear.** `CategoryContributor`
  now joins `catalog_category_entity_int.is_active` (store-scoped
  with admin fallback) and restricts to the active store's root
  category subtree, so categories disabled in admin disappear from
  the next generated sitemap.
- **CMS homepage and duplicate CMS rows.** `CmsPageContributor`
  now drops the page configured as `web/default/cms_home_page`
  (it already resolves to `/`) and groups by `page_id` so a CMS page
  assigned to both "All Store Views" and a specific store no longer
  duplicates its URL.

### Notes

- URL suffix (`catalog/seo/product_url_suffix`,
  `catalog/seo/category_url_suffix`) continues to be honoured via
  `url_rewrite.request_path` - there is no second source of truth.

## [1.0.19] - 2026-05-11

### Added

- **Per-profile "Excluded CMS Identifiers" textarea** in the CMS Page
  Settings fieldset. Newline-separated identifiers listed here are
  dropped from the CMS shard regardless of the `exclude_noindex`
  setting - belt-and-braces over the existing `panth_seo_resolved`
  noindex join. New and upgraded installs seed the column with
  `home`, `enable-cookies`, `privacy-policy-cookie-restriction-mode`,
  `no-route` so the CMS shard is clean on day one without depending
  on the noindex resolver having run yet.

### Notes

- Column added via declarative schema (`db_schema.xml`); seed runs
  via the idempotent `SeedExcludedCmsIdentifiers` data patch (only
  overwrites NULL/empty rows so merchant customisations survive
  upgrade).

## [1.0.7] - 2026-04-21

### Added

- **README Preview section** with 4 admin screenshots + a 900×506
  walkthrough GIF. Screenshots enhanced via ImageMagick (shadow +
  1800px + quality 88). GIF is 4× speed, 12 fps, 2-pass ffmpeg palette.

## [1.0.6] - 2026-04-21

### Changed

- **Shorter default output path** - `sitemap/<store_code>/` instead of
  `sitemap/panth/<store_code>/profile-N/`. The index file now lives one
  folder deep at `/sitemap/<store_code>/sitemap_index.xml`. Existing
  profiles with a non-empty `output_path` column keep using their
  configured path. `AddDefaultProfile` no longer seeds an `output_path`
  value so new installs pick up the short default automatically.

### Fixed

- **`/panth-sitemap.xml` ignored the profile's `entity_types` filter.**
  The live endpoint's `buildForStore()` iterated every contributor
  unconditionally, so selecting only "Products" in the admin multiselect
  still produced CMS + landing-page + blog URLs on the frontend. The
  method now loads the store's active profile (store-specific first,
  then "All Stores" fallback) and:
  - skips contributors outside the profile's allowed buckets
    (product / category / cms / custom, with landing-page+blog mapped
    into product/cms respectively);
  - emits profile-configured `custom_links` when `custom` is selected;
  - passes `priority_homepage`, `exclude_out_of_stock`, `exclude_noindex`,
    `include_images`, `include_hreflang_tags`, `include_video_sitemap`
    through as the contributor `$config` array;
  - deduplicates URLs across contributors so `CmsPageContributor` +
    `LandingPageContributor` + `BlogContributor` can't emit the same
    URL twice.
- **XSL stylesheet path in `buildForStore()`** pointed at
  `/sitemap/panth/<code>/sitemap-style.xsl`. Updated to match the new
  short output path.

## [1.0.5] - 2026-04-21

### Added

- **"View Sitemap" toolbar button on the Edit Profile form.** Opens the
  generated `sitemap_index.xml` in a new tab. Hidden until the profile
  has been generated at least once (`file_count > 0`). Respects the
  profile's `output_path` template so custom locations work.

### Fixed

- **Grid "View Sitemap" row action honours the `output_path` template.**
  Previously hardcoded `sitemap/panth/<store>/profile-N/...`; now resolves
  `{store_code}` from the column when set.

## [1.0.4] - 2026-04-21

### Fixed

- **Profile `entity_types` multiselect was dead.** Builder iterated every
  contributor regardless of the profile's selection, so turning off
  Products/Categories/CMS/Custom Links in the admin had no effect on
  the generated sitemap. `buildFromProfile()` now parses the CSV value
  into a bucket set (product / category / cms / custom) and skips any
  contributor outside the allowed buckets. Custom-link shard generation
  is gated on the `custom` bucket being selected.
- **Profile `priority_homepage` was ignored.** `ProductContributor`
  hardcoded `1.0` in both the dedicated homepage-yield branch and the
  url_rewrite loop's homepage-optimisation override. Both branches now
  read `$config['priority_homepage']` with a `1.0` fallback.
- **Profile `custom_links` CSV format was not parsed.** The newline
  branch of `resolveCustomLinks()` treated every line as a bare URL so
  `https://example.com/page,weekly,0.7` ended up as a `<loc>` containing
  commas. Parser now splits on the first two commas and extracts
  `url,changefreq,priority`.
- **Profile `output_path` template was not applied.** `buildFromProfile()`
  hardcoded `sitemap/panth/{store_code}/profile-N/`. The column now
  honours its `{store_code}` placeholder; pre-existing profiles with an
  empty value keep the legacy default for backward compatibility.
- **Profile `include_hreflang_tags` / `include_video_sitemap` were
  unused.** `ShardWriter` unconditionally emitted `xmlns:xhtml` and
  `xmlns:video` on every urlset. The writer's `open()` now accepts an
  options array and the two flags are plumbed through `buildFromProfile`
  -> `writeEntityShards` -> `ShardWriter::open`. Custom-link shards always
  opt out of both auxiliary namespaces since their URLs never carry
  hreflang or video data.

## [1.0.3] - 2026-04-21

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
  hard-coded in private consts / arrays - all rewritten to match.

## [1.0.2] - 2026-04-21

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
  to the parent renderer - same pattern Panth_Crosslinks uses.

## [1.0.1] - 2026-04-21

### Fixed

- Admin controllers under `Controller/Adminhtml/Profile/` referenced the
  pre-extraction `Panth\AdvancedSEO\Controller\Adminhtml\AbstractAction`
  which no longer exists - admin CRUD pages failed to generate
  interceptors during `setup:di:compile`. Each controller now extends
  the module-local `Panth\XmlSitemap\Controller\Adminhtml\AbstractAction`.
- `Controller\Adminhtml\Profile\Rebuild` imported the renamed cron
  class under its old name `Panth\XmlSitemap\Cron\SitemapRebuild`;
  updated to `Panth\XmlSitemap\Cron\Rebuild` (aliased as `RebuildCron`
  to avoid the local class-name collision).

## [1.0.0] - 2026-04-21

### Added

- Initial release, extracted from Panth_AdvancedSEO 1.1.0.
- Per-store sitemap profile CRUD with 17 configurable fields (base URL,
  max URLs per file, gzip, hreflang, images, videos, auto-split, etc.)
- 7 entity-type contributors: Product, Category, CmsPage, LandingPage,
  Blog, Video, AdditionalLinks + HreflangContributor + ImageContributor.
- `Panth\XmlSitemap\Api\BuilderInterface` - shard-aware sitemap generator.
- `Panth\XmlSitemap\Model\Sitemap\ShardWriter` - writes individual
  sitemap shards, `IndexWriter` - writes the top-level sitemap index.
- `DeltaTracker` - records last-modified timestamps so subsequent cron
  runs only regenerate changed entities.
- `SearchEnginePinger` - posts to Google / Bing ping endpoints on
  successful generation.
- `Cron\Rebuild` - nightly regeneration (default schedule `0 2 * * *`).
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
