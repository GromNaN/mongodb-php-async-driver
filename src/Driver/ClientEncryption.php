<?php
declare(strict_types=1);

namespace MongoDB\Driver;

use MongoDB\BSON\Binary;
use MongoDB\Driver\Exception\RuntimeException;

final class ClientEncryption
{
    public const string AEAD_AES_256_CBC_HMAC_SHA_512_DETERMINISTIC = 'AEAD_AES_256_CBC_HMAC_SHA_512-Deterministic';
    public const string AEAD_AES_256_CBC_HMAC_SHA_512_RANDOM        = 'AEAD_AES_256_CBC_HMAC_SHA_512-Random';
    public const string ALGORITHM_INDEXED                           = 'Indexed';
    public const string ALGORITHM_UNINDEXED                         = 'Unindexed';
    public const string ALGORITHM_RANGE                             = 'Range';
    public const string QUERY_TYPE_EQUALITY                         = 'equality';
    public const string QUERY_TYPE_RANGE                            = 'range';

    public function __construct(array $options)
    {
        throw new RuntimeException('Client-side encryption is not supported in this driver');
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
