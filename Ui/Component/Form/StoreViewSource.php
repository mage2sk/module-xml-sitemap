<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Ui\Component\Form;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Store-view picker for the sitemap profile form.
 *
 * Each profile must own EXACTLY ONE store view — generating a sitemap
 * "for all stores" was previously possible via the default Magento
 * options class which surfaced an "All Store Views" entry. That choice
 * mapped to `store_id = 0` and downstream code (cron generator,
 * frontend `/panth-sitemap.xml`, view-sitemap URL builder) had to
 * special-case the value. The result was the bug the merchant hit:
 * a profile saved with id 0 generated, but the View Sitemap link
 * pointed at a non-existent path.
 *
 * This source class lists every active, non-admin store view as a
 * flat option set so the admin form cannot land an invalid value in
 * the database. The save controller now rejects store_id <= 0 too as
 * a defence-in-depth.
 */
class StoreViewSource implements OptionSourceInterface
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return array<int,array{label:string,value:int}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->storeManager->getStores(false) as $store) {
            $storeId = (int) $store->getId();
            if ($storeId <= 0) {
                continue;
            }
            $options[] = [
                'value' => $storeId,
                'label' => sprintf('%s (%s)', (string) $store->getName(), (string) $store->getCode()),
            ];
        }
        return $options;
    }
}
