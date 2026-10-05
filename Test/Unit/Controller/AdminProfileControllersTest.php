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
use Panth\XmlSitemap\Helper\Config;
use Panth\XmlSitemap\Helper\PathResolver;
use Panth\XmlSitemap\Model\ResourceModel\Profile\CollectionFactory;
use Panth\XmlSitemap\Model\Sitemap\Builder;
use Panth\XmlSitemap\Model\Sitemap\ProfileSchedule;
use Panth\XmlSitemap\Model\Sitemap\ProfileTarget;

class AdminProfileControllersTest extends AdminControllerTestCase
{
    private function resource(AdapterInterface $conn): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    private function profileTarget(array $options = []): ProfileTarget
    {
        return new class (
            $this->createStub(ResourceConnection::class),
            $this->createStub(StoreManagerInterface::class),
            new PathResolver(),
            $this->createStub(Config::class),
            $options
        ) extends ProfileTarget {
            public function __construct(
                ResourceConnection $resource,
                StoreManagerInterface $storeManager,
                PathResolver $pathResolver,
                Config $config,
                private readonly array $options
            ) {
                parent::__construct($resource, $storeManager, $pathResolver, $config);
            }

            public function hasFilenameColumn(): bool
            {
                return $this->options['column'] ?? true;
            }

            public function findProfileConflict(int $profileId, array $profile): ?array
            {
                return $this->options['conflict'] ?? null;
            }

            public function findCoreConflicts(array $profile): array
            {
                return $this->options['core'] ?? [];
            }

            public function loadProfile(int $profileId): ?array
            {
                return $this->options['previous'] ?? null;
            }

            public function getFallbackFilename(int $storeId): string
            {
                return 'sitemap.xml';
            }

            public function resolveRelativeFile(array $profile): string
            {
                return trim((string) ($profile['output_path'] ?? ''), '/') . '/'
                    . ($profile['sitemap_filename'] ?? 'sitemap.xml');
            }
        };
    }

