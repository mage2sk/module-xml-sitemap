<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Controller;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Ui\Component\MassAction\Filter;
use Panth\XmlSitemap\Controller\Adminhtml\Profile\Delete;
use Panth\XmlSitemap\Controller\Adminhtml\Profile\Generate;
use Panth\XmlSitemap\Controller\Adminhtml\Profile\MassDelete;
use Panth\XmlSitemap\Controller\Adminhtml\Profile\Rebuild;
use Panth\XmlSitemap\Controller\Adminhtml\Profile\Save;
use Panth\XmlSitemap\Cron\Rebuild as RebuildCron;
use Panth\XmlSitemap\Model\ResourceModel\Profile\CollectionFactory;
use Panth\XmlSitemap\Model\Sitemap\Builder;
use Panth\XmlSitemap\Model\Sitemap\ProfileSchedule;

class AdminProfileControllersTest extends AdminControllerTestCase
{
    private function resource(AdapterInterface $conn): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    private function save(array $post, AdapterInterface $conn, array $params = [], bool $storeExists = true, bool $cronValid = true): Save
    {
        $sm = $this->createStub(StoreManagerInterface::class);
        if (!$storeExists) {
            $sm->method('getStore')->willThrowException(new NoSuchEntityException());
        }
        $schedule = $this->createStub(ProfileSchedule::class);
        $schedule->method('isValid')->willReturn($cronValid);
        $dt = $this->createStub(DateTime::class);
        $dt->method('gmtDate')->willReturn('2026-01-01 00:00:00');
        return new Save($this->context($params, $post), $this->resource($conn), $dt, $sm, $schedule);
    }

