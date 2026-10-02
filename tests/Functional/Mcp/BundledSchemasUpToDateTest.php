<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class BundledSchemasUpToDateTest extends KernelTestCase
{
    #[TestDox('BundledSchemas.php equals the output of app:schema:bundle')]
    public function testBundledSchemasAreUpToDate(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:schema:bundle'));

        $tester->execute(['--check' => true]);

        $tester->assertCommandIsSuccessful($tester->getDisplay());
    }
}
