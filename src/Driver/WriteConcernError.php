<?php
declare(strict_types=1);

namespace MongoDB\Driver;

final class WriteConcernError
{
    private ?object $details;

    public function __construct(
        private readonly int $code,
        private readonly string $message,
        private readonly ?object $info = null,
        ?array $details = null,
    ) {
        $this->details = $details !== null ? (object) $details : null;
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
}
