<?php

declare(strict_types=1);

namespace MongoDB\BSON;

interface JavascriptInterface
{
    public function getCode(): string;

    public function getScope(): ?object;

    public function __toString(): string;
}
