<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Security\AgentUser;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Uid\Uuid;

/**
 * Stores a session under `Uuid::v5(<token ID>, <session ID>)`, so a session ID answers only to the token that opened
 * it. Another token gets the SDK's 404 "Session not found", and its DELETE removes nothing.
 */
#[AsDecorator('mcp.server.holistic_review.session.store')]
final class TokenBoundSessionStore implements SessionStoreInterface
{
    public function __construct(
        #[AutowireDecorated] private readonly SessionStoreInterface $inner,
        private readonly Security $security,
    ) {
    }

    public function exists(Uuid $id): bool
    {
        return $this->inner->exists($this->storedId($id));
    }

    public function read(Uuid $id): string|false
    {
        return $this->inner->read($this->storedId($id));
    }

    public function write(Uuid $id, string $data): bool
    {
        return $this->inner->write($this->storedId($id), $data);
    }

    public function destroy(Uuid $id): bool
    {
        return $this->inner->destroy($this->storedId($id));
    }

    public function gc(): array
    {
        return $this->inner->gc();
    }

    private function storedId(Uuid $sessionId): Uuid
    {
        $agent = $this->security->getUser();

        if (!$agent instanceof AgentUser) {
            throw new \LogicException('An MCP session needs the API token of the request.');
        }

        return Uuid::v5(new Uuid($agent->tokenId->toRfc4122()), $sessionId->toRfc4122());
    }
}
