<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * A2 marked the tenant of the user. Its purge job deletes the user soon.
 */
final class TenantBeingDeleted extends CustomUserMessageAccountStatusException
{
    public function __construct()
    {
        parent::__construct('The account is being deleted.');
    }
}
