<?php

declare(strict_types=1);

namespace App\Tests\Support\GitHub;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;

/**
 * The code endpoints of the GitHub API that Pass-through reads, answered by running git on a bare repository: commits
 * by ref (SHA media type and JSON with files), commit lists, git commits, trees, raw blobs, compare, and GraphQL blame.
 * {@see GitHubResponses} gives it the bare repositories of LocalGitHub.
 */
final class GitBackedGitHub
{
    private const int FILES_PER_PAGE = 300;

    private const array STATUS_BY_LETTER = ['A' => 'added', 'D' => 'removed', 'M' => 'modified', 'R' => 'renamed', 'C' => 'copied', 'T' => 'changed'];

    private bool $truncateNextRecursiveTree = false;

    /**
     * @param string $gitDir A bare repository
     */
    public function __construct(private readonly string $gitDir, private readonly string $apiPrefix)
    {
    }

    /**
     * The next recursive tree answer is cut, as GitHub cuts a large one.
     */
    public function truncateNextRecursiveTree(): void
    {
        $this->truncateNextRecursiveTree = true;
    }

    /**
     * @param string               $path    Below `repos/{owner}/{name}/`, with the query
     * @param array<string, mixed> $headers Lower-case names
     */
    public function answer(string $path, array $headers): ?MockResponse
    {
        $accept = \is_string($headers['accept'] ?? null) ? $headers['accept'] : '';
        [$route, $query] = explode('?', $path, 2) + [1 => ''];
        parse_str($query, $parameters);

        return match (true) {
            1 === preg_match('~^commits/(.+)$~', $route, $match) => str_contains($accept, 'vnd.github.sha') ? $this->resolve(rawurldecode($match[1])) : $this->commitWithFiles(rawurldecode($match[1]), (int) ($parameters['page'] ?? 1)),
            'commits' === $route => $this->log(\is_string($parameters['sha'] ?? null) ? $parameters['sha'] : 'HEAD', \is_string($parameters['per_page'] ?? null) ? (int) $parameters['per_page'] : 30),
            1 === preg_match('~^git/commits/([0-9a-f]+)$~', $route, $match) => $this->gitCommit($match[1]),
            1 === preg_match('~^git/trees/(.+)$~', $route, $match) => $this->tree(rawurldecode($match[1]), isset($parameters['recursive'])),
            1 === preg_match('~^git/blobs/([0-9a-f]+)$~', $route, $match) => $this->blob($match[1]),
            1 === preg_match('~^compare/([0-9a-f]+)\.\.\.([0-9a-f]+)$~', $route, $match) => $this->compare($match[1], $match[2]),
            default => null,
        };
    }

    /**
     * @param array{query?: mixed, variables?: mixed} $body
     */
    public function blame(array $body): MockResponse
    {
        $variables = \is_array($body['variables'] ?? null) ? $body['variables'] : [];
        $output = $this->git(['blame', '--porcelain', '--end-of-options', (string) ($variables['oid'] ?? ''), (string) ($variables['path'] ?? '')]);

        if (null === $output) {
            return self::json(200, ['data' => ['repository' => ['object' => null]], 'errors' => [['message' => 'Could not resolve file']]]);
        }

        $ranges = [];
        /** @var array<string, array{name: string, date: string, message: string}> $commits */
        $commits = [];
        $current = null;

        foreach (explode("\n", $output) as $line) {
            if (1 === preg_match('/^([0-9a-f]{40}) \d+ (\d+)/', $line, $match)) {
                $current = $match[1];
                $commits[$current] ??= ['name' => '', 'date' => '', 'message' => ''];
                $last = array_key_last($ranges);
                $lineNumber = (int) $match[2];

                if (null !== $last && $ranges[$last]['commit'] === $current && $ranges[$last]['endingLine'] + 1 === $lineNumber) {
                    ++$ranges[$last]['endingLine'];
                } else {
                    $ranges[] = ['startingLine' => $lineNumber, 'endingLine' => $lineNumber, 'commit' => $current];
                }
            } elseif (null !== $current && str_starts_with($line, 'author ')) {
                $commits[$current]['name'] = substr($line, 7);
            } elseif (null !== $current && str_starts_with($line, 'author-time ')) {
                $commits[$current]['date'] = gmdate('Y-m-d\TH:i:s\Z', (int) substr($line, 12));
            } elseif (null !== $current && str_starts_with($line, 'summary ')) {
                $commits[$current]['message'] = substr($line, 8);
            }
        }

        $ranges = array_map(static function (array $range) use ($commits): array {
            ['name' => $name, 'date' => $date, 'message' => $message] = $commits[$range['commit']];

            return ['startingLine' => $range['startingLine'], 'endingLine' => $range['endingLine'], 'commit' => ['oid' => $range['commit'], 'message' => $message, 'author' => ['name' => $name, 'date' => $date]]];
        }, $ranges);

        return self::json(200, ['data' => ['repository' => ['object' => ['blame' => ['ranges' => $ranges]]]]]);
    }

