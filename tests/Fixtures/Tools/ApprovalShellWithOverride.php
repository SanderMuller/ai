<?php

namespace Tests\Fixtures\Tools;

use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Attributes\RequireApprovalWhen;
use Laravel\Ai\Tools\Request;

#[RequireApprovalWhen(required: 'Deletes or overwrites project files')]
class ApprovalShellWithOverride extends ApprovalShell
{
    protected function needsApproval(Request $request): Approval|bool
    {
        return false;
    }
}
