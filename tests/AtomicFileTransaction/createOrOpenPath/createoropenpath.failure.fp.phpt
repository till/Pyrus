--TEST--
PEAR2_Pyrus_AtomicFileTransaction::createOrOpenPath(), path can't be opened
--FILE--
<?php
define('MYDIR', __DIR__);
require dirname(__DIR__) . '/setup.empty.php.inc';

$role = new PEAR2_Pyrus_Installer_Role_Php(PEAR2_Pyrus_Config::current());
$atomic = new PEAR2_Pyrus_AtomicFileTransaction($role, __DIR__ . '/testit/src');

$atomic->begin();

mkdir(__DIR__ . '/testit/.journal-src/foo/bar', 0777, true);
try {
    $atomic->createOrOpenPath('foo', null, 'wb');
    die('should have failed');
} catch (PEAR2_Pyrus_AtomicFileTransaction_Exception $e) {
    $test->assertEquals('Unable to open foo for writing in ' . __DIR__ .
                        DIRECTORY_SEPARATOR . 'testit' . DIRECTORY_SEPARATOR .
                        '.journal-src', $e->getMessage(), 'error msg');
}

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