<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Api;

/**
 * Local, narrow interface used by HreflangContributor to look up
 * alternate-language URLs for a given entity.
 *
 * Defining this inside module-xml-sitemap (rather than depending
 * directly on Panth\Hreflang\Api\HreflangResolverInterface) keeps
 * setup:di:compile working on installs that don't ship the
 * mage2kishan/module-hreflang package - DI compile validates
 * constructor types against autoloadable classes, and a hard
 * cross-module import would crash with "Class does not exist".
 *
 * When the consumer wants real hreflang output in the sitemap,
 * they declare a `<preference>` in their own di.xml binding this
 * interface to an adapter that delegates to the installed hreflang
 * resolver. Otherwise the bundled {@see \Panth\XmlSitemap\Model\Hreflang\NullHreflangResolver}
 * returns an empty alternate set and the contributor is a no-op.
 */
interface HreflangResolverInterface
{
    /**
     * @param string $entityType One of "product", "category", "cms".
     * @return array<int, array{locale: string, url: string}>
     */
    public function getAlternates(string $entityType, int $entityId, int $storeId): array;
}
