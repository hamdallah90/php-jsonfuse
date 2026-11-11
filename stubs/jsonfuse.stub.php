<?php

/** @generate-class-entries */

namespace JsonFuse;

/**
 * @strict-properties
 * @not-serializable
 */
final class Plan
{
    private function __construct() {}

    public static function compile(array $spec): Plan {}
}

/**
 * @strict-properties
 */
final class Stats
{
    public readonly int $recordsRead;
    public readonly int $recordsMatched;
    public readonly int $recordsEmitted;
    public readonly int $bytesRead;
    public readonly int $bytesWritten;

    private function __construct() {}
}

/**
 * @strict-properties
 * @not-serializable
 */
final class Runner
{
    private function __construct() {}

    /**
     * @param resource $input
     * @param resource $output
     */
    public static function process($input, $output, Plan $plan): Stats {}
}

/* Set by the extension when it throws; an instance made with new keeps the defaults. */
class JsonFuseException extends \RuntimeException
{
    private string $reason = '';
    private ?string $field = null;

    public function getReason(): string {}

    public function getField(): ?string {}
}

class PlanException extends JsonFuseException
{
    private ?string $option = null;

    public function getOption(): ?string {}
}

class RecordException extends JsonFuseException
{
    private int $inputLine = 0;
    private int $recordNumber = 0;
    private int $byteOffset = 0;
    private ?Stats $progress = null;

    public function getInputLine(): int {}

    public function getRecordNumber(): int {}

    public function getByteOffset(): int {}

    public function getProgress(): Stats {}
}

class StreamException extends JsonFuseException
{
    private int $inputLine = 0;
    private int $recordNumber = 0;
    private int $byteOffset = 0;
    private ?Stats $progress = null;

    /** @implementation-alias JsonFuse\RecordException::getInputLine */
    public function getInputLine(): int {}

    /** @implementation-alias JsonFuse\RecordException::getRecordNumber */
    public function getRecordNumber(): int {}

    /** @implementation-alias JsonFuse\RecordException::getByteOffset */
    public function getByteOffset(): int {}

    /** @implementation-alias JsonFuse\RecordException::getProgress */
    public function getProgress(): Stats {}
}
