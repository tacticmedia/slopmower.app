<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conventions;

use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

final class QaWorkflowTest extends KernelTestCase
{
    private const string GATE_CONDITION = "\${{ !cancelled() && steps.up.outcome != 'skipped' }}";

    #[TestDox('Each gate step of qa.yml runs a make target or npm audit, never a command of its own')]
    public function testGateStepsRunMakeTargetsOrAudits(): void
    {
        $gateCommands = $this->gateCommands();

        self::assertNotSame([], $gateCommands);
        self::assertSame([], array_values(array_filter(
            $gateCommands,
            static fn (string $command): bool => 1 !== preg_match('/^(make [a-z0-9-]+( c="[^"]+")?|npm audit( --prefix symbols)?)$/', $command),
        )));
    }

    #[TestDox('qa.yml has a gate step for each gate of make check')]
    public function testWorkflowRunsEveryGateOfMakeCheck(): void
    {
        self::assertSame(1, preg_match('/^check: ([a-z0-9 ]+?) +##/m', (string) file_get_contents(dirname(__DIR__, 3).'/Makefile'), $check));

        $workflowTargets = array_map(static fn (string $command): string => explode(' ', $command)[1] ?? '', $this->gateCommands());

        self::assertSame([], array_values(array_diff(explode(' ', $check[1]), $workflowTargets)));
    }

    /**
     * @return list<string>
     */
    private function gateCommands(): array
    {
        $workflow = Yaml::parseFile(dirname(__DIR__, 3).'/.github/workflows/qa.yml');
        self::assertIsArray($workflow);
        $steps = $workflow['jobs']['qa']['steps'] ?? null;
        self::assertIsArray($steps);

        $commands = [];
        foreach ($steps as $step) {
            if (is_array($step) && self::GATE_CONDITION === ($step['if'] ?? null) && is_string($step['run'] ?? null)) {
                $commands[] = $step['run'];
            }
        }

        return $commands;
    }
}
