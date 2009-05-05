--TEST--
PEAR2_Pyrus_AtomicFileTransaction::removePath() failure, strict
--FILE--
<?php
define('MYDIR', __DIR__);
require dirname(__DIR__) . '/setup.empty.php.inc';

$role = new PEAR2_Pyrus_Installer_Role_Php(PEAR2_Pyrus_Config::current());
$atomic = new PEAR2_Pyrus_AtomicFileTransaction($role, __DIR__ . '/testit/src');

$atomic->begin();
mkdir(__DIR__ . '/testit/.journal-src/foo/bar', 0777, true);
$test->assertFileExists(__DIR__ . '/testit/.journal-src/foo', 'before');
try {
    $atomic->removePath('foo');
    die('should have failed');
} catch (PEAR2_Pyrus_AtomicFileTransaction_Exception $e) {
    $test->assertEquals('Cannot remove directory foo in ' . __DIR__ . DIRECTORY_SEPARATOR .
                        'testit' . DIRECTORY_SEPARATOR . '.journal-src', $e->getMessage(), 'error');
}
$test->assertFileExists(__DIR__ . '/testit/.journal-src/foo', 'should still exist');
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