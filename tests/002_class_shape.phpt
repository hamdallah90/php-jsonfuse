--TEST--
jsonfuse: class shape, exception hierarchy, readonly Stats, private constructors, refused clone/serialize
--EXTENSIONS--
jsonfuse
--FILE--
<?php
use JsonFuse\Plan;
use JsonFuse\Runner;
use JsonFuse\Stats;

// final classes
foreach (['Plan', 'Runner', 'Stats'] as $c) {
    $rc = new ReflectionClass("JsonFuse\\$c");
    echo $c, ' final=', var_export($rc->isFinal(), true),
        ' internal=', var_export($rc->isInternal(), true), "\n";
}

// exception hierarchy: plain (non-final) classes
$parents = [
    'JsonFuse\JsonFuseException' => 'RuntimeException',
    'JsonFuse\PlanException' => 'JsonFuse\JsonFuseException',
    'JsonFuse\RecordException' => 'JsonFuse\JsonFuseException',
    'JsonFuse\StreamException' => 'JsonFuse\JsonFuseException',
];
foreach ($parents as $c => $p) {
    $rc = new ReflectionClass($c);
    echo $c, ' extends ', $rc->getParentClass()->getName(),
        ' final=', var_export($rc->isFinal(), true),
        ' ok=', var_export($rc->getParentClass()->getName() === $p, true), "\n";
}
var_dump((new JsonFuse\PlanException('m', 3)) instanceof Throwable);

// Stats: five public readonly int properties, in this order
$rc = new ReflectionClass(Stats::class);
foreach ($rc->getProperties() as $p) {
    $t = $p->getType();
    echo $p->getName(), ' public=', var_export($p->isPublic(), true),
        ' readonly=', var_export($p->isReadOnly(), true),
        ' type=', $t, ' nullable=', var_export($t->allowsNull(), true),
        ' static=', var_export($p->isStatic(), true), "\n";
}

// private constructors
foreach ([Plan::class, Runner::class, Stats::class] as $c) {
    $ctor = (new ReflectionClass($c))->getConstructor();
    echo $c, ' ctor private=', var_export($ctor->isPrivate(), true), "\n";
    $r = jf_t(fn() => new $c());
    echo '  new: ', $r, "\n";
}

// Plan::compile shape
$m = new ReflectionMethod(Plan::class, 'compile');
echo 'compile static=', var_export($m->isStatic(), true),
    ' public=', var_export($m->isPublic(), true),
    ' params=', $m->getNumberOfParameters(),
    ' required=', $m->getNumberOfRequiredParameters(),
    ' return=', $m->getReturnType(), "\n";
$p = $m->getParameters()[0];
echo '  $', $p->getName(), ': ', $p->getType(), "\n";

// Runner::process shape
$m = new ReflectionMethod(Runner::class, 'process');
echo 'process static=', var_export($m->isStatic(), true),
    ' public=', var_export($m->isPublic(), true),
    ' params=', $m->getNumberOfParameters(),
    ' required=', $m->getNumberOfRequiredParameters(),
    ' return=', $m->getReturnType(), "\n";
foreach ($m->getParameters() as $p) {
    echo '  $', $p->getName(), ' type=', $p->hasType() ? $p->getType() : '(none)', "\n";
}

// Plan: clone / serialize / unserialize / newInstanceWithoutConstructor refused
$plan = Plan::compile(['fields' => ['id' => 'int64']]);
echo 'clone: ', jf_t(fn() => clone $plan), "\n";
echo 'serialize: ', jf_t(fn() => serialize($plan)), "\n";
echo 'unserialize: ', jf_t(fn() => unserialize('O:13:"JsonFuse\Plan":0:{}')), "\n";
echo 'newInstanceWithoutConstructor: ',
    jf_t(fn() => (new ReflectionClass(Plan::class))->newInstanceWithoutConstructor()), "\n";

// Runner is not serializable either
echo 'unserialize Runner: ', jf_t(fn() => unserialize('O:15:"JsonFuse\Runner":0:{}')), "\n";

// @strict-properties: no dynamic properties on Plan
echo 'dynamic prop on Plan: ', jf_t(function () use ($plan) { $plan->extra = 1; }), "\n";

function jf_t(callable $f): string
{
    try {
        $f();
        return 'ALLOWED';
    } catch (Throwable $e) {
        return 'refused';
    }
}
?>
--EXPECT--
Plan final=true internal=true
Runner final=true internal=true
Stats final=true internal=true
JsonFuse\JsonFuseException extends RuntimeException final=false ok=true
JsonFuse\PlanException extends JsonFuse\JsonFuseException final=false ok=true
JsonFuse\RecordException extends JsonFuse\JsonFuseException final=false ok=true
JsonFuse\StreamException extends JsonFuse\JsonFuseException final=false ok=true
bool(true)
recordsRead public=true readonly=true type=int nullable=false static=false
recordsMatched public=true readonly=true type=int nullable=false static=false
recordsEmitted public=true readonly=true type=int nullable=false static=false
bytesRead public=true readonly=true type=int nullable=false static=false
bytesWritten public=true readonly=true type=int nullable=false static=false
JsonFuse\Plan ctor private=true
  new: refused
JsonFuse\Runner ctor private=true
  new: refused
JsonFuse\Stats ctor private=true
  new: refused
compile static=true public=true params=1 required=1 return=JsonFuse\Plan
  $spec: array
process static=true public=true params=3 required=3 return=JsonFuse\Stats
  $input type=(none)
  $output type=(none)
  $plan type=JsonFuse\Plan
clone: refused
serialize: refused
unserialize: refused
newInstanceWithoutConstructor: refused
unserialize Runner: refused
dynamic prop on Plan: refused
