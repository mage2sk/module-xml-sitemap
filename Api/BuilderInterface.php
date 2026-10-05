<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Api;

/**
 * Builds sharded sitemap files plus a sitemap index for a store or a sitemap profile.
 */
interface BuilderInterface
{
    /**
     * Build sitemaps for the given store and return an iterable of written file paths.
     *
     * @return iterable<int,string>
     */
    public function build(int $storeId): iterable;

    /**
     * Build sitemaps from a sitemap profile configuration.
     *
     * Writes the index to the profile's Output Path and Sitemap Filename below pub/ and one
     * file set per entity type named after that filename (sitemap.xml gives sitemap-products-1.xml).
     *
     * @param array $profile Profile row from the panth_seo_sitemap_profile table
     * @return array{url_count: int, file_count: int, generation_time: float, files: list<string>}
     */
    public function buildFromProfile(array $profile): array;
}
