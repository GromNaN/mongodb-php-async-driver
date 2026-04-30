<?php

declare(strict_types=1);

namespace MongoDB\Internal\Protocol;

use function gzcompress;
use function pack;
use function strlen;
use function substr;
use function unpack;

/**
 * Wraps an OP_MSG frame in an OP_COMPRESSED envelope.
 *
 * Format (compression spec §4.3):
 *
 *   MsgHeader (16 bytes)           opCode = 2012 (OP_COMPRESSED)
 *   int32  originalOpcode          The wrapped opcode (2013 = OP_MSG)
 *   int32  uncompressedSize        Payload size excluding the MsgHeader
 *   uint8  compressorId            Algorithm (2 = zlib)
 *   bytes  compressedData          Compressed payload
 *
 * @internal
 */
final class OpCompressedEncoder
{
    /**
     * Wrap a single OP_MSG frame in an OP_COMPRESSED envelope using zlib.
     *
     * The requestId and responseTo from the original header are preserved so
     * that the server can correlate responses.
     *
     * @param string $opMsgBytes       Complete OP_MSG frame (header + payload).
     * @param int    $compressionLevel zlib compression level (0–9 or -1 for default).
     *
     * @return string OP_COMPRESSED frame ready for the wire, or the original
     *                OP_MSG frame unchanged when compression fails.
     */
    public static function encode(string $opMsgBytes, int $compressionLevel = -1): string
    {
        // Payload = everything after the 16-byte MessageHeader.
        $payload          = substr($opMsgBytes, MessageHeader::HEADER_SIZE);
        $uncompressedSize = strlen($payload);

        $compressed = gzcompress($payload, $compressionLevel);
        if ($compressed === false) {
            // Fall back to sending uncompressed on failure.
            return $opMsgBytes;
        }

        // Preserve requestId and responseTo from the original header.
        /** @var array{1: int, 2: int, 3: int} $orig */
        $orig = unpack('V1/V2/V3', substr($opMsgBytes, 4, 12));

        $body = pack('V', MessageHeader::OP_MSG)       // originalOpcode
            . pack('V', $uncompressedSize)              // uncompressedSize
            . pack('C', MessageHeader::COMPRESSOR_ZLIB) // compressorId
            . $compressed;

        $totalLength = MessageHeader::HEADER_SIZE + strlen($body);
        $header      = pack('V', $totalLength)
            . pack('V', $orig[1])   // requestId
            . pack('V', $orig[2])   // responseTo
            . pack('V', MessageHeader::OP_COMPRESSED);

        return $header . $body;
    }
}
