<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Plugin\Admin;

use Magento\Catalog\Model\Category\DataProvider as CategoryDataProvider;
use Panth\XmlSitemap\Helper\Config as SitemapConfig;

class CategoryFormSitemapPlugin
{
    public function __construct(
        private readonly SitemapConfig $sitemapConfig
    ) {
    }

    public function afterGetMeta(CategoryDataProvider $subject, array $result): array
    {
        if (!$this->sitemapConfig->isSitemapEnabled()) {
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
                                    'true'  => '0',
                                    'false' => '1',
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
