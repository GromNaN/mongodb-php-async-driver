<?php
declare(strict_types=1);

namespace MongoDB\Driver;

final class WriteConcernError
{
    private ?object $details;

    private function __construct(
        private readonly int $code,
        private readonly string $message,
        private readonly ?object $info = null,
        ?array $details = null,
    ) {
        $this->details = $details !== null ? (object) $details : null;
    }

    /** @internal */
    public static function create(int $code, string $message, ?object $info = null, ?array $details = null): self
    {
        return new self($code, $message, $info, $details);
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getInfo(): ?object
    {
        return $this->info;
    }

    public function getDetails(): ?object
    {
        return $this->details;
    }

    public function __debugInfo(): array
    {
        return [
            'message' => $this->message,
            'code'    => $this->code,
            'info'    => $this->info,
        ];
    }
}
