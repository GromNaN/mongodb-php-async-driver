<?php

declare(strict_types=1);

namespace MongoDB\Internal\Protocol;

use MongoDB\Internal\BSON\BsonEncoder;

use function implode;
use function pack;
use function strlen;

/**
 * Builds OP_MSG wire-protocol frames from PHP command documents.
 *
 * OP_MSG layout
 * ─────────────
 *   MsgHeader   (16 bytes)
 *     messageLength : int32 LE  – total message size including header
 *     requestID     : int32 LE
 *     responseTo    : int32 LE  – 0 for client requests
 *     opCode        : int32 LE  – 2013 (OP_MSG)
 *   flagBits        : uint32 LE – 0 = normal, bit 0 = checksumPresent, bit 1 = exhaustAllowed
 *   Section kind=0  :
 *     kind          : uint8 (0)
 *     body          : BSON document
 *   [Section kind=1 …]
 *     kind          : uint8 (1)
 *     size          : int32 LE  – byte length of this section including the size field itself
 *     identifier    : cstring
 *     documents     : BSON document * N
 *   [checksum       : uint32 LE – only when flagBits bit 0 is set]
 *
 * @internal
 */
final class OpMsgEncoder
{
    /**
     * Encode a command into a fully-framed OP_MSG byte string.
     *
     * @param array|object $body         The command body (section kind 0).
     * @param array        $docSequences Optional kind-1 sections.
     *                                   Each entry: ['id' => 'documents', 'docs' => [...]]
     * @param int          $flags        OP_MSG flagBits (0 = default, 2 = exhaustAllowed).
     *
     * @return string Fully-framed message bytes including header.
     */
    public static function encode(
        array|object $body,
        array $docSequences = [],
        int $flags = 0,
    ): string {
        [$bytes] = self::encodeWithRequestId($body, $docSequences, $flags);

        return $bytes;
    }

    /**
     * Encode a command and return both the wire bytes and the request ID used.
     *
     * @return array{string, int} [$bytes, $requestId]
     */
    public static function encodeWithRequestId(
        array|object $body,
        array $docSequences = [],
        int $flags = 0,
    ): array {
        $requestId = RequestIdGenerator::next();
        $bytes     = self::buildFrame($requestId, $body, $docSequences, $flags);

        return [$bytes, $requestId];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the complete OP_MSG frame for a given request ID.
     */
    private static function buildFrame(
        int $requestId,
        array|object $body,
        array $docSequences,
        int $flags,
    ): string {
        // --- Section kind 0: body ---
        $kind0 = "\x00" . BsonEncoder::encode($body);

        // --- Sections kind 1: document sequences ---
        $kind1Parts = '';
        foreach ($docSequences as $seq) {
            $identifier = (string) $seq['id'];
            $docs       = (array) $seq['docs'];

            // Encode all documents in this sequence — collect into array to avoid O(n²) copies.
            $docParts = [];
            foreach ($docs as $doc) {
                $docParts[] = BsonEncoder::encode($doc);
            }

            $docsBytes = implode('', $docParts);

            // Section size = 4 (size field) + strlen(identifier) + 1 (null) + strlen(docsBytes)
            $sectionSize = 4 + strlen($identifier) + 1 + strlen($docsBytes);

            $kind1Parts .= "\x01"
                         . pack('V', $sectionSize)
                         . $identifier . "\x00"
                         . $docsBytes;
        }

        // --- flagBits (uint32 LE) ---
        $flagBytes = pack('V', $flags);

        // --- Payload (everything after the header) ---
        $payload = $flagBytes . $kind0 . $kind1Parts;

        // --- Full message length = header (16) + payload ---
        $messageLength = MessageHeader::HEADER_SIZE + strlen($payload);

        // --- Header ---
        $header = new MessageHeader(
            messageLength: $messageLength,
            requestId:     $requestId,
            responseTo:    0,
            opCode:        MessageHeader::OP_MSG,
        );

        return $header->toBytes() . $payload;
    }
}
