<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Application\Query\IssueQuery;
use App\Domain\Issue\IssueKey;
use App\Entity\Issue;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * The visible issue of the `{issueKey}` of a path, for `#[ValueResolver(IssueKeyResolver::class)] Issue $issue`. It runs
 * with the other resolvers, so a hidden key gives 404 before the 403 and the 422 (docs/specs/README.md section 4).
 */
#[AsTargetedValueResolver]
final class IssueKeyResolver implements ValueResolverInterface
{
    public function __construct(private readonly IssueQuery $issues)
    {
    }

    /**
     * @return list<Issue>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        return [$this->issues->issue(IssueKey::fromString($request->attributes->getString('issueKey'), 'issueKey'))];
    }
}
