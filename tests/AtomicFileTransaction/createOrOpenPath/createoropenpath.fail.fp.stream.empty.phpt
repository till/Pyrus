--TEST--
PEAR2_Pyrus_AtomicFileTransaction::createOrOpenPath(), failure, contents is empty stream
--FILE--
<?php
define('MYDIR', __DIR__);
require dirname(__DIR__) . '/setup.empty.php.inc';

$role = new PEAR2_Pyrus_Installer_Role_Php(PEAR2_Pyrus_Config::current());
$atomic = new PEAR2_Pyrus_AtomicFileTransaction($role, __DIR__ . '/testit/src');

$atomic->begin();

file_put_contents(__DIR__ . '/testit/blah', 'blah');
$fp = fopen(__DIR__ . '/testit/blah', 'rb');
fread($fp, 55);
try {
    $atomic->createOrOpenPath('foo', $fp, 0664);
    fclose($fp);
    die('should have failed');
} catch (PEAR2_Pyrus_AtomicFileTransaction_Exception $e) {
    $test->assertEquals('Unable to copy to foo in ' . __DIR__ . DIRECTORY_SEPARATOR .
                        'testit' . DIRECTORY_SEPARATOR . '.journal-src', $e->getMessage(), 'error message');
}
fclose($fp);
$atomic->rollback();
?>
===DONE===
--CLEAN--
<?php
$dir = __DIR__ . '/testit';
include __DIR__ . '/../../clean.php.inc';
?>
--EXPECT--
===DONE===