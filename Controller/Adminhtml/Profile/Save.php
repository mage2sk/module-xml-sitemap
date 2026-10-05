<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Controller\Adminhtml\Profile;

use Panth\XmlSitemap\Controller\Adminhtml\AbstractAction;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Backend\App\Action\Context;
use Magento\Store\Model\StoreManagerInterface;
use Panth\XmlSitemap\Helper\PathResolver;
use Panth\XmlSitemap\Model\Sitemap\Builder;
use Panth\XmlSitemap\Model\Sitemap\ProfileSchedule;
use Panth\XmlSitemap\Model\Sitemap\ProfileTarget;

class Save extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_XmlSitemap::profiles_save';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProfileSchedule $profileSchedule,
        private readonly ProfileTarget $profileTarget,
        private readonly Builder $builder
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $data = (array)$this->getRequest()->getPostValue();
        $resultRedirect = $this->resultRedirectFactory->create();
        if (!$data) {
            return $resultRedirect->setPath('*/*/');
        }

        $id = (int)($data['profile_id'] ?? 0);
        $back = $id > 0 ? ['id' => $id] : [];

        $storeId = (int)($data['store_id'] ?? 0);
        if ($storeId <= 0) {
            $this->messageManager->addErrorMessage(
                __('Pick a single store view - sitemap profiles are scoped per store view.')
            );
            return $resultRedirect->setPath('*/*/edit', $back);
        }
        try {
            $this->storeManager->getStore($storeId);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('The selected store view no longer exists.'));
            return $resultRedirect->setPath('*/*/edit', $back);
        }

        $cronSchedule = trim((string)($data['cron_schedule'] ?? ''));
        if ($cronSchedule === '') {
            $cronSchedule = ProfileSchedule::DEFAULT_EXPRESSION;
        }
        if (!$this->profileSchedule->isValid($cronSchedule)) {
            $this->messageManager->addErrorMessage(
                __('The cron schedule "%1" is not a valid cron expression.', $cronSchedule)
            );
            return $resultRedirect->setPath('*/*/edit', $back);
        }

        $invalid = $this->validateValues($data);
        if ($invalid !== null) {
            $this->messageManager->addErrorMessage($invalid);
            return $resultRedirect->setPath('*/*/edit', $back);
        }

        $outputPath = trim((string)($data['output_path'] ?? ''));
        if ($outputPath === '') {
            $outputPath = PathResolver::DEFAULT_PROFILE_PATH;
        }
        $filename = trim((string)($data[ProfileTarget::FILENAME_COLUMN] ?? ''));
        if ($filename === '') {
            $filename = PathResolver::DEFAULT_FILENAME;
        }
        $formatError = $this->profileTarget->validateFormat($outputPath, $filename);
        if ($formatError !== null) {
            $this->messageManager->addErrorMessage($formatError);
            return $resultRedirect->setPath('*/*/edit', $back);
        }

        $hasFilenameColumn = $this->profileTarget->hasFilenameColumn();
        if (!$hasFilenameColumn) {
            $fallback = $this->profileTarget->getFallbackFilename($storeId);
            if ($filename !== $fallback) {
                $this->messageManager->addErrorMessage(__(
                    'The Sitemap Filename can be changed after the database update for this module has run '
                    . '(bin/magento setup:upgrade). Until then the filename is %1.',
                    $fallback
                ));
                return $resultRedirect->setPath('*/*/edit', $back);
            }
        }

        $target = ['store_id' => $storeId, 'output_path' => $outputPath];
        if ($hasFilenameColumn) {
            $target[ProfileTarget::FILENAME_COLUMN] = $filename;
        }
        try {
            $conflict = $this->profileTarget->findProfileConflict($id, $target);
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $resultRedirect->setPath('*/*/edit', $back);
        }
        if ($conflict !== null) {
            $this->messageManager->addErrorMessage(__(
                'The sitemap profile "%1" (ID %2) already writes pub/%3. Choose another Sitemap Filename or '
                . 'Output Path so that each profile writes its own file.',
                $conflict['name'],
                $conflict['profile_id'],
                $conflict['file']
            ));
            return $resultRedirect->setPath('*/*/edit', $back);
        }

        $entityTypes = $data['entity_types'] ?? 'product,category,cms';
        if (is_array($entityTypes)) {
            $entityTypes = implode(',', $entityTypes);
        }

        $row = [
            'name' => mb_substr((string)($data['name'] ?? ''), 0, 255),
            'store_id' => $storeId,
            'entity_types' => $entityTypes,
            'include_images' => (int)($data['include_images'] ?? 1),
            'include_video' => (int)($data['include_video'] ?? 0),
            'include_hreflang' => (int)($data['include_hreflang'] ?? 0),
            'max_urls_per_file' => (int)($data['max_urls_per_file'] ?? 50000),
            'changefreq_product' => (string)($data['changefreq_product'] ?? 'weekly'),
            'changefreq_category' => (string)($data['changefreq_category'] ?? 'weekly'),
            'changefreq_cms' => (string)($data['changefreq_cms'] ?? 'monthly'),
            'priority_product' => (float)($data['priority_product'] ?? 0.8),
            'priority_category' => (float)($data['priority_category'] ?? 0.6),
            'priority_cms' => (float)($data['priority_cms'] ?? 0.5),
            'priority_homepage' => (float)($data['priority_homepage'] ?? 1.0),
            'exclude_out_of_stock' => (int)($data['exclude_out_of_stock'] ?? 0),
            'exclude_noindex' => (int)($data['exclude_noindex'] ?? 1),
            'custom_links' => str_replace(["\r\n", "\r"], "\n", (string)($data['custom_links'] ?? '')),
            'output_path' => $outputPath,
            'is_active' => (int)($data['is_active'] ?? 1),
            'cron_enabled' => (int)($data['cron_enabled'] ?? 0),
            'cron_schedule' => $cronSchedule,
            'updated_at' => $this->dateTime->gmtDate(),
        ];
        if ($hasFilenameColumn) {
            $row[ProfileTarget::FILENAME_COLUMN] = $filename;
        }
        if (array_key_exists('excluded_cms_identifiers', $data)) {
            $row['excluded_cms_identifiers'] = $this->normaliseIdentifiers($data['excluded_cms_identifiers']);
        }

        $previous = $id > 0 ? $this->profileTarget->loadProfile($id) : null;
        $previousFile = null;
        $newFile = $this->profileTarget->resolveRelativeFile($target);
        if ($previous !== null) {
            $previousFile = $this->profileTarget->resolveRelativeFile($previous);
            if ($previousFile === $newFile) {
                $previousFile = null;
            } else {
                $row['url_count'] = 0;
                $row['file_count'] = 0;
                $row['last_generated_at'] = null;
            }
        }

        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_seo_sitemap_profile');
            if ($id > 0) {
                $connection->update($table, $row, ['profile_id = ?' => $id]);
            } else {
                $row['created_at'] = $this->dateTime->gmtDate();
                $connection->insert($table, $row);
                $id = (int)$connection->lastInsertId($table);
            }
            $this->messageManager->addSuccessMessage(__('Sitemap profile saved.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }

        if ($previous !== null && $previousFile !== null) {
            $this->removePreviousFiles($previous, $previousFile, $newFile);
        }
        $this->warnAboutCoreSitemap($target);

        if ($this->getRequest()->getParam('back')) {
            return $resultRedirect->setPath('*/*/edit', ['id' => $id]);
        }
        return $resultRedirect->setPath('*/*/');
    }

    private function removePreviousFiles(array $previous, string $previousFile, string $newFile): void
    {
        try {
            $this->builder->removeProfileFiles($previous);
        } catch (\Throwable $e) {
            $this->messageManager->addWarningMessage(
                __('The old sitemap files at pub/%1 could not be removed: %2', $previousFile, $e->getMessage())
            );
            return;
        }
        $this->messageManager->addNoticeMessage(__(
            'The sitemap location changed from pub/%1 to pub/%2. The old files were removed; '
            . 'click Generate Now to write the new file.',
            $previousFile,
            $newFile
        ));
    }

    private function warnAboutCoreSitemap(array $target): void
    {
        try {
            $conflicts = $this->profileTarget->findCoreConflicts($target);
        } catch (\Throwable) {
            return;
        }
        foreach ($conflicts as $conflict) {
            $this->messageManager->addWarningMessage(__(
                'Magento\'s own site map (Marketing > SEO & Search > Site Map, ID %1) also writes pub/%2. '
                . 'Whichever runs last overwrites the file. Change the Sitemap Filename or Output Path here, '
                . 'or change or delete that site map.',
                $conflict['sitemap_id'],
                $conflict['file']
            ));
        }
    }

    private function validateValues(array $data): ?\Magento\Framework\Phrase
    {
        $maxUrls = $data['max_urls_per_file'] ?? '';
        if ($maxUrls !== '' && $maxUrls !== null
            && (!is_numeric($maxUrls) || (int)$maxUrls < 1 || (int)$maxUrls > 50000)
        ) {
            return __('Max URLs per File must be a whole number between 1 and 50000.');
        }

        $priorities = [
            'priority_product' => __('Product priority'),
            'priority_category' => __('Category priority'),
            'priority_cms' => __('CMS page priority'),
            'priority_homepage' => __('Homepage priority'),
        ];
        foreach ($priorities as $field => $label) {
            $value = $data[$field] ?? '';
            if ($value === '' || $value === null) {
                continue;
            }
            if (!is_numeric($value) || (float)$value < 0.0 || (float)$value > 1.0) {
                return __('%1 must be a number between 0.0 and 1.0.', $label);
            }
        }

        return null;
    }

    private function normaliseIdentifiers(mixed $value): ?string
    {
        $raw = is_array($value) ? implode("\n", $value) : (string) $value;
        $parts = preg_split('/\R|,/', $raw) ?: [];
        $parts = array_values(array_unique(array_filter(array_map('trim', $parts), 'strlen')));
        if ($parts === []) {
            return null;
        }
        return mb_substr(implode("\n", $parts), 0, 1024);
    }
}
