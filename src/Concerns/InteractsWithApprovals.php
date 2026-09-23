<?php

namespace Laravel\Ai\Concerns;

use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Attributes\RequireApprovalWhen;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use UnexpectedValueException;

trait InteractsWithApprovals
{
    protected Approval|bool|null $approvalRequirement = null;

    protected ?RequireApprovalWhen $approvalCriteria = null;

    /**
     * Indicate that the tool requires approval before execution.
     */
    public function requireApproval(?string $reason = null): static
    {
        $this->approvalRequirement = Approval::required($reason);

        return $this;
    }

    /**
     * Indicate that the tool may execute without approval.
     */
    public function withoutApproval(): static
    {
        $this->approvalRequirement = false;

        return $this;
    }

    /**
     * Indicate that the tool requires approval when a classifier matches the given criteria.
     */
    public function requireApprovalWhen(
        string $required,
        ?string $notRequired = null,
        float $threshold = 0.5,
        ?int $timeout = null,
        Lab|array|string|null $provider = null,
        ?string $model = null,
    ): static {
        $this->approvalRequirement = null;
        $this->approvalCriteria = new RequireApprovalWhen($required, $notRequired, $threshold, $timeout, $provider, $model);

        return $this;
    }

    /**
     * Determine whether the tool should request approval for the given request.
     */
    public function shouldRequestApproval(Request $request): ?Approval
    {
        $result = $this->approvalRequirement ?? $this->needsApproval($request);

        return match (true) {
            $result === false => null,
            $result === true => Approval::required(),
            default => $result,
        };
    }

    /**
     * Determine whether the tool needs approval for the given request.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        $criteria = $this->approvalCriteria ?? RequireApprovalWhen::for($this);

        return $criteria === null ? true : $this->classifyApproval($criteria, $request);
    }

    /**
     * Classify whether the tool call matches the criteria that require approval.
     *
     * A failed classification requires approval, so an unavailable provider never lets a call through.
     */
    protected function classifyApproval(RequireApprovalWhen $criteria, Request $request): Approval|false
    {
        return rescue(function () use ($criteria, $request) {
            $classification = Classification::of([
                'tool' => ToolNameResolver::resolve($this),
                'description' => (string) $this->description(),
                'arguments' => $request->all(),
            ])->question('approval', new Boolean(
                'Does this tool call need human approval before it runs?',
                $criteria->criteria(),
            ));

            if ($criteria->timeout !== null) {
                $classification->timeout($criteria->timeout);
            }

            $answer = $classification->classify($criteria->provider, $criteria->model)->answer('approval');

            if (! $answer instanceof BooleanAnswer) {
                throw new UnexpectedValueException('The approval classification did not return a boolean answer.');
            }

            return $answer->isTrue($criteria->threshold) ? Approval::required($criteria->required) : false;
        }, fn () => Approval::required('Approval could not be decided automatically.'));
    }
}
