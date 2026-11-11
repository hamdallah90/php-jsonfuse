--TEST--
jsonfuse 1.0: exception classes carry the reason and position getters with the declared signatures; instances made with new return the documented empty values
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\JsonFuseException;
use JsonFuse\PlanException;
use JsonFuse\RecordException;
use JsonFuse\StreamException;

$position = ['getInputLine' => 'int', 'getRecordNumber' => 'int', 'getByteOffset' => 'int', 'getProgress' => 'JsonFuse\Stats'];
$shape = [
    JsonFuseException::class => ['RuntimeException', ['getReason' => 'string', 'getField' => '?string']],
    PlanException::class => [JsonFuseException::class, ['getReason' => 'string', 'getField' => '?string', 'getOption' => '?string']],
    RecordException::class => [JsonFuseException::class, ['getReason' => 'string', 'getField' => '?string'] + $position],
    StreamException::class => [JsonFuseException::class, ['getReason' => 'string', 'getField' => '?string'] + $position],
];

echo "-- class shape\n";
foreach ($shape as $class => [$parent, $methods]) {
    $rc = new ReflectionClass($class);
    echo $class, ' extends ', $rc->getParentClass()->getName(), $rc->getParentClass()->getName() === $parent ? '' : ' WRONG', "\n";
    foreach ($methods as $name => $type) {
        if (!$rc->hasMethod($name)) {
            echo "  $name missing\n";
            continue;
        }
        $m = $rc->getMethod($name);
        $got = (string)$m->getReturnType();
        echo "  $name(): $got", $got === $type ? '' : " WRONG want $type",
            $m->isPublic() ? '' : ' NOT PUBLIC', $m->isStatic() ? ' STATIC' : '',
            $m->getNumberOfParameters() === 0 ? '' : ' TAKES PARAMETERS', "\n";
    }
}
var_dump(is_subclass_of(StreamException::class, RecordException::class));
var_dump(is_subclass_of(RecordException::class, StreamException::class));

echo "-- instances made with new\n";
foreach ([new JsonFuseException(), new PlanException('m', 3), new RecordException('m', 4), new StreamException()] as $e) {
    echo jf10_exc($e), ' message=', var_export($e->getMessage(), true), ' code=', $e->getCode(),
        ' reason=', var_export($e->getReason(), true), "\n";
    if ($e instanceof RecordException || $e instanceof StreamException) {
        var_dump($e->getProgress() instanceof JsonFuse\Stats);
    }
}
?>
--EXPECT--
-- class shape
JsonFuse\JsonFuseException extends RuntimeException
  getReason(): string
  getField(): ?string
JsonFuse\PlanException extends JsonFuse\JsonFuseException
  getReason(): string
  getField(): ?string
  getOption(): ?string
JsonFuse\RecordException extends JsonFuse\JsonFuseException
  getReason(): string
  getField(): ?string
  getInputLine(): int
  getRecordNumber(): int
  getByteOffset(): int
  getProgress(): JsonFuse\Stats
JsonFuse\StreamException extends JsonFuse\JsonFuseException
  getReason(): string
  getField(): ?string
  getInputLine(): int
  getRecordNumber(): int
  getByteOffset(): int
  getProgress(): JsonFuse\Stats
bool(false)
bool(false)
-- instances made with new
JsonFuseException  field=null message='' code=0 reason=''
PlanException  option=null field=null message='m' code=3 reason=''
RecordException  field=null at=0/0/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] message='m' code=4 reason=''
bool(true)
StreamException  field=null at=0/0/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] message='' code=0 reason=''
bool(true)
