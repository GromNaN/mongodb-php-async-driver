<?php

declare(strict_types=1);

namespace MongoDB\Internal\Protocol;

use MongoDB\Driver\Exception\CommandException;
use MongoDB\Internal\BSON\BsonDecoder;
use RuntimeException;

use function is_array;
use function ord;
use function sprintf;
use function strlen;
use function strpos;
use function substr;
use function unpack;

/**
 * Parses OP_MSG response frames received from a MongoDB server.
 *
 * @internal
 */
final class OpMsgDecoder
{
    /**
     * Decode a complete OP_MSG byte string into its constituent parts.
     *
     * @param string $bytes   Complete message bytes (header + body).
     * @param array  $typeMap BsonDecoder type map to apply when decoding documents.
     *
     * @return array{header: MessageHeader, body: array|object, sequences: array}
     *
     * @throws RuntimeException on malformed data.
     */
    public static function decode(string $bytes, array $typeMap = []): array
    {
        $totalLen = strlen($bytes);

        if ($totalLen < MessageHeader::HEADER_SIZE + 5) {
            // 5 = 4 (flagBits) + 1 (minimum section kind byte)
            throw new RuntimeException(
                sprintf('OP_MSG response too short: %d bytes', $totalLen),
            );
        }

        // 1. Parse the 16-byte header.
        $header = MessageHeader::fromBytes(substr($bytes, 0, MessageHeader::HEADER_SIZE));

        if ($header->opCode !== MessageHeader::OP_MSG) {
            throw new RuntimeException(
                sprintf(
                    'Expected OP_MSG (opCode %d), got opCode %d',
                    MessageHeader::OP_MSG,
                    $header->opCode,
                ),
            );
        }

        // 2. Read flagBits (uint32 LE) at offset 16.
        /** @var array{1: int} $flagUnpacked */
        $flagUnpacked = unpack('V', substr($bytes, MessageHeader::HEADER_SIZE, 4));
        $flagBits     = $flagUnpacked[1];

        // 3. Determine payload end (exclude checksum if present).
        $checksumPresent = (bool) ($flagBits & 0x01);
        $payloadEnd      = $checksumPresent ? $totalLen - 4 : $totalLen;

        // 4. Iterate over sections starting at offset 20.
        $offset    = MessageHeader::HEADER_SIZE + 4; // skip header + flagBits
        $body      = null;
        $sequences = [];

        while ($offset < $payloadEnd) {
            $kind    = ord($bytes[$offset]);
            $offset += 1;

            if ($kind === 0) {
                // Kind 0: body BSON document.
                $body    = BsonDecoder::decode(substr($bytes, $offset), $typeMap);
                $docLen  = self::readDocumentLength($bytes, $offset);
                $offset += $docLen;
            } elseif ($kind === 1) {
                // Kind 1: document sequence.
                // Read int32 size (includes size field itself).
                /** @var array{1: int} $sizeUnpacked */
                $sizeUnpacked  = unpack('V', substr($bytes, $offset, 4));
                $sectionSize   = $sizeUnpacked[1];
                $sectionEnd    = $offset + $sectionSize;
                $offset       += 4; // advance past size field

                // Read identifier (cstring).
                $nullPos = strpos($bytes, "\x00", $offset);
                if ($nullPos === false) {
                    throw new RuntimeException('Unterminated identifier cstring in OP_MSG kind-1 section');
                }

                $identifier = substr($bytes, $offset, $nullPos - $offset);
                $offset     = $nullPos + 1;

                // Read BSON documents until end of section.
                $seqDocs = [];
                while ($offset < $sectionEnd) {
                    $seqDocs[] = BsonDecoder::decode(substr($bytes, $offset), $typeMap);
                    $docLen    = self::readDocumentLength($bytes, $offset);
                    $offset   += $docLen;
                }

                $sequences[$identifier] = $seqDocs;
            } else {
                throw new RuntimeException(
                    sprintf('Unknown OP_MSG section kind: %d at offset %d', $kind, $offset - 1),
                );
            }
        }

        if ($body === null) {
            throw new RuntimeException('OP_MSG response contained no kind-0 body section');
        }

        return [
            'header'    => $header,
            'body'      => $body,
            'sequences' => $sequences,
        ];
    }

    /**
     * Decode an OP_MSG response and check for a server-side command error.
     *
     * @return array|object The decoded body document.
     *
     * @throws CommandException if the response has ok != 1.
     */
    public static function decodeAndCheck(string $bytes, array $typeMap = []): array|object
    {
        $result = self::decode($bytes, $typeMap);
        $body   = $result['body'];

        // Normalise: support both array and object bodies.
        $ok = is_array($body) ? ($body['ok'] ?? null) : ($body->ok ?? null);

        if ((int) $ok !== 1) {
            $errmsg = is_array($body)
                ? ($body['errmsg'] ?? 'Unknown error')
                : ($body->errmsg ?? 'Unknown error');
            $code   = is_array($body)
                ? (int) ($body['code'] ?? 0)
                : (int) ($body->code ?? 0);

            throw new CommandException((string) $errmsg, $code);
        }

        return $body;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Read the BSON document total-length int32 at $offset without advancing the offset.
     */
    private static function readDocumentLength(string $bytes, int $offset): int
    {
        /** @var array{1: int} $u */
        $u = unpack('V', substr($bytes, $offset, 4));

        return $u[1];
    }
}
