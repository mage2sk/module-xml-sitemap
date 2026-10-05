# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.2.8] - 2026-10-06

### Changed
- Each sitemap profile now has a Sitemap Filename next to its Output Path. The sitemap index is written to and served from pub/<Output Path>/<Sitemap Filename>, so the default profile file is https://<store-domain>/sitemap.xml. New profiles start with Output Path / and Sitemap Filename sitemap.xml.
- The split files and the stylesheet are named after the Sitemap Filename: sitemap.xml gives sitemap-products-1.xml and sitemap-style.xsl, sitemap_luma.xml gives sitemap_luma-products-1.xml and sitemap_luma-style.xsl.
- The links to the split files use the store's web base URL, so they never contain a store code or index.php.
- Profiles saved before this version keep their stored Output Path and use the Sitemap Index Filename setting until they are saved with a Sitemap Filename.
- General > Enabled now works: No stops sitemap generation for that scope (cron, Generate Now and the command line, which report the profile as skipped), saving the configuration removes the sitemap files of the affected profiles, and Robots SEO and LLMs.txt stop listing the sitemap.

### Removed
- The separate dynamic sitemap URL and its frontend route. That URL now returns 404, and the setup upgrade deletes its url_rewrite rows. Sitemap indexes no longer link to it.

### Fixed
- Saving a profile rejects a Sitemap Filename that does not end in .xml or contains a folder, .. or characters other than letters, digits, dot, dash and underscore, an Output Path that would leave pub/, and an Output Path and Sitemap Filename that another profile already writes.
- Saving a profile warns when Magento's own site map (Marketing > SEO & Search > Site Map) writes the same file.
- Changing a profile's Output Path or Sitemap Filename deletes the files at the old location and resets its URL and file counts.
- A build only deletes the files of its own profile, so Magento's sitemap-1-1.xml files and other profiles' files in the same folder are kept.
- Panth Blog posts and categories are only listed in the sitemap of the store views they are assigned to.
- Panth FAQ items and categories use the URL key and Active value set for the store view, and are only listed for the store views they are assigned to.
