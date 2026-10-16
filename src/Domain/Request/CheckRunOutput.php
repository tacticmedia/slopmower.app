<?php

declare(strict_types=1);

namespace App\Domain\Request;

use App\Domain\Error\InvalidInput;
use App\Domain\Identity\TenantId;

/**
 * The status and output of the check run of a pull request: docs/specs/webhooks-and-live-updates.md, "Check run".
 * The annotations have the shape of the GitHub REST API. Everyone who can see the pull request reads a run, and no
 * call deletes one, so it holds request headlines and links, never request text.
 */
final readonly class CheckRunOutput
{
    private const string TENANT_EXTERNAL_ID_PREFIX = 'tenant:';

    private const int MAX_SUMMARY_CHARACTERS = 65535;

    /** Room for the line that counts the requests that are not listed. */
    private const int SUMMARY_RESERVE_CHARACTERS = 100;

    private const int HEADLINE_CHARACTERS = 80;

    /**
     * @param 'in_progress'|'completed'                                                                                                       $status
     * @param 'success'|'neutral'|null                                                                                                        $conclusion
     * @param list<array{path: string, start_line: int, end_line: int, annotation_level: 'warning'|'notice', title: string, message: string}> $annotations
     */
    private function __construct(
        public string $status,
        public ?string $conclusion,
        public string $title,
        public string $summary,
        public array $annotations,
    ) {
    }

    /**
     * One check per user, so that the requests of one tenant never hide the unfinished requests of another.
     */
    public static function name(string $login): string
    {
        return "Slopmower ({$login})";
    }

    /**
     * @return string|null The login in a name of {@see self::name()}
     */
    public static function loginOf(string $name): ?string
    {
        return 1 === preg_match('/^Slopmower \((.+)\)$/', $name, $match) ? $match[1] : null;
    }

    /**
     * The `external_id` of a neutral run, which has no record. A login can pass to another user, the tenant cannot.
     */
    public static function tenantExternalId(TenantId $tenantId): string
    {
        return self::TENANT_EXTERNAL_ID_PREFIX.$tenantId->toString();
    }

    /**
     * @return TenantId|null The tenant in an `external_id` of {@see self::tenantExternalId()}
     */
    public static function tenantOf(string $externalId): ?TenantId
    {
        $id = str_starts_with($externalId, self::TENANT_EXTERNAL_ID_PREFIX) ? substr($externalId, \strlen(self::TENANT_EXTERNAL_ID_PREFIX)) : '';

        try {
            return '' === $id ? null : TenantId::fromString($id);
        } catch (InvalidInput) {
            return null;
        }
    }

    /**
     * @param list<CheckedRequest> $requests            In summary order. Without a location for a head that the clone lacks.
     * @param string|null          $noAnnotationsReason Why no request has an annotation, for a head that the clone lacks
     */
    public static function of(array $requests, ?string $noAnnotationsReason = null): self
    {
        $total = count($requests);
        $finished = count(array_filter($requests, static fn (CheckedRequest $request): bool => $request->finished()));
        [$status, $conclusion, $title] = match (true) {
            0 === $total => ['completed', 'neutral', 'No linked requests'],
            $finished === $total => ['completed', 'success', 'All requests finished'],
            default => ['in_progress', null, "{$finished} of {$total} ".(1 === $total ? 'request' : 'requests').' finished'],
        };
        $annotations = array_values(array_filter(array_map(self::annotation(...), $requests)));

        return new self($status, $conclusion, $title, self::summary($requests, $noAnnotationsReason), $annotations);
    }

    /**
     * Equal for the outputs that one run can show. An update appends annotations, the REST description names no
     * update from `completed` back to `in_progress`, and an update keeps the name, so other annotations, another
     * status, or another name need a new run.
     */
    public function hash(string $name): string
    {
        return hash('sha256', json_encode([$name, $this->status, $this->annotations], \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{path: string, start_line: int, end_line: int, annotation_level: 'warning'|'notice', title: string, message: string}|null
     */
    private static function annotation(CheckedRequest $request): ?array
    {
        $level = $request->annotationLevel();
        $path = $request->location?->path;

        if (null === $level || null === $path) {
            return null;
        }

        $range = $request->location->range;

        return [
            'path' => $path->toString(),
            'start_line' => $range->startLine ?? 1,
            'end_line' => $range->endLine ?? 1,
            'annotation_level' => $level,
            'title' => self::headline($request),
            'message' => self::statusLabel($request->status).' in Slopmower: '.$request->url,
        ];
    }

    /**
     * @param list<CheckedRequest> $requests
     */
    private static function summary(array $requests, ?string $noAnnotationsReason): string
    {
        $byReview = [];

        foreach ($requests as $request) {
            $byReview[$request->reviewId->toString()][] = $request;
        }

        $blocks = null === $noAnnotationsReason ? [] : [$noAnnotationsReason];

        foreach ($byReview as $reviewRequests) {
            $review = $reviewRequests[0];
            $finished = count(array_filter($reviewRequests, static fn (CheckedRequest $request): bool => $request->finished()));
            $closed = $review->reviewClosed ? ' The review is closed.' : '';
            $blocks[] = '### ['.self::linkText($review->reviewTitle).']('.$review->reviewUrl.')';
            $blocks[] = "{$finished} of ".count($reviewRequests)." finished.{$closed}";
            $blocks[] = implode("\n", array_map(static fn (CheckedRequest $request): string => self::summaryLine($request, null !== $noAnnotationsReason), $reviewRequests));
        }

        $summary = [] === $blocks ? 'No linked requests' : implode("\n\n", $blocks);

        return mb_strlen($summary) <= self::MAX_SUMMARY_CHARACTERS ? $summary : self::cut($summary);
    }

    private static function summaryLine(CheckedRequest $request, bool $noAnnotations): string
    {
        $line = '- '.self::statusLabel($request->status).': ['.self::linkText(self::headline($request)).']('.$request->url.')';

        if ($noAnnotations || $request->finished()) {
            return $line;
        }

        return match (true) {
            $request->general => "{$line}, general request without an annotation",
            null === $request->location?->path => "{$line}, not located at the head commit",
            default => $line,
        };
    }

    /**
     * Cuts at a line end and counts the request lines that it leaves out.
     */
    private static function cut(string $summary): string
    {
        $kept = mb_substr($summary, 0, self::MAX_SUMMARY_CHARACTERS - self::SUMMARY_RESERVE_CHARACTERS);
        $kept = substr($kept, 0, (int) strrpos($kept, "\n"));
        $left = substr_count(substr($summary, strlen($kept)), "\n- ");

        return "{$kept}\n\n{$left} more requests are not listed.";
    }

    private static function statusLabel(RequestStatus $status): string
    {
        return match ($status) {
            RequestStatus::Open => 'Open',
            RequestStatus::InProgress => 'In progress',
            RequestStatus::ReadyForReview => 'Ready for review',
            RequestStatus::Verified => 'Verified',
            RequestStatus::Reopened => 'Reopened',
            RequestStatus::WontDo => "Won't do",
        };
    }

    /**
     * The title, or the first line with text of the body, cut to HEADLINE_CHARACTERS.
     */
    private static function headline(CheckedRequest $request): string
    {
        $line = $request->title ?? trim(array_find(explode("\n", $request->body), static fn (string $line): bool => '' !== trim($line)) ?? '');

        return mb_strlen($line) > self::HEADLINE_CHARACTERS ? mb_substr($line, 0, self::HEADLINE_CHARACTERS - 3).'...' : $line;
    }

    private static function linkText(string $text): string
    {
        return addcslashes($text, '\\[]');
    }
}
