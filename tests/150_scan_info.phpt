--TEST--
jsonfuse 1.0: the module information says whether the validating scan is compiled in; a run with JF_DISABLE_SCAN=1 in the environment expects a build made with -DJF_DISABLE_SCAN
--EXTENSIONS--
jsonfuse
--FILE--
<?php
ob_start();
(new ReflectionExtension('jsonfuse'))->info();
$info = ob_get_clean();
$want = getenv('JF_DISABLE_SCAN') === '1' ? 'disabled' : 'enabled';
var_dump(preg_match("/^validating scan => $want\$/m", $info));
--EXPECT--
int(1)
