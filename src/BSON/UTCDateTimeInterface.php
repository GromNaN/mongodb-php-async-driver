<?php

declare(strict_types=1);

namespace MongoDB\BSON;

interface UTCDateTimeInterface
{
    public function toDateTime(): \DateTimeImmutable;

    public function __toString(): string;
}
