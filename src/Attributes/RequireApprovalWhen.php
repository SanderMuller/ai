<?php

namespace Laravel\Ai\Attributes;

use Attribute;
use InvalidArgumentException;
use Laravel\Ai\Enums\Lab;
use ReflectionClass;

#[Attribute(Attribute::TARGET_CLASS)]
final class RequireApprovalWhen
{
    /**
     * Create a new attribute instance.
     *
     * @param  string  $required  Describes the tool calls that need human approval.
     * @param  string|null  $notRequired  Describes the tool calls that may run without approval.
     * @param  float  $threshold  The probability from which a call needs approval; a lower value is stricter.
     * @param  int|null  $timeout  The classification timeout in seconds.
     * @param  Lab|array|string|null  $provider  The classification provider, or the configured default.
     * @param  string|null  $model  The classification model, or the provider's default.
     *
     * @throws InvalidArgumentException if a criterion is blank or the threshold is outside 0 to 1.
     */
    public function __construct(
        public string $required,
        public ?string $notRequired = null,
        public float $threshold = 0.5,
        public ?int $timeout = null,
        public Lab|array|string|null $provider = null,
        public ?string $model = null,
    ) {
        if (blank($required) || ($notRequired !== null && blank($notRequired))) {
            throw new InvalidArgumentException('Approval criteria may not be blank.');
        }

        if ($threshold < 0 || $threshold > 1) {
            throw new InvalidArgumentException('The approval threshold must be between 0 and 1.');
        }
    }

    /**
     * Get the approval criteria declared on the target tool.
     */
    public static function for(?object $target): ?self
    {
        if ($target === null) {
            return null;
        }

        $attributes = (new ReflectionClass($target))->getAttributes(self::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * Get the criteria the tool call is classified against.
     *
     * @return array{true: string, false?: string}
     */
    public function criteria(): array
    {
        return array_filter(['true' => $this->required, 'false' => $this->notRequired]);
    }
}
