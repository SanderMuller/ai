<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\RequireApprovalWhen;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

#[RequireApprovalWhen(
    required: 'Deletes or overwrites project files',
    notRequired: 'Only reads project files',
)]
class ApprovalShell implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Runs a shell command in the project directory.';
    }

    public function handle(Request $request): string
    {
        return 'done';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
