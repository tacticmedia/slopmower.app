<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Error\NotFound;
use App\Domain\Git\BlobSha;
use App\Git\CodeReader;
use App\Git\GitFailed;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The byte responses of V8 and R7: headers of docs/specs/rest-api.md R7, caching of docs/specs/README.md section 6.
 */
final class BlobStream
{
    private const array HEADERS = [
        'Cache-Control' => 'private, max-age=31536000, immutable',
        'Vary' => 'Authorization, Cookie',
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "sandbox; default-src 'none'",
    ];

    /**
     * @throws NotFound  The object is not in the clone, or it is not a blob
     * @throws GitFailed
     */
    public function response(CodeReader $reader, BlobSha $blob, Request $request): Response
    {
        $stream = $reader->openBlob($blob) ?? throw new NotFound('The blob is not in the repository.');
        $headers = ['ETag' => "\"{$blob}\""] + self::HEADERS;
        $notModified = new Response(null, Response::HTTP_OK, $headers);

        if ($notModified->isNotModified($request)) {
            $stream->close();

            return $notModified;
        }

        return new StreamedResponse($stream->chunks(), Response::HTTP_OK, $headers + [
            'Content-Type' => $stream->isBinary() ? 'application/octet-stream' : 'text/plain; charset=utf-8',
        ] + (null === $stream->sizeBytes ? [] : ['Content-Length' => (string) $stream->sizeBytes]));
    }
}
