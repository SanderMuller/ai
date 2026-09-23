<?php

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Attributes\RequireApprovalWhen;
use Laravel\Ai\Classification;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\Fixtures\Tools\ApprovalShell;
use Tests\Fixtures\Tools\ApprovalShellWithOverride;

use function Laravel\Ai\agent;

test('the attribute requires approval when the classifier matches the criteria', function (): void {
    Classification::fake(fn (ClassificationPrompt $prompt) => [
        'approval' => new BooleanAnswer($prompt->contains('rm -rf') ? 0.95 : 0.05),
    ]);

    $tool = new ApprovalShell;

    expect($tool->shouldRequestApproval(new ToolRequest(['command' => 'rm -rf build']))->reason)
        ->toBe('Deletes or overwrites project files')
        ->and($tool->shouldRequestApproval(new ToolRequest(['command' => 'ls'])))->toBeNull();
});

test('the classifier receives the tool, its description, its arguments, and the criteria', function (): void {
    Classification::fake([['approval' => new BooleanAnswer(0.05)]]);

    (new ApprovalShell)->shouldRequestApproval(new ToolRequest(['command' => 'cat composer.json']));

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->state === [
        'tool' => 'ApprovalShell',
        'description' => 'Runs a shell command in the project directory.',
        'arguments' => ['command' => 'cat composer.json'],
    ] && $prompt->questions['approval']->criteria === [
        'true' => 'Deletes or overwrites project files',
        'false' => 'Only reads project files',
    ]);
});

test('the classifier receives the name the model calls the tool by', function (): void {
    Classification::fake([['approval' => new BooleanAnswer(0.05)]]);

    $tool = new #[RequireApprovalWhen('Deletes files')] class extends ApprovalShell
    {
        public function name(): string
        {
            return 'bash';
        }
    };

    $tool->shouldRequestApproval(new ToolRequest(['command' => 'ls']));

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->state['tool'] === 'bash');
});

test('the fluent criteria take precedence over the attribute', function (): void {
    Classification::fake([['approval' => new BooleanAnswer(0.95)]]);

    $approval = (new ApprovalShell)->requireApprovalWhen('Pushes to a remote')
        ->shouldRequestApproval(new ToolRequest(['command' => 'git push']));

    expect($approval->reason)->toBe('Pushes to a remote');

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->questions['approval']->criteria === [
        'true' => 'Pushes to a remote',
    ]);
});

test('explicit approval requirements skip the classifier', function (): void {
    Classification::fake();

    $request = new ToolRequest(['command' => 'rm -rf build']);

    expect((new ApprovalShell)->withoutApproval()->shouldRequestApproval($request))->toBeNull()
        ->and((new ApprovalShell)->requireApprovalWhen('Deletes files')->withoutApproval()->shouldRequestApproval($request))->toBeNull()
        ->and((new ApprovalShell)->requireApproval('Always')->shouldRequestApproval($request)->reason)->toBe('Always');

    Classification::assertNothingClassified();
});

test('the last approval call wins', function (): void {
    Classification::fake([['approval' => new BooleanAnswer(0.05)]]);

    $request = new ToolRequest(['command' => 'ls']);

    expect((new ApprovalShell)->requireApprovalWhen('Deletes files')->requireApproval('Always')->shouldRequestApproval($request)->reason)->toBe('Always')
        ->and((new ApprovalShell)->requireApproval('Always')->requireApprovalWhen('Deletes files')->shouldRequestApproval($request))->toBeNull();

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->questions['approval']->criteria === [
        'true' => 'Deletes files',
    ]);
});

test('an overridden needs approval method ignores the attribute and the fluent criteria', function (): void {
    Classification::fake();

    $request = new ToolRequest(['command' => 'rm -rf build']);

    expect((new ApprovalShellWithOverride)->shouldRequestApproval($request))->toBeNull()
        ->and((new ApprovalShellWithOverride)->requireApprovalWhen('Deletes files')->shouldRequestApproval($request))->toBeNull();

    Classification::assertNothingClassified();
});

test('the threshold decides how probable a match must be', function (): void {
    Classification::fake([
        ['approval' => new BooleanAnswer(0.49)],
        ['approval' => new BooleanAnswer(0.5)],
        ['approval' => new BooleanAnswer(0.4)],
    ]);

    $request = new ToolRequest(['command' => 'mv a b']);

    expect((new ApprovalShell)->shouldRequestApproval($request))->toBeNull()
        ->and((new ApprovalShell)->shouldRequestApproval($request)->reason)->toBe('Deletes or overwrites project files')
        ->and((new ApprovalShell)->requireApprovalWhen('Moves files', threshold: 0.3)->shouldRequestApproval($request)->reason)
        ->toBe('Moves files');
});

test('the timeout, provider, and model are passed to the classification', function (): void {
    Classification::fake([['approval' => new BooleanAnswer(0.05)]]);

    (new ApprovalShell)->requireApprovalWhen('Deletes files', timeout: 5, provider: Lab::OpenRouter, model: 'custom-model')
        ->shouldRequestApproval(new ToolRequest(['command' => 'ls']));

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->timeout === 5
        && $prompt->provider->name() === Lab::OpenRouter->value
        && $prompt->model === 'custom-model');
});

test('invalid criteria are rejected', function (Closure $criteria, string $message): void {
    expect($criteria)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'blank required' => [fn () => new RequireApprovalWhen(' '), 'Approval criteria may not be blank.'],
    'blank not required' => [fn () => new RequireApprovalWhen('Deletes files', ''), 'Approval criteria may not be blank.'],
    'threshold above one' => [fn () => new RequireApprovalWhen('Deletes files', threshold: 50), 'The approval threshold must be between 0 and 1.'],
    'threshold below zero' => [fn () => new RequireApprovalWhen('Deletes files', threshold: -0.1), 'The approval threshold must be between 0 and 1.'],
]);

test('a failed classification requires approval and reports why', function (Closure|array $response, string $exception): void {
    Exceptions::fake();

    Classification::fake($response);

    $approval = (new ApprovalShell)->shouldRequestApproval(new ToolRequest(['command' => 'ls']));

    expect($approval)->not->toBeNull()
        ->and($approval->reason)->toBe('Approval could not be decided automatically.');

    Exceptions::assertReported($exception);
})->with([
    'provider failure' => [fn () => fn () => throw new RuntimeException('Provider unavailable.'), RuntimeException::class],
    'missing answer' => [fn () => [new ClassificationResponse([], new TextUsage, new Meta('fake', 'fake'))], InvalidArgumentException::class],
    'non-boolean answer' => [fn () => [['approval' => new ChoiceAnswer('yes', ['yes' => 1.0])]], UnexpectedValueException::class],
]);

test('a prompt pauses on a tool call the classifier matches', function (): void {
    Classification::fake([['approval' => new BooleanAnswer(0.95)]]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_tool_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_1',
                'name' => 'ApprovalShell',
                'input' => (object) ['command' => 'rm -rf build'],
            ]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $response = agent(tools: [new ApprovalShell])->prompt('Clean the build', provider: 'anthropic');

    expect($response->pendingApprovals)->toHaveCount(1)
        ->and($response->pendingApprovals[0]->reason)->toBe('Deletes or overwrites project files');
});
