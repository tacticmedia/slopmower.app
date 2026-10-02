<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Anchoring;

use App\Domain\Anchoring\LineSimilarity;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LineSimilarityTest extends KernelTestCase
{
    #[TestDox('Identical lists give 1, disjoint lists give 0, and 3 common lines of 4 and 6 give 0.6')]
    public function testMeasuresTheLongestCommonSubsequence(): void
    {
        self::assertSame(1.0, LineSimilarity::between(['  if ($a) {', 'return  $b;', '}'], ["\tif (\$a) {", "\treturn \$b;\r", '}']));
        self::assertSame(0.0, LineSimilarity::between(['a', 'b'], ['c', 'd', 'e']));
        self::assertSame(0.6, LineSimilarity::between(['a', 'b', 'c', 'd'], ['d', 'a', 'x', 'b', 'y', 'c']));
    }
}
