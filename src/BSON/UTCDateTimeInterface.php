<?php

declare(strict_types=1);

namespace MongoDB\BSON;

use DateTime;
use DateTimeImmutable;

interface UTCDateTimeInterface
{
    public function toDateTime(): DateTime;

    public function toDateTimeImmutable(): DateTimeImmutable;

    public function __toString(): string;
}