    private function save(
        array $post,
        AdapterInterface $conn,
        array $params = [],
        bool $storeExists = true,
        bool $cronValid = true,
        ?ProfileTarget $target = null,
        ?Builder $builder = null
    ): Save {
        $sm = $this->createStub(StoreManagerInterface::class);
        if (!$storeExists) {
            $sm->method('getStore')->willThrowException(new NoSuchEntityException());
        }
        $schedule = $this->createStub(ProfileSchedule::class);
        $schedule->method('isValid')->willReturn($cronValid);
        $dt = $this->createStub(DateTime::class);
        $dt->method('gmtDate')->willReturn('2026-01-01 00:00:00');
        return new Save(
            $this->context($params, $post),
            $this->resource($conn),
            $dt,
            $sm,
            $schedule,
            $target ?? $this->profileTarget(),
            $builder ?? $this->createStub(Builder::class)
        );
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

    public static function invalidValuesProvider(): array
    {
        return [
            'traversal' => [['output_path' => '../../app/etc'], 'output path "../../app/etc" is not allowed'],
            'backslash' => [['output_path' => 'maps\x'], 'is not allowed'],
            'bad characters' => [['output_path' => 'maps/<b>'], 'is not allowed'],
            'filename traversal' => [['sitemap_filename' => '../x.xml'], 'sitemap filename "../x.xml" is not allowed'],
            'filename folder' => [['sitemap_filename' => 'seo/sitemap.xml'], 'is not allowed'],
            'filename extension' => [['sitemap_filename' => 'sitemap.txt'], 'is not allowed'],
            'zero urls' => [['max_urls_per_file' => '0'], 'between 1 and 50000'],
            'too many urls' => [['max_urls_per_file' => '60000'], 'between 1 and 50000'],
            'text urls' => [['max_urls_per_file' => 'lots'], 'between 1 and 50000'],
            'priority above one' => [['priority_product' => '5'], 'Product priority must be a number between 0.0 and 1.0'],
            'negative priority' => [['priority_homepage' => '-0.1'], 'Homepage priority must be'],
            'text priority' => [['priority_cms' => 'high'], 'CMS page priority must be'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidValuesProvider')]
    public function testSaveRejectsInvalidValues(array $extra, string $message): void
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->expects($this->never())->method('update');
        $conn->expects($this->never())->method('insert');
        $this->save(['profile_id' => 3, 'store_id' => 1] + $extra, $conn)->execute();
        $this->assertSame(['*/*/edit', ['id' => 3]], $this->redirect);
        $this->assertStringContainsString($message, (string)$this->messages['error'][0]);
    }

    public function testSaveStoresCustomLinksWithUnixLineEndings(): void
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->expects($this->once())->method('update')->with(
            'panth_seo_sitemap_profile',
            $this->callback(static fn(array $row) => $row['custom_links'] === "/a,daily,0.5\n/b\n/c"),
            ['profile_id = ?' => 3]
        );
        $this->save(['profile_id' => 3, 'store_id' => 1, 'custom_links' => "/a,daily,0.5\r\n/b\r/c"], $conn)->execute();
    }

    public function testSaveAcceptsSafeOutputPathsAndBoundaryValues(): void
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->expects($this->once())->method('update')->with(
            'panth_seo_sitemap_profile',
            $this->callback(static fn(array $row) => $row['output_path'] === 'media/seo/{store_code}/'
                && $row['max_urls_per_file'] === 50000
                && $row['priority_homepage'] === 1.0
                && $row['priority_product'] === 0.0),
            ['profile_id = ?' => 3]
        );
        $this->save([
            'profile_id' => 3,
            'store_id' => 1,
            'output_path' => 'media/seo/{store_code}/',
            'max_urls_per_file' => '50000',
            'priority_homepage' => '1',
            'priority_product' => '0',
        ], $conn)->execute();
        $this->assertSame(['*/*/', []], $this->redirect);
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
                $this->assertSame('/', $row['output_path']);
                $this->assertSame('sitemap.xml', $row['sitemap_filename']);
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

    public function testSaveRejectsTargetUsedByAnotherProfile(): void
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->expects($this->never())->method('update');
        $target = $this->profileTarget(['conflict' => ['profile_id' => 2, 'name' => 'Luma', 'file' => 'sitemap.xml']]);
        $this->save(['profile_id' => 3, 'store_id' => 1, 'output_path' => '/'], $conn, [], true, true, $target)->execute();
        $this->assertSame(['*/*/edit', ['id' => 3]], $this->redirect);
        $this->assertStringContainsString('"Luma" (ID 2) already writes pub/sitemap.xml', $this->messages['error'][0]);
    }

    public function testSaveWithoutFilenameColumnKeepsFallbackName(): void
    {
        $conn = $this->createMock(AdapterInterface::class);
        $conn->expects($this->never())->method('update');
        $target = $this->profileTarget(['column' => false]);
        $this->save(['profile_id' => 3, 'store_id' => 1, 'sitemap_filename' => 'luma.xml'], $conn, [], true, true, $target)->execute();
        $this->assertStringContainsString('setup:upgrade', $this->messages['error'][0]);
        $this->assertStringContainsString('the filename is sitemap.xml', $this->messages['error'][0]);

        $ok = $this->createMock(AdapterInterface::class);
        $ok->expects($this->once())->method('update')->with(
            'panth_seo_sitemap_profile',
            $this->callback(static fn(array $row) => !array_key_exists('sitemap_filename', $row) && $row['output_path'] === '/'),
            ['profile_id = ?' => 3]
        );
        $this->save(['profile_id' => 3, 'store_id' => 1, 'sitemap_filename' => 'sitemap.xml'], $ok, [], true, true, $this->profileTarget(['column' => false]))->execute();
    }

    public function testSaveMovingTargetRemovesOldFilesAndResetsStats(): void
    {
        $previous = ['profile_id' => 3, 'store_id' => 1, 'output_path' => 'xmlsitemap/{store_code}/', 'sitemap_filename' => null];
        $conn = $this->createMock(AdapterInterface::class);
        $conn->expects($this->once())->method('update')->with(
            'panth_seo_sitemap_profile',
            $this->callback(static fn(array $row) => $row['file_count'] === 0
                && $row['url_count'] === 0
                && $row['last_generated_at'] === null
                && $row['sitemap_filename'] === 'sitemap_hyva.xml'),
            ['profile_id = ?' => 3]
        );
        $builder = $this->createMock(Builder::class);
        $builder->expects($this->once())->method('removeProfileFiles')->with($previous)->willReturn(3);

        $this->save(
            ['profile_id' => 3, 'store_id' => 1, 'output_path' => '/', 'sitemap_filename' => 'sitemap_hyva.xml'],
            $conn,
            [],
            true,
            true,
            $this->profileTarget(['previous' => $previous]),
            $builder
        )->execute();

        $this->assertSame(['Sitemap profile saved.'], $this->messages['success']);
        $this->assertStringContainsString(
            'changed from pub/xmlsitemap/{store_code}/sitemap.xml to pub//sitemap_hyva.xml',
            $this->messages['notice'][0]
        );
    }

    public function testSaveWithSameTargetKeepsFilesAndStats(): void
    {
        $previous = ['profile_id' => 3, 'store_id' => 1, 'output_path' => '/', 'sitemap_filename' => 'sitemap.xml'];
        $conn = $this->createMock(AdapterInterface::class);
        $conn->expects($this->once())->method('update')->with(
            'panth_seo_sitemap_profile',
            $this->callback(static fn(array $row) => !array_key_exists('file_count', $row)),
            ['profile_id = ?' => 3]
        );
        $builder = $this->createMock(Builder::class);
        $builder->expects($this->never())->method('removeProfileFiles');
        $this->save(
            ['profile_id' => 3, 'store_id' => 1, 'output_path' => '', 'sitemap_filename' => ' '],
            $conn,
            [],
            true,
            true,
            $this->profileTarget(['previous' => $previous]),
            $builder
        )->execute();
        $this->assertSame([], $this->messages['notice']);
    }

    public function testSaveReportsFailedCleanupAndCoreSitemapConflict(): void
    {
        $previous = ['profile_id' => 3, 'store_id' => 1, 'output_path' => 'old', 'sitemap_filename' => 'sitemap.xml'];
        $builder = $this->createStub(Builder::class);
        $builder->method('removeProfileFiles')->willThrowException(new \RuntimeException('read-only'));
        $target = $this->profileTarget([
            'previous' => $previous,
            'core' => [['sitemap_id' => 9, 'file' => 'sitemap.xml']],
        ]);
        $this->save(
            ['profile_id' => 3, 'store_id' => 1, 'output_path' => '/'],
            $this->createStub(AdapterInterface::class),
            [],
            true,
            true,
            $target,
            $builder
        )->execute();

        $this->assertStringContainsString('could not be removed: read-only', $this->messages['warning'][0]);
        $this->assertStringContainsString('Site Map, ID 9) also writes pub/sitemap.xml', $this->messages['warning'][1]);
        $this->assertSame(['*/*/', []], $this->redirect);
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

    public function testGenerateSingleDisabledProfileWarns(): void
    {
        $builder = $this->createMock(Builder::class);
        $builder->method('loadProfile')->willReturn(['profile_id' => 5, 'name' => 'Main']);
        $stats = ['url_count' => 0, 'file_count' => 0, 'generation_time' => 0.0, 'files' => [], 'disabled' => true];
        $builder->method('buildFromProfile')->willReturn($stats);
        $builder->expects($this->once())->method('updateProfileStats')->with(5, $stats);

        (new Generate($this->context(['id' => 5]), $builder))->execute();
        $this->assertSame([], $this->messages['success']);
        $this->assertCount(1, $this->messages['warning']);
        $this->assertStringContainsString('profile "Main" was not generated', $this->messages['warning'][0]);
        $this->assertStringContainsString('XML Sitemap > General > Enabled', $this->messages['warning'][0]);
    }

    public function testGenerateAllSkipsDisabledProfilesInTotals(): void
    {
        $builder = $this->createStub(Builder::class);
        $builder->method('loadActiveProfiles')->willReturn([
            ['profile_id' => 1, 'name' => 'Hyva'],
            ['profile_id' => 2, 'name' => 'Luma'],
        ]);
        $builder->method('buildFromProfile')->willReturnCallback(
            static fn(array $p) => (int) $p['profile_id'] === 2
                ? ['url_count' => 0, 'file_count' => 0, 'generation_time' => 0.0, 'files' => [], 'disabled' => true]
                : ['url_count' => 7, 'file_count' => 3, 'generation_time' => 0.25, 'files' => []]
        );

        (new Generate($this->context(), $builder))->execute();
        $this->assertSame(
            ['Sitemap generated for 1 profile(s): 7 URLs in 3 files (0.25 seconds total).'],
            $this->messages['success']
        );
        $this->assertCount(1, $this->messages['warning']);
        $this->assertStringContainsString('"Luma"', $this->messages['warning'][0]);
    }

    public function testGenerateAllWithEveryProfileDisabledShowsNoSuccess(): void
    {
        $builder = $this->createStub(Builder::class);
        $builder->method('loadActiveProfiles')->willReturn([['profile_id' => 1, 'name' => 'Hyva']]);
        $builder->method('buildFromProfile')->willReturn(
            ['url_count' => 0, 'file_count' => 0, 'generation_time' => 0.0, 'files' => [], 'disabled' => true]
        );

        (new Generate($this->context(), $builder))->execute();
        $this->assertSame([], $this->messages['success']);
        $this->assertCount(1, $this->messages['warning']);
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
