# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.2.6] - 2026-10-03

### Fixed
- Saving or loading a sitemap profile no longer fails on installs where the profile table was missing: the table created on demand now has the same columns as the declared schema.
- The "No active sitemap profiles found" message now names the real command, bin/magento panth:seo:sitemap:generate.
- The Delete and Generate Now buttons on the profile edit page keep working when a translation of their confirmation text contains an apostrophe or other special characters.
