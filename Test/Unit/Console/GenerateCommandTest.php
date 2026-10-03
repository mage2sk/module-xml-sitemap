<?php
declare(strict_types=1);

namespace Panth\XmlSitemap\Test\Unit\Console;

use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Panth\XmlSitemap\Api\BuilderInterface;
use Panth\XmlSitemap\Console\Command\GenerateCommand;
use Panth\XmlSitemap\Model\Sitemap\Builder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class GenerateCommandTest extends TestCase
{
    private function store(int $id, string $code): StoreInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);
        return $store;
    }

    private function runCommand(BuilderInterface $builder, StoreRepositoryInterface $repo, array $input = []): CommandTester
    {
        $tester = new CommandTester(new GenerateCommand($builder, $repo, $this->createStub(AppState::class)));
        $tester->execute($input);
        return $tester;
    }

    public function testCommandName(): void
    {
        $cmd = new GenerateCommand(
            $this->createStub(BuilderInterface::class),
            $this->createStub(StoreRepositoryInterface::class),
            $this->createStub(AppState::class)
        );
        $this->assertSame('panth:seo:sitemap:generate', $cmd->getName());
        $this->assertTrue($cmd->getDefinition()->hasOption('store'));
        $this->assertTrue($cmd->getDefinition()->hasOption('profile'));
    }

    public function testProfileRequiresConcreteBuilder(): void
    {
        $tester = $this->runCommand(
            $this->createStub(BuilderInterface::class),
            $this->createStub(StoreRepositoryInterface::class),
            ['--profile' => '3']
        );
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('requires the Panth Builder', $tester->getDisplay());
    }

    public function testUnknownProfileFails(): void
    {
        $builder = $this->createStub(Builder::class);
        $builder->method('loadProfile')->willReturn(null);
        $tester = $this->runCommand($builder, $this->createStub(StoreRepositoryInterface::class), ['--profile' => '3']);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Profile not found: 3', $tester->getDisplay());
    }

    public function testProfileBuildSuccess(): void
    {
        $builder = $this->createMock(Builder::class);
        $builder->method('loadProfile')->willReturn(['profile_id' => 3, 'name' => 'Main', 'store_id' => 1]);
        $stats = ['url_count' => 5, 'file_count' => 2, 'generation_time' => 1.25, 'files' => ['/x/a.xml']];
        $builder->expects($this->once())->method('buildFromProfile')->willReturn($stats);
        $builder->expects($this->once())->method('updateProfileStats')->with(3, $stats);

        $tester = $this->runCommand($builder, $this->createStub(StoreRepositoryInterface::class), ['--profile' => '3']);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Done: 5 URLs in 2 files (1.25s)', $tester->getDisplay());
        $this->assertStringContainsString('- /x/a.xml', $tester->getDisplay());
    }

    public function testProfileBuildFailure(): void
    {
        $builder = $this->createStub(Builder::class);
        $builder->method('loadProfile')->willReturn(['profile_id' => 3]);
        $builder->method('buildFromProfile')->willThrowException(new \RuntimeException('oops'));
        $tester = $this->runCommand($builder, $this->createStub(StoreRepositoryInterface::class), ['--profile' => '3']);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Failed: oops', $tester->getDisplay());
    }

    public function testUnknownStoreFails(): void
    {
        $repo = $this->createStub(StoreRepositoryInterface::class);
        $repo->method('get')->willThrowException(new NoSuchEntityException());
        $tester = $this->runCommand($this->createStub(BuilderInterface::class), $repo, ['--store' => 'nope']);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Store not found: nope', $tester->getDisplay());
    }

    public function testStoreByIdWithoutProfilesUsesPlainBuild(): void
    {
        $repo = $this->createMock(StoreRepositoryInterface::class);
        $repo->expects($this->once())->method('getById')->with(2)->willReturn($this->store(2, 'fr'));
        $builder = $this->createMock(BuilderInterface::class);
        $builder->expects($this->once())->method('build')->with(2)->willReturn(['/x/fr.xml']);

        $tester = $this->runCommand($builder, $repo, ['--store' => '2']);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('store "fr" (id 2)', $tester->getDisplay());
        $this->assertStringContainsString('- /x/fr.xml', $tester->getDisplay());
    }

    public function testStoreWithProfilesBuildsEachProfile(): void
    {
        $repo = $this->createStub(StoreRepositoryInterface::class);
        $repo->method('get')->willReturn($this->store(1, 'default'));
        $builder = $this->createMock(Builder::class);
        $builder->method('loadActiveProfiles')->with(1)->willReturn([['profile_id' => 4], ['profile_id' => 5]]);
        $builder->method('loadProfile')->willReturnCallback(
            static fn(int $id) => $id === 4 ? ['profile_id' => 4, 'name' => 'ok'] : null
        );
        $builder->expects($this->once())->method('buildFromProfile')
            ->willReturn(['url_count' => 1, 'file_count' => 1, 'generation_time' => 0.1, 'files' => []]);
        $builder->expects($this->never())->method('build');

        $tester = $this->runCommand($builder, $repo, ['--store' => 'default']);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode(), 'one missing profile fails the run');
        $this->assertStringContainsString('Found 2 active profile(s) for store "default"', $tester->getDisplay());
    }

    public function testAllStoresSkipsAdminAndReportsFailures(): void
    {
        $repo = $this->createStub(StoreRepositoryInterface::class);
        $repo->method('getList')->willReturn([$this->store(0, 'admin'), $this->store(1, 'en'), $this->store(2, 'de')]);
        $builder = $this->createMock(BuilderInterface::class);
        $builder->expects($this->exactly(2))->method('build')->willReturnCallback(
            static function (int $id): array {
                if ($id === 2) {
                    throw new \RuntimeException('de broke');
                }
                return ['/x/en.xml'];
            }
        );

        $tester = $this->runCommand($builder, $repo);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringNotContainsString('"admin"', $display);
        $this->assertStringContainsString('- /x/en.xml', $display);
        $this->assertStringContainsString('Failed: de broke', $display);
    }

    public function testAllStoresWithProfiles(): void
    {
        $builder = $this->createStub(Builder::class);
        $builder->method('loadActiveProfiles')->willReturn([['profile_id' => 4]]);
        $builder->method('loadProfile')->willReturn(['profile_id' => 4, 'name' => 'ok']);
        $builder->method('buildFromProfile')
            ->willReturn(['url_count' => 1, 'file_count' => 1, 'generation_time' => 0.1, 'files' => []]);

        $tester = $this->runCommand($builder, $this->createStub(StoreRepositoryInterface::class));
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Found 1 active profile(s) across all stores', $tester->getDisplay());
    }
}