    /**
     * An ambiguous prefix answers as a missing commit, as on GitHub.
     */
    private function resolve(string $ref): MockResponse
    {
        $sha = $this->git(['rev-parse', '--verify', '--quiet', '--end-of-options', "{$ref}^{commit}"]);

        return null === $sha ? self::notFoundCommit($ref) : new MockResponse(trim($sha), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/vnd.github.sha']]);
    }

    private function commitWithFiles(string $sha, int $page): MockResponse
    {
        $parents = $this->git(['log', '-1', '--format=%P', '--end-of-options', $sha]);

        if (null === $parents) {
            return self::notFoundCommit($sha);
        }

        $parent = explode(' ', trim($parents))[0];
        $range = '' === $parent ? ['--root', $sha] : [$parent, $sha];
        $numstat = explode("\0", rtrim((string) $this->git(['diff-tree', '-r', '-M', '-z', '--numstat', '--no-commit-id', '--end-of-options', ...$range]), "\0"));
        $raw = explode("\0", rtrim((string) $this->git(['diff-tree', '-r', '-M', '-z', '--raw', '--no-commit-id', '--end-of-options', ...$range]), "\0"));
        $files = [];

        for ($index = 0, $numstatIndex = 0; isset($raw[$index + 1]);) {
            preg_match('/^:\d{6} \d{6} [0-9a-f]+ ([0-9a-f]+) ([A-Z])/', $raw[$index], $match);
            $status = self::STATUS_BY_LETTER[$match[2] ?? 'M'] ?? 'modified';
            $twoPaths = \in_array($status, ['renamed', 'copied'], true);
            [$additions, $deletions] = explode("\t", $numstat[$numstatIndex]) + ['0', '0'];
            $file = [
                'sha' => $match[1] ?? '',
                'filename' => $twoPaths ? $raw[$index + 2] : $raw[$index + 1],
                'status' => $status,
                'additions' => '-' === $additions ? 0 : (int) $additions,
                'deletions' => '-' === $deletions ? 0 : (int) $deletions,
            ];
            $file['changes'] = $file['additions'] + $file['deletions'];
            $files[] = $twoPaths ? $file + ['previous_filename' => $raw[$index + 1]] : $file;
            $index += $twoPaths ? 3 : 2;
            $numstatIndex += $twoPaths ? 3 : 1;
        }

        $headers = \count($files) > $page * self::FILES_PER_PAGE ? ['link' => "<{$this->apiPrefix}commits/{$sha}?page=".($page + 1).'>; rel="next"'] : [];

        return self::json(200, ['sha' => $sha, 'files' => \array_slice($files, ($page - 1) * self::FILES_PER_PAGE, self::FILES_PER_PAGE)], $headers);
    }

    private function log(string $sha, int $limit): MockResponse
    {
        $output = $this->git(['log', '-z', '-n', (string) $limit, '--format=%H%x00%an%x00%aI%x00%cI%x00%B', '--end-of-options', $sha]);

        if (null === $output) {
            return self::notFoundCommit($sha);
        }

        $fields = explode("\0", $output);
        $items = [];

        for ($index = 0; isset($fields[$index + 4]); $index += 5) {
            $items[] = ['sha' => trim($fields[$index]), 'commit' => [
                'author' => ['name' => $fields[$index + 1], 'date' => self::utc($fields[$index + 2])],
                'committer' => ['date' => self::utc($fields[$index + 3])],
                'message' => rtrim($fields[$index + 4], "\n"),
            ]];
        }

        return self::json(200, $items);
    }

    private function gitCommit(string $sha): MockResponse
    {
        $output = $this->git(['log', '-1', '--format=%T%x00%P%x00%an%x00%ae%x00%aI%x00%cI%x00%B', '--end-of-options', "{$sha}^{commit}"]);

        if (null === $output || 1 !== preg_match('/^[0-9a-f]{40}$/', $sha)) {
            return self::json(404, ['message' => 'Not Found']);
        }

        [$tree, $parents, $name, $email, $authoredAt, $committedAt, $message] = explode("\0", $output, 7);

        return self::json(200, [
            'sha' => $sha,
            'tree' => ['sha' => $tree],
            'parents' => array_map(static fn (string $parent): array => ['sha' => $parent], array_values(array_filter(explode(' ', $parents)))),
            'author' => ['name' => $name, 'email' => $email, 'date' => self::utc($authoredAt)],
            'committer' => ['date' => self::utc($committedAt)],
            'message' => rtrim($message, "\n"),
        ]);
    }

    /**
     * A recursive answer lists the trees too, as GitHub does.
     */
    private function tree(string $treeish, bool $recursive): MockResponse
    {
        $type = trim((string) $this->git(['cat-file', '-t', '--end-of-options', $treeish]));

        if (!\in_array($type, ['tree', 'commit'], true)) {
            return self::json('' === $type ? 404 : 422, ['message' => '' === $type ? 'Not Found' : 'Invalid tree']);
        }

        $output = (string) $this->git(['ls-tree', ...$recursive ? ['-r', '-t'] : [], '--long', '-z', '--end-of-options', $treeish]);
        $entries = [];

        foreach (array_filter(explode("\0", $output)) as $record) {
            if (1 !== preg_match('/^(\d{6}) (\w+) ([0-9a-f]+) +(-|\d+)\t(.+)$/s', $record, $match)) {
                continue;
            }

            $entries[] = ['path' => $match[5], 'mode' => $match[1], 'type' => $match[2], 'sha' => $match[3]] + ('blob' === $match[2] ? ['size' => (int) $match[4]] : []);
        }

        $truncated = $recursive && $this->truncateNextRecursiveTree;
        $this->truncateNextRecursiveTree = $this->truncateNextRecursiveTree && !$recursive;

        return self::json(200, [
            // GitHub echoes a commit SHA, and gives the tree SHA of a `{sha}:{path}` form.
            'sha' => str_contains($treeish, ':') ? trim((string) $this->git(['rev-parse', '--verify', '--end-of-options', "{$treeish}^{tree}"])) : $treeish,
            'tree' => $truncated ? \array_slice($entries, 0, 1) : $entries,
            'truncated' => $truncated,
        ]);
    }

    private function blob(string $sha): MockResponse
    {
        $bytes = 'blob' === trim((string) $this->git(['cat-file', '-t', '--end-of-options', $sha])) ? $this->git(['cat-file', 'blob', '--end-of-options', $sha]) : null;

        return null === $bytes
            ? self::json(404, ['message' => 'Not Found'])
            // An uncached raw blob of GitHub has no Content-Length.
            : new MockResponse($bytes, ['http_code' => 200, 'response_headers' => ['content-type' => 'application/octet-stream']]);
    }

    /**
     * Three dots, as GitHub: from the merge base of the two commits.
     */
    private function compare(string $base, string $head): MockResponse
    {
        $mergeBase = $this->git(['merge-base', '--end-of-options', $base, $head]);
        $output = null === $mergeBase ? null : $this->git(['diff', '--name-status', '-M', '-z', '--end-of-options', trim($mergeBase), $head, '--']);

        if (null === $output) {
            return self::json(404, ['message' => 'Not Found']);
        }

        $records = explode("\0", rtrim($output, "\0"));
        $files = [];

        for ($index = 0; isset($records[$index + 1]);) {
            $status = self::STATUS_BY_LETTER[$records[$index][0]] ?? 'modified';
            $twoPaths = \in_array($status, ['renamed', 'copied'], true);
            $files[] = ['filename' => $twoPaths ? $records[$index + 2] : $records[$index + 1], 'status' => $status, 'additions' => 0, 'deletions' => 0]
                + ($twoPaths ? ['previous_filename' => $records[$index + 1]] : []);
            $index += $twoPaths ? 3 : 2;
        }

        return self::json(200, ['files' => $files]);
    }

    /**
     * @param list<string> $arguments
     *
     * @return string|null Null when git fails
     */
    private function git(array $arguments): ?string
    {
        $process = new Process(['git', '--git-dir='.$this->gitDir, ...$arguments], env: ['GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'LC_ALL' => 'C']);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    private static function utc(string $isoTime): string
    {
        return new \DateTimeImmutable($isoTime)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function notFoundCommit(string $ref): MockResponse
    {
        return self::json(422, ['message' => "No commit found for SHA: {$ref}"]);
    }

    /**
     * @param array<mixed>          $body
     * @param array<string, string> $headers
     */
    private static function json(int $status, array $body, array $headers = []): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json; charset=utf-8'] + $headers]);
    }
}
