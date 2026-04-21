<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Plugin\Admin;

use Magento\Catalog\Model\Category\DataProvider as CategoryDataProvider;
use Panth\XmlSitemap\Helper\Config as SitemapConfig;

/**
 * Adds an "Exclude from Sitemap" checkbox to the category edit form's
 * SEO fieldset. The value is persisted via the `in_xml_sitemap` EAV
 * attribute (boolean, default 1 = included).
 *
 * The checkbox label is inverted for UX clarity: checking the box sets
 * `in_xml_sitemap` to 0 (excluded).
 */
class CategoryFormSitemapPlugin
{
    public function __construct(
        private readonly SitemapConfig $sitemapConfig
    ) {
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function afterGetMeta(CategoryDataProvider $subject, array $result): array
    {
        if (!$this->sitemapConfig->isEnabled()) {
            return $result;
        }

        $result['search_engine_optimization']['children']['container_exclude_from_sitemap'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'formElement'   => 'container',
                        'componentType' => 'container',
                        'breakLine'     => false,
                        'label'         => '',
                        'required'      => false,
                        'sortOrder'     => 200,
                    ],
                ],
            ],
            'children' => [
                'exclude_from_sitemap' => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'dataType'      => 'boolean',
                                'formElement'   => 'checkbox',
                                'componentType' => 'field',
                                'label'         => __('Exclude from XML Sitemap'),
                                'description'   => __('When checked, this category will not appear in the XML sitemap.'),
                                'prefer'        => 'toggle',
                                'valueMap'      => [
                                    'true'  => '0', // checked = exclude (in_xml_sitemap = 0)
                                    'false' => '1', // unchecked = include (in_xml_sitemap = 1)
                                ],
                                'default'       => '1',
                                'dataScope'     => 'in_xml_sitemap',
                                'sortOrder'     => 200,
                                'switcherConfig' => [
                                    'enabled' => false,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return $result;
    }
}