    public function testSaveWithoutPostRedirectsToGrid(): void
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->expects($this->never())->method('insert');
        $this->save([], $conn)->execute();
        $this->assertSame(['*/*/', []], $this->redirect);
    }

    public function testSaveRejectsAllStoreViews(): void
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->expects($this->never())->method('update');
        $this->save(['profile_id' => 4, 'store_id' => 0], $conn)->execute();
        $this->assertSame(['*/*/edit', ['id' => 4]], $this->redirect);
        $this->assertStringContainsString('single store view', $this->messages['error'][0]);
    }

    public function testSaveRejectsUnknownStore(): void
    {
        $this->save(['store_id' => 9], $this->createStub(AdapterInterface::class), [], false)->execute();
        $this->assertSame(['*/*/edit', []], $this->redirect);
        $this->assertStringContainsString('no longer exists', $this->messages['error'][0]);
    }

    public function testSaveRejectsInvalidCron(): void
    {
        $this->save(['store_id' => 1, 'cron_schedule' => 'bad'], $this->createStub(AdapterInterface::class), [], true, false)->execute();
        $this->assertStringContainsString('"bad" is not a valid cron expression', $this->messages['error'][0]);
    }

    public function testSaveInsertsNewProfileWithNormalisedData(): void
    {
        $conn = $this->createMock(\Magento\Framework\DB\Adapter\Pdo\Mysql::class);
        $conn->expects($this->once())->method('insert')->with(
            'panth_seo_sitemap_profile',
            $this->callback(function (array $row): bool {
                $this->assertSame('product,cms', $row['entity_types']);
                $this->assertSame(ProfileSchedule::DEFAULT_EXPRESSION, $row['cron_schedule']);
                $this->assertSame("home\nprivacy", $row['excluded_cms_identifiers']);
                $this->assertSame(255, mb_strlen($row['name']));
                $this->assertSame('2026-01-01 00:00:00', $row['created_at']);
                $this->assertSame('xmlsitemap/{store_code}/', $row['output_path']);
                $this->assertSame(1, $row['exclude_noindex']);
                return true;
            })
        );
        $conn->method('lastInsertId')->willReturn('12');

        $this->save([
            'store_id' => '1',
            'name' => str_repeat('n', 300),
            'entity_types' => ['product', 'cms'],
            'cron_schedule' => '  ',
            'excluded_cms_identifiers' => "home, privacy\nhome\n ",
        ], $conn, ['back' => 1])->execute();

        $this->assertSame(['*/*/edit', ['id' => 12]], $this->redirect);
        $this->assertSame(['Sitemap profile saved.'], $this->messages['success']);
    }

    public function testSaveUpdatesExistingProfile(): void
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->expects($this->once())->method('update')->with(
            'panth_seo_sitemap_profile',
            $this->callback(static fn(array $row) => !isset($row['created_at']) && $row['excluded_cms_identifiers'] === null),
            ['profile_id = ?' => 3]
        );
        $conn->expects($this->never())->method('insert');
        $this->save(['profile_id' => 3, 'store_id' => 1, 'excluded_cms_identifiers' => ' , '], $conn)->execute();
        $this->assertSame(['*/*/', []], $this->redirect);
    }

    public function testSaveDbErrorRedirectsBackToEdit(): void
    {
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('update')->willThrowException(new \RuntimeException('duplicate'));
        $this->save(['profile_id' => 3, 'store_id' => 1], $conn)->execute();
        $this->assertSame(['*/*/edit', ['id' => 3]], $this->redirect);
        $this->assertSame(['duplicate'], $this->messages['error']);
    }

    public function testGenerateSingleProfile(): void
    {
        $builder = $this->createMock(Builder::class);
        $builder->method('loadProfile')->with(5)->willReturn(['profile_id' => 5, 'name' => 'Main']);
        $stats = ['url_count' => 3, 'file_count' => 2, 'generation_time' => 0.456, 'files' => []];
        $builder->expects($this->once())->method('buildFromProfile')->willReturn($stats);
        $builder->expects($this->once())->method('updateProfileStats')->with(5, $stats);

        (new Generate($this->context(['profile_id' => 5]), $builder))->execute();
        $this->assertSame(['Sitemap generated for profile "Main": 3 URLs in 2 files (0.46 seconds).'], $this->messages['success']);
        $this->assertSame(['*/*/', []], $this->redirect);
    }

    public function testGenerateMissingProfile(): void
    {
        $builder = $this->createStub(Builder::class);
        $builder->method('loadProfile')->willReturn(null);
        (new Generate($this->context(['id' => 8]), $builder))->execute();
        $this->assertSame(['Sitemap profile with ID 8 was not found.'], $this->messages['error']);
    }

    public function testGenerateAllProfilesAggregatesStats(): void
    {
        $builder = $this->createMock(Builder::class);
        $builder->method('loadActiveProfiles')->willReturn([['profile_id' => 1], ['name' => 'no id']]);
        $builder->method('buildFromProfile')->willReturn(['url_count' => 2, 'file_count' => 1, 'generation_time' => 0.5, 'files' => []]);
        $builder->expects($this->once())->method('updateProfileStats')->with(1, $this->anything());

        (new Generate($this->context(), $builder))->execute();
        $this->assertSame(['Sitemap generated for 2 profile(s): 4 URLs in 2 files (1.00 seconds total).'], $this->messages['success']);
    }

    public function testGenerateWithoutProfilesAndOnError(): void
    {
        $builder = $this->createStub(Builder::class);
        $builder->method('loadActiveProfiles')->willReturn([]);
        (new Generate($this->context(), $builder))->execute();
        $this->assertStringContainsString('No active sitemap profiles found', $this->messages['success'][0]);

        $failing = $this->createStub(Builder::class);
        $failing->method('loadActiveProfiles')->willThrowException(new \RuntimeException('boom'));
        (new Generate($this->context(), $failing))->execute();
        $this->assertSame(['Error generating sitemap: boom'], $this->messages['error']);
    }

    public function testDelete(): void
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->expects($this->once())->method('delete')->with('panth_seo_sitemap_profile', ['profile_id = ?' => 6]);
        (new Delete($this->context(['id' => '6']), $this->resource($conn)))->execute();
        $this->assertSame(['Sitemap profile deleted.'], $this->messages['success']);
        $this->assertSame(['*/*/', []], $this->redirect);
    }

    public function testDeleteWithoutIdAndOnError(): void
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->expects($this->never())->method('delete');
        (new Delete($this->context(), $this->resource($conn)))->execute();
        $this->assertSame([], $this->messages['success']);

        $bad = $this->createStub(AdapterInterface::class);
        $bad->method('delete')->willThrowException(new \RuntimeException('locked'));
        (new Delete($this->context(['id' => 1]), $this->resource($bad)))->execute();
        $this->assertSame(['locked'], $this->messages['error']);
    }

    private function massDelete(array $ids, AdapterInterface $conn): MassDelete
    {
        $collection = $this->createStub(AbstractDb::class);
        $collection->method('getAllIds')->willReturn($ids);
        $filter = $this->createStub(Filter::class);
        $filter->method('getCollection')->willReturn($collection);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn(
            $this->createStub(\Panth\XmlSitemap\Model\ResourceModel\Profile\Collection::class)
        );
        return new MassDelete($this->context(), $filter, $factory, $this->resource($conn));
    }

    public function testMassDelete(): void
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->expects($this->once())->method('delete')->with('panth_seo_sitemap_profile', ['profile_id IN (?)' => [1, 2]]);
        $this->massDelete(['1', '2'], $conn)->execute();
        $this->assertSame(['2 sitemap profile(s) deleted.'], $this->messages['success']);
    }

    public function testMassDeleteNothingSelected(): void
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->expects($this->never())->method('delete');
        $this->massDelete([], $conn)->execute();
        $this->assertSame(['0 sitemap profile(s) deleted.'], $this->messages['success']);
    }

    public function testRebuild(): void
    {
        $cron = $this->createMock(RebuildCron::class);
        $cron->expects($this->once())->method('execute');
        (new Rebuild($this->context(), $cron))->execute();
        $this->assertSame(['Sitemaps rebuilt.'], $this->messages['success']);

        $failing = $this->createStub(RebuildCron::class);
        $failing->method('execute')->willThrowException(new \RuntimeException('nope'));
        (new Rebuild($this->context(), $failing))->execute();
        $this->assertSame(['nope'], $this->messages['error']);
    }

    public function testNoProfilesMessageNamesTheRealCliCommand(): void
    {
        $builder = $this->createStub(Builder::class);
        $builder->method('loadActiveProfiles')->willReturn([]);
        (new Generate($this->context(), $builder))->execute();

        $this->assertStringContainsString('bin/magento panth:seo:sitemap:generate ', $this->messages['success'][0]);
    }
}
