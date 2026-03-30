<?php

declare(strict_types=1);

namespace MongoDB\Tests\Protocol;

use MongoDB\Internal\Protocol\MessageHeader;
use MongoDB\Internal\Protocol\OpMsgDecoder;
use MongoDB\Internal\Protocol\OpMsgEncoder;
use PHPUnit\Framework\TestCase;

use function ord;
use function strlen;
use function substr;
use function unpack;

class OpMsgTest extends TestCase
{
    public function testEncodeDecodeRoundTrip(): void
    {
        $body  = ['ping' => 1, '$db' => 'admin'];
        $bytes = OpMsgEncoder::encode($body);

        $result = OpMsgDecoder::decode($bytes, ['root' => 'array']);

        $this->assertArrayHasKey('body', $result);
        $decodedBody = (array) $result['body'];
        $this->assertSame(1, $decodedBody['ping']);
        $this->assertSame('admin', $decodedBody['$db']);
    }

    public function testMessageHeaderSize(): void
    {
        $body   = ['ping' => 1, '$db' => 'admin'];
        $bytes  = OpMsgEncoder::encode($body);

        // The first 16 bytes are the header
        $headerBytes = substr($bytes, 0, MessageHeader::HEADER_SIZE);
        $this->assertSame(MessageHeader::HEADER_SIZE, strlen($headerBytes));
    }

    public function testOpCode(): void
    {
        $body   = ['ping' => 1, '$db' => 'admin'];
        $bytes  = OpMsgEncoder::encode($body);

        $header = MessageHeader::fromBytes(substr($bytes, 0, MessageHeader::HEADER_SIZE));
        $this->assertSame(MessageHeader::OP_MSG, $header->opCode);
        $this->assertSame(2013, $header->opCode);
    }

    public function testRequestIdIsSet(): void
    {
        [$bytes, $requestId] = OpMsgEncoder::encodeWithRequestId(['ping' => 1, '$db' => 'admin']);

        $header = MessageHeader::fromBytes(substr($bytes, 0, MessageHeader::HEADER_SIZE));

        $this->assertGreaterThan(0, $header->requestId);
        $this->assertSame($requestId, $header->requestId);
    }

    public function testKind0Section(): void
    {
        $body  = ['hello' => 1, '$db' => 'admin'];
        $bytes = OpMsgEncoder::encode($body);

        // Kind byte is at offset 20 (16 header + 4 flagBits)
        $kindByte = ord($bytes[20]);
        $this->assertSame(0, $kindByte, 'First section must be kind=0 (body).');

        $result      = OpMsgDecoder::decode($bytes, ['root' => 'array']);
        $decodedBody = (array) $result['body'];
        $this->assertArrayHasKey('hello', $decodedBody);
    }

    public function testChecksumAbsent(): void
    {
        $body  = ['ping' => 1, '$db' => 'admin'];
        $bytes = OpMsgEncoder::encode($body);

        // flagBits are at bytes 16–19; bit 0 = checksum present
        /** @var array{1: int} $flagUnpacked */
        $flagUnpacked = unpack('V', substr($bytes, 16, 4));
        $flagBits     = $flagUnpacked[1];

        $this->assertSame(0, $flagBits & 0x01, 'Checksum-present bit must not be set by default.');
    }
}
