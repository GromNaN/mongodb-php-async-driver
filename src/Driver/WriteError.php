<?php
declare(strict_types=1);

namespace MongoDB\Driver;

final class WriteError
{
    public function __construct(
        private readonly int $code,
        private readonly int $index,
        private readonly string $message,
        private readonly ?object $info = null,
    ) {
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getInfo(): ?object
    {
        return $this->info;
    }

    public function __debugInfo(): array
    {
        return [
            'message' => $this->message,
            'code'    => $this->code,
            'index'   => $this->index,
            'info'    => $this->info,
        ];
    }
}
