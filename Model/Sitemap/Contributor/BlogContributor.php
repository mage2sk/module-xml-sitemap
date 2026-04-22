<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Model\Sitemap\Contributor;

use Panth\StructuredData\Model\Blog\BlogDetector;
use Panth\XmlSitemap\Api\ContributorInterface;

/**
 * Contributes blog post URLs to the XML sitemap when a supported third-party
 * blog module is installed. Yields nothing if no blog module is detected.
 */
class BlogContributor implements ContributorInterface
{
    private const CHANGEFREQ = 'weekly';
    private const PRIORITY   = 0.6;

    public function __construct(
        private readonly BlogDetector $blogDetector
    ) {
    }

    public function getCode(): string
    {
        return 'blog';
    }

    public function getUrls(int $storeId, array $config = []): \Generator
    {
        if (!$this->blogDetector->isBlogInstalled()) {
            return;
        }

        $posts = $this->blogDetector->getBlogPosts($storeId);

        foreach ($posts as $post) {
            $url = (string) ($post['url'] ?? '');

            if ($url === '') {
                continue;
            }

            yield [
                'loc'        => $url,
                'changefreq' => self::CHANGEFREQ,
                'priority'   => self::PRIORITY,
            ];
        }
    }
}
