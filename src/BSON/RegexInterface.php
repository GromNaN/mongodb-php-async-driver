<?php

declare(strict_types=1);

namespace MongoDB\BSON;

interface RegexInterface
{
    public function getPattern(): string;

    public function getFlags(): string;

    public function __toString(): string;
}
