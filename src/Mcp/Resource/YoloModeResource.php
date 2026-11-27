<?php

declare(strict_types=1);

namespace App\Mcp\Resource;

use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpResource;

/**
 * docs/specs/mcp.md "YOLO mode" (D148). The SDK encodes the array as JSON text.
 */
#[McpResource(
    uri: 'slopmower://yolo_mode',
    name: 'yolo_mode',
    title: 'YOLO mode',
    description: 'Whether YOLO mode is on in each project that this token sees. In YOLO mode the yolo_ tools do steps that otherwise only the user does. With YOLO mode off they fail with forbidden.',
    mimeType: 'application/json',
)]
final readonly class YoloModeResource
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{projects: list<array{key: string, yoloMode: bool}>}
     */
    public function __invoke(): array
    {
        $projects = $this->entityManager->getRepository(Project::class)->findBy([], ['key' => 'ASC']);

        return ['projects' => array_map(static fn (Project $project): array => ['key' => $project->getKey(), 'yoloMode' => $project->isYoloMode()], $projects)];
    }
}
