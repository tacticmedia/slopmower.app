<?php

declare(strict_types=1);

namespace App\Entity;

use App\Webhook\WebhookDeliverySql;
use Doctrine\ORM\Mapping as ORM;

/**
 * A GitHub delivery that the webhook consumer has routed. Not tenant owned. Only {@see WebhookDeliverySql} reads and
 * writes it, so the entity has no repository.
 */
#[ORM\Entity]
#[ORM\Table(name: 'webhook_delivery')]
#[ORM\Index(name: 'idx_webhook_delivery_received_at', columns: ['received_at'])]
class WebhookDelivery
{
    /** `X-GitHub-Delivery` */
    #[ORM\Id]
    #[ORM\Column]
    private string $id;

    #[ORM\Column]
    private \DateTimeImmutable $receivedAt;

    public function __construct(string $id, \DateTimeImmutable $receivedAt)
    {
        $this->id = $id;
        $this->receivedAt = $receivedAt;
    }
}
