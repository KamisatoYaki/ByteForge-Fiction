<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Database\Eloquent\Model;

/**
 * Thrown when a user attempts to approve or reject a record they submitted.
 *
 * Applies to every actor, including administrators and superusers.
 */
class SelfApprovalNotAllowed extends Exception
{
    public function __construct(public readonly Model $approvable)
    {
        parent::__construct(
            'Users cannot approve or reject a request they personally submitted.'
        );
    }
}
