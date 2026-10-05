<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Model\Sitemap;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\XmlSitemap\Helper\Config;
use Panth\XmlSitemap\Helper\PathResolver;
use Panth\XmlSitemap\Model\Sitemap\ProfileTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProfileTargetTest extends TestCase
{
    private array $wheres = [];

    private function store(int $id, string $code): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);
        return $store;
    }

    private function target(?AdapterInterface $conn = null, string $configFilename = 'sitemap.xml'): ProfileTarget
    {
        $resource = $this->createStub(ResourceConnection::class);
        if ($conn === null) {
            $resource->method('getConnection')->willThrowException(new \RuntimeException('no db'));
        } else {
            $resource->method('getConnection')->willReturn($conn);
        }
        $resource->method('getTableName')->willReturnArgument(0);

        $stores = [1 => $this->store(1, 'default'), 2 => $this->store(2, 'luma')];
        $sm = $this->createStub(StoreManagerInterface::class);
        $sm->method('getStore')->willReturnCallback(static fn($id) => $stores[(int) $id]);
        $sm->method('getDefaultStoreView')->willReturn($stores[1]);

        $config = $this->createStub(Config::class);
        $config->method('getSitemapIndexFilename')->willReturn($configFilename);

        return new ProfileTarget($resource, $sm, new PathResolver(), $config);
    }

    private function connection(array $profileRows, array $coreRows = [], bool $hasColumn = true, bool $coreTable = true): AdapterInterface
    {
        $this->wheres = [];
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('tableColumnExists')->willReturn($hasColumn);
        $conn->method('isTableExists')->willReturn($coreTable);
        $tables = new \SplObjectStorage();
        $conn->method('select')->willReturnCallback(function () use ($tables) {
            $select = $this->createStub(Select::class);
            $select->method('from')->willReturnCallback(static function ($name) use ($select, $tables) {
                $tables[$select] = $name;
                return $select;
            });
            $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
                $this->wheres[] = [$cond, $value];
                return $select;
            });
            return $select;
        });
        $conn->method('fetchAll')->willReturnCallback(
            static fn($select) => $tables->contains($select) && $tables[$select] === 'sitemap' ? $coreRows : $profileRows
        );
        $conn->method('fetchRow')->willReturn(['profile_id' => 7, 'output_path' => '/']);
        return $conn;
    }

    public static function formatProvider(): array
    {
        return [
            'defaults' => ['/', 'sitemap.xml', null],
            'store folder' => ['seo/{store_code}/', 'sitemap_luma.xml', null],
            'dotted name' => ['/', 'sitemap.en-gb.xml', null],
            'gzip name' => ['/', 'sitemap.xml.gz', 'sitemap filename "sitemap.xml.gz" is not allowed'],
            'no extension' => ['/', 'sitemap', 'is not allowed'],
            'folder in name' => ['/', 'seo/sitemap.xml', 'is not allowed'],
            'traversal name' => ['/', '../sitemap.xml', 'is not allowed'],
            'double dot name' => ['/', 'site..map.xml', 'is not allowed'],
            'leading dot' => ['/', '.sitemap.xml', 'is not allowed'],
            'space' => ['/', 'site map.xml', 'is not allowed'],
            'php name' => ['/', 'sitemap.php', 'is not allowed'],
            'traversal path' => ['../../app/etc', 'sitemap.xml', 'output path "../../app/etc" is not allowed'],
            'backslash path' => ['maps\\x', 'sitemap.xml', 'is not allowed'],
            'bad path chars' => ['maps/<b>', 'sitemap.xml', 'is not allowed'],
        ];
    }

    #[DataProvider('formatProvider')]
    public function testValidateFormat(string $path, string $filename, ?string $message): void
    {
        $error = $this->target()->validateFormat($path, $filename);
        if ($message === null) {
            $this->assertNull($error);
            return;
        }
        $this->assertNotNull($error);
        $this->assertStringContainsString($message, (string) $error);
    }

    public function testResolveRelativeFile(): void
    {
        $target = $this->target(null, 'legacy.xml');
        $this->assertSame('sitemap.xml', $target->resolveRelativeFile([
            'store_id' => 1, 'output_path' => '/', 'sitemap_filename' => 'sitemap.xml',
        ]));
        $this->assertSame('seo/luma/sitemap_luma.xml', $target->resolveRelativeFile([
            'store_id' => 2, 'output_path' => 'seo/{store_code}', 'sitemap_filename' => 'sitemap_luma.xml',
        ]));
        $this->assertSame('xmlsitemap/default/legacy.xml', $target->resolveRelativeFile([
            'store_id' => 0, 'output_path' => 'xmlsitemap/{store_code}/',
        ]));
        $this->assertSame('legacy.xml', $target->getFallbackFilename(1));
    }

    public function testFindProfileConflictAcrossStores(): void
    {
        $conn = $this->connection([
            ['profile_id' => 2, 'name' => 'Luma', 'store_id' => 2, 'output_path' => 'seo/{store_code}', 'sitemap_filename' => 'a.xml'],
            ['profile_id' => 3, 'name' => 'Hyva', 'store_id' => 1, 'output_path' => '/', 'sitemap_filename' => 'SITEMAP.xml'],
        ]);
        $conflict = $this->target($conn)->findProfileConflict(5, [
            'store_id' => 2, 'output_path' => '/', 'sitemap_filename' => 'sitemap.xml',
        ]);
        $this->assertSame(['profile_id' => 3, 'name' => 'Hyva', 'file' => 'SITEMAP.xml'], $conflict);
        $this->assertContains(['profile_id <> ?', 5], $this->wheres);
    }

    public function testFindProfileConflictNoneWhenTargetsDiffer(): void
    {
        $conn = $this->connection([
            ['profile_id' => 1, 'name' => 'Hyva', 'store_id' => 1, 'output_path' => 'xmlsitemap/{store_code}/', 'sitemap_filename' => null],
            ['profile_id' => 2, 'name' => 'Luma', 'store_id' => 2, 'output_path' => 'xmlsitemap/{store_code}/', 'sitemap_filename' => null],
        ]);
        $target = $this->target($conn);
        $this->assertNull($target->findProfileConflict(0, ['store_id' => 2, 'output_path' => '/', 'sitemap_filename' => 'sitemap.xml']));
        $this->assertSame([], $this->wheres, 'new profile compares against every row');
        $this->assertNull($target->findProfileConflict(1, ['store_id' => 1, 'output_path' => 'seo/{store_code}/']));
    }

    public function testFindCoreConflicts(): void
    {
        $conn = $this->connection([], [
            ['sitemap_id' => 1, 'sitemap_path' => '/', 'sitemap_filename' => 'sitemap.xml'],
            ['sitemap_id' => 2, 'sitemap_path' => '/seo/', 'sitemap_filename' => 'sitemap.xml'],
            ['sitemap_id' => 3, 'sitemap_path' => '/', 'sitemap_filename' => ''],
        ]);
        $target = $this->target($conn);
        $this->assertSame(
            [['sitemap_id' => 1, 'file' => 'sitemap.xml']],
            $target->findCoreConflicts(['store_id' => 1, 'output_path' => '/', 'sitemap_filename' => 'sitemap.xml'])
        );
        $this->assertSame(
            [['sitemap_id' => 2, 'file' => 'seo/sitemap.xml']],
            $target->findCoreConflicts(['store_id' => 2, 'output_path' => 'seo', 'sitemap_filename' => 'sitemap.xml'])
        );
        $this->assertSame([], $target->findCoreConflicts(['store_id' => 1, 'output_path' => '/', 'sitemap_filename' => 'other.xml']));
    }

    public function testFindCoreConflictsWithoutCoreTableOrDatabase(): void
    {
        $this->assertSame([], $this->target($this->connection([], [], true, false))->findCoreConflicts(['store_id' => 1]));
        $this->assertSame([], $this->target()->findCoreConflicts(['store_id' => 1]));
    }

    public function testHasFilenameColumnAndLoadProfile(): void
    {
        $this->assertTrue($this->target($this->connection([]))->hasFilenameColumn());
        $this->assertFalse($this->target($this->connection([], [], false))->hasFilenameColumn());
        $this->assertFalse($this->target()->hasFilenameColumn());

        $this->assertSame(['profile_id' => 7, 'output_path' => '/'], $this->target($this->connection([]))->loadProfile(7));
        $this->assertNull($this->target($this->connection([]))->loadProfile(0));
        $this->assertNull($this->target()->loadProfile(7));
    }

    public function testConflictLookupSkipsFilenameColumnWhenMissing(): void
    {
        $conn = $this->connection([
            ['profile_id' => 4, 'name' => 'Old', 'store_id' => 1, 'output_path' => '/'],
        ], [], false);
        $conflict = $this->target($conn)->findProfileConflict(0, ['store_id' => 1, 'output_path' => '/']);
        $this->assertSame(4, $conflict['profile_id']);
    }
}
