<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use MongoDB\Driver\Exception\RuntimeException;

final class ClientEncryption
{
    private function __construct()
    {
    }

    public function addKeyAltName(Binary $keyId, string $keyAltName): ?object
    {
        throw new RuntimeException('Client-side encryption is not supported in this driver');
    }

    public function createDataKey(string $kmsProvider, ?array $options = null): Binary
    {
        throw new RuntimeException('Client-side encryption is not supported in this driver');
    }

    public function decrypt(Binary $value): mixed
    {
        throw new RuntimeException('Client-side encryption is not supported in this driver');
    }

    public function deleteKey(Binary $keyId): object
    {
        throw new RuntimeException('Client-side encryption is not supported in this driver');
    }

    public function encrypt(mixed $value, ?array $options = null): Binary
    {
        throw new RuntimeException('Client-side encryption is not supported in this driver');
    }

    public function encryptExpression(array|object $expr, ?array $options = null): object
    {
        throw new RuntimeException('Client-side encryption is not supported in this driver');
    }

    public function getKey(Binary $keyId): ?object
    {
        throw new RuntimeException('Client-side encryption is not supported in this driver');
    }

    public function getKeyByAltName(string $keyAltName): ?object
    {
        throw new RuntimeException('Client-side encryption is not supported in this driver');
    }

    public function getKeys(): Cursor
    {
        throw new RuntimeException('Client-side encryption is not supported in this driver');
    }

    public function removeKeyAltName(Binary $keyId, string $keyAltName): ?object
    {
        throw new RuntimeException('Client-side encryption is not supported in this driver');
    }

    public function rewrapManyDataKey(array|object $filter, ?array $options = null): object
    {
        throw new RuntimeException('Client-side encryption is not supported in this driver');
    }
}
