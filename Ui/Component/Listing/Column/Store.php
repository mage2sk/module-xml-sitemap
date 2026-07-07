<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Ui\Component\Listing\Column;

use Magento\Store\Ui\Component\Listing\Column\Store as BaseStore;

class Store extends BaseStore
{
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$item) {
                if (array_key_exists('store_id', $item) && !is_array($item['store_id'])) {
                    $item['store_id'] = [(int) $item['store_id']];
                }
            }
            unset($item);
        }
        return parent::prepareDataSource($dataSource);
    }
}
