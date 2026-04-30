<?php

declare(strict_types=1);

namespace MongoDB\Internal\Protocol;

use MongoDB\Driver\Exception\ConnectionException;

use function gzuncompress;
use function pack;
use function sprintf;
use function strlen;
use function substr;
use function unpack;

/**
 * Unwraps an OP_COMPRESSED frame and returns the original OP_MSG bytes.
 *
 * This is the inverse of {@see OpCompressedEncoder}.  After decompression the
 * returned bytes are indistinguishable from a server response that was never
 * compressed, so they can be fed directly into {@see OpMsgDecoder::decode()}.
 *
 * Only zlib (compressor ID 2) is supported; other IDs throw ConnectionException.
 *
 * @internal
 */
final class OpCompressedDecoder
{
    /**
     * Test whether a raw wire-protocol frame is an OP_COMPRESSED message.
     */
    public static function isCompressed(string $bytes): bool
    {
        if (strlen($bytes) < MessageHeader::HEADER_SIZE) {
            return false;
        }

        /** @var array{4: int} $h */
        $h = unpack('V4', substr($bytes, 0, MessageHeader::HEADER_SIZE));

        return $h[4] === MessageHeader::OP_COMPRESSED;
    }

    /**
     * Decompress an OP_COMPRESSED frame and return plain OP_MSG bytes.
     *
     * The reconstructed OP_MSG header retains the original requestId / responseTo
     * values and uses the wrapped original opcode.
     *
     * @throws ConnectionException on decompression failure or unsupported compressor.
     */
    public static function decode(string $bytes): string
    {
        // Minimum size: 16 (MsgHeader) + 4 (originalOpcode) + 4 (uncompressedSize)
        //               + 1 (compressorId) = 25 bytes.
        if (strlen($bytes) < MessageHeader::HEADER_SIZE + 9) {
            throw new ConnectionException(
                sprintf(
                    'OP_COMPRESSED frame is too short: expected >= %d bytes, got %d',
                    MessageHeader::HEADER_SIZE + 9,
                    strlen($bytes),
                ),
            );
        }

        $offset = MessageHeader::HEADER_SIZE;

        $originalOpcode   = unpack('V', substr($bytes, $offset, 4))[1];
        $offset          += 4;
        $uncompressedSize = unpack('V', substr($bytes, $offset, 4))[1];
        $offset          += 4;
        $compressorId     = unpack('C', $bytes[$offset])[1];
        $offset          += 1;
        $compressedData   = substr($bytes, $offset);

        if ($compressorId !== MessageHeader::COMPRESSOR_ZLIB) {
            throw new ConnectionException(
                sprintf('Unsupported compressor ID %d in OP_COMPRESSED response', $compressorId),
            );
        }

        $decompressed = gzuncompress($compressedData, $uncompressedSize);
        if ($decompressed === false) {
            throw new ConnectionException('Failed to decompress OP_COMPRESSED response (zlib error)');
        }

        // Rebuild a valid OP_MSG frame: preserve requestId + responseTo from the
        // OP_COMPRESSED header and substitute the wrapped original opcode.
        $totalLength = MessageHeader::HEADER_SIZE + strlen($decompressed);
        /** @var array{1: int, 2: int} $rr */
        $rr     = unpack('V1/V2', substr($bytes, 4, 8));
        $header = pack('V', $totalLength)
            . pack('V', $rr[1])           // requestId
            . pack('V', $rr[2])           // responseTo
            . pack('V', $originalOpcode);

        return $header . $decompressed;
    }
}
