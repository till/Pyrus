<?php
/**
 * This script handles the command line interface commands to Pyrus
 *
 * PHP version 5
 *
 * @category  PEAR2
 * @package   PEAR2_Pyrus
 * @author    Greg Beaver <cellog@php.net>
 * @copyright 2008 The PEAR Group
 * @license   http://www.opensource.org/licenses/bsd-license.php New BSD License
 * @version   SVN: $Id$
 * @link      http://svn.pear.php.net/wsvn/PEARSVN/Pyrus/
 */

/**
 * This script handles the command line interface commands to Pyrus
 *
 * Each command is a separate method, and will be called with the arguments
 * entered by the end user.
 *
 * @category  PEAR2
 * @package   PEAR2_Pyrus
 * @author    Greg Beaver <cellog@php.net>
 * @copyright 2008 The PEAR Group
 * @license   http://www.opensource.org/licenses/bsd-license.php New BSD License
 * @link      http://svn.pear.php.net/wsvn/PEARSVN/Pyrus/
 */
class PEAR2_Pyrus_ScriptFrontend_Commands implements PEAR2_Pyrus_ILog
{
    public $commands = array();
    // for unit-testing ease
    public static $configclass = 'PEAR2_Pyrus_Config';
    public static $downloadClass = 'PEAR2_HTTP_Request';
    protected $term = array(
        'bold'   => '',
        'normal' => '',
    );
    protected static $commandParser;

    function __construct()
    {
        PEAR2_Pyrus_Log::attach($this);
        if (!isset(static::$commandParser)) {
            $schemapath = PEAR2_Pyrus::getDataPath() . '/customcommand-2.0.xsd';
            $defaultcommands = PEAR2_Pyrus::getDataPath() . '/built-in-commands.xml';
            if (!file_exists($schemapath)) {
                $schemapath = realpath(__DIR__ . '/../../../data/customcommand-2.0.xsd');
                $defaultcommands = realpath(__DIR__ . '/../../../data/built-in-commands.xml');
            }
            $parser = new PEAR2_Pyrus_XMLParser;
            $commands = $parser->parse($defaultcommands, $schemapath);
            $commands = $commands['commands']['command'];
            if ('@PACKAGE_VERSION@' == '@'.'PACKAGE_VERSION@') {
                $version = '2.0.0a1'; // running from svn
            } else {
                $version = '@PACKAGE_VERSION@';
            }
            static::$commandParser = new PEAR2_Pyrus_ScriptFrontend(array(
                    'version' => $version,
                    'description' => 'Pyrus, the installer for PEAR2',
                    'name' => 'php ' . basename($_SERVER['argv'][0])
                )
            );
            // set up our custom renderer for help options
            static::$commandParser->accept(new PEAR2_Pyrus_ScriptFrontend_Renderer(static::$commandParser));
            // set up command-less options and argument
            static::$commandParser->addOption('verbose', array(
                'short_name'  => '-v',
                'long_name'   => '--verbose',
                'action'      => 'Counter',
                'description' => 'increase verbosity'
            ));
            PEAR2_Pyrus_PluginRegistry::registerFrontend($this);
            PEAR2_Pyrus_PluginRegistry::addCommand($commands);
        }
        $term = getenv('TERM');
        if (function_exists('posix_isatty') && !posix_isatty(1)) {
            // output is being redirected to a file or through a pipe
        } elseif ($term) {
            if (preg_match('/^(xterm|vt220|linux)/', $term)) {
                $this->term['bold']   = sprintf("%c%c%c%c", 27, 91, 49, 109);
                $this->term['normal'] = sprintf("%c%c%c", 27, 91, 109);
            } elseif (preg_match('/^vt100/', $term)) {
                $this->term['bold']   = sprintf("%c%c%c%c%c%c", 27, 91, 49, 109, 0, 0);
                $this->term['normal'] = sprintf("%c%c%c%c%c", 27, 91, 109, 0, 0);
            }
        }
    }

    function mapCommand($commandinfo)
    {
        $command = static::$commandParser->addCommand($commandinfo['name'], array(
            'description' => $commandinfo['summary']
        ));
        if (isset($commandinfo['options']['option'])) {
            $options = $commandinfo['options']['option'];
            if (!isset($options[0])) {
                $options = array($options);
            }
            foreach ($options as $option) {
                switch (key($option['type'])) {
                    case 'bool' :
                        $action = 'StoreTrue';
                        break;
                    case 'string' :
                        $action = 'StoreString';
                        break;
                    case 'int' :
                        $action = 'StoreInt';
                        break;
                    case 'float' :
                        $action = 'StoreFloat';
                        break;
                    case 'counter' :
                        $action = 'Counter';
                        break;
                    case 'callback' :
                        $func = $option['type']['callback'];
                        $class = $commandinfo['class'];
                        $callback = function ($value, $option, $result, $parser) use ($func, $class) {
                            return $class::$func($value);
                        };
                        $action = 'Callback';
                        break;
                    case 'set' :
                        $action = 'StoreString';
                        $choice = $option['set']['value'];
                        settype($choice, 'array');
                        break;
                }
                $info = array(
                    'short_name' => '-' . $option['shortopt'],
                    'long_name' => '--' . $option['name'],
                    'description' => $option['doc'],
                    'action' => $action,
                );
                if ($action == 'Callback') {
                    $info['callback'] = $callback;
                }
                if (isset($option['default'])) {
                    $info['default'] = $option['default'];
                }
                if (isset($choice)) {
                    $info['choices'] = $choice;
                    $choice = null;
                }
                $command->addOption($option['name'], $info);
            }
        }
        if (isset($commandinfo['arguments']['argument'])) {
            $args = $commandinfo['arguments']['argument'];
            if (!isset($args[0])) {
                $args = array($args);
            }
            foreach ($args as $arg) {
                $command->addArgument($arg['name'], array(
                    'description' => $arg['doc'],
                    'multiple' => (bool) $arg['multiple'],
                    'optional' => (bool) $arg['optional'],
                ));
            }
        }
    }

    function _bold($text)
    {
        if (empty($this->term['bold'])) {
            return strtoupper($text);
        }

        return $this->term['bold'] . $text . $this->term['normal'];
    }

    /**
     * This method acts as a controller which dispatches the request to the
     * correct command/method.
     *
     * <code>
     * $cli = PEAR2_Pyrus_ScriptFrontend_Commands();
     * $cli->run($args = array (0 => 'install',
     *                          1 => 'PEAR2/Pyrus_Developer/package.xml'));
     * </code>
     *
     * The above code will dispatch to the install command
     *
     * @param array $args An array of command line arguments.
     *
     * @return void
     */
    function run($args)
    {
        try {
            $this->_findPEAR($args);
            // scan for custom commands/roles/tasks
            PEAR2_Pyrus_Config::current()->pluginregistry->scan();
            $result = static::$commandParser->parse(count($args) + 1, array_merge(array('cruft'), $args));
            if ($info = PEAR2_Pyrus_PluginRegistry::getCommandInfo($result->command_name)) {
                if ($this instanceof $info['class']) {
                    $this->{$info['function']}($result->command->args, $result->command->options);
                } else {
                    $class = new $info['class'];
                    $class->{$info['function']}($result->command->args, $result->command->options);
                }
            } else {
                $this->help(array('command' => isset($args[0]) ? $args[0] : null));
            }
        } catch (PEAR2_Console_CommandLine_Exception $e) {
            static::$commandParser->displayError($e->getMessage());
        } catch (Exception $e) {
            echo "Operation failed:\n$e";
            exit -1;
        }
    }

    function _ask($question, array $choices = null, $default = null)
    {
        if (is_array($choices)) {
            foreach ($choices as $i => $choice) {
                if (is_int($i) && ($default === null || ($default !== null && !is_string($default)))) {
                    $is_int = false;
                } else {
                    $is_int = true;
                }
                break;
            }
        }
previous:
        echo $question,"\n";
        if ($choices !== null) {
            echo "Please choose:\n";
            foreach ($choices as $i => $choice) {
                if ($is_int) {
                    echo '  ',$choice,"\n";
                } else {
                    echo '  [',$i,'] ',$choice,"\n";
                }
            }
        }
        if ($default !== null) {
            echo '[',$default,']';
        }
        echo ' : ';
        $answer = $this->_readStdin();

        if (!strlen($answer)) {
            if ($default !== null) {
                $answer = $default;
            } else {
                $answer = null;
            }
        } elseif ($choices !== null) {
            if (($is_int && in_array($answer, $choices)) || (!$is_int && array_key_exists($answer, $choices))) {
                return $answer;
            } else {
                echo "Please choose one choice\n";
                goto previous;
            }
        }
        return $answer;
    }

    function _readStdin($amount = 1024)
    {
        return trim(fgets(STDIN, $amount));
    }

    function _findPEAR(&$arr)
    {
        if (isset($arr[0]) && @file_exists($arr[0]) && @is_dir($arr[0])) {
            $maybe = array_shift($arr);
            $maybe = realpath($maybe);
            echo "Using PEAR installation found at $maybe\n";
            $configclass = static::$configclass;
            $config = $configclass::singleton($maybe);
            return;
        }
        $configclass = static::$configclass;
        if (!$configclass::userInitialized()) {
            echo "Pyrus: No user configuration file detected\n";
            if ('yes' === $this->_ask("It appears you have not used Pyrus before, welcome!  Initialize install?", array('yes', 'no'), 'yes')) {
                echo "Great.  We will store your configuration in:\n  ",$configclass::getDefaultUserConfigFile(),"\n";
previous:
                $path = $this->_ask("Where would you like to install packages by default?", null, getcwd());
                echo "You have chosen:\n", $path, "\n";
                if (!realpath($path)) {
                    echo " this path does not yet exist\n";
                    if ('yes' !== $this->_ask("Create it?", array('yes', 'no'), 'yes')) {
                        goto previous;
                    }
                } elseif (!is_dir($path)) {
                    echo $path," exists, and is not a directory\n";
                    goto previous;
                }
                $configclass = static::$configclass;
                $config = $configclass::singleton($path);
                $config->saveConfig();
                echo "Thank you, enjoy using Pyrus\n";
                echo "Documentation is at http://pear.php.net\n";
            } else {
                echo "OK, thank you, finishing execution now\n";
                exit;
            }
        }
        $configclass = static::$configclass;
        $mypath = $configclass::current()->my_pear_path;
        if ($mypath) {
            foreach (explode(PATH_SEPARATOR, $mypath) as $path) {
                echo "Using PEAR installation found at $path\n";
                $configclass = static::$configclass;
                $config = $configclass::singleton($path);
                return;
            }
        }
    }

    /**
     * Display the help dialog and list all commands supported.
     *
     * @param array $args Array of command line arguments
     */
    function help($args)
    {
        if (!isset($args['command']) || $args['command'] === 'help') {
            static::$commandParser->displayUsage();
        } else {
            $info = PEAR2_Pyrus_PluginRegistry::getCommandInfo($args['command']);
            if (!$info) {
                echo "Unknown command: $args[command]\n";
                static::$commandParser->displayUsage();
            } else {
                static::$commandParser->commands[$args['command']]->displayUsage();
                echo "\n", $info['doc'], "\n";
            }
        }
    }

    /**
     * install a local or remote package
     *
     * @param array $args
     */
    function install($args, $options)
    {
        if ($options['plugin']) {
            PEAR2_Pyrus::$options['install-plugins'] = true;
        }
        if ($options['force']) {
            PEAR2_Pyrus::$options['force'] = true;
        }
        if (isset($options['packagingroot']) && $options['packagingroot']) {
            PEAR2_Pyrus::$options['packagingroot'] = $options['packagingroot'];
        }
        PEAR2_Pyrus_Installer::begin();
        try {
            $packages = array();
            foreach ($args['package'] as $arg) {
                PEAR2_Pyrus_Installer::prepare($packages[] = new PEAR2_Pyrus_Package($arg));
            }
            PEAR2_Pyrus_Installer::commit();
            foreach (PEAR2_Pyrus_Installer::getInstalledPackages() as $package) {
                echo 'Installed ' . $package->channel . '/' . $package->name . '-' .
                    $package->version['release'] . "\n";
                if ($package->type === 'extsrc' || $package->type === 'zendextsrc') {
                    echo " ==> To build this PECL package, use the build command\n";
                }
            }
        } catch (Exception $e) {
            echo $e;
            exit -1;
        }
    }

    /**
     * uninstall an installed package
     *
     * @param array $args
     */
    function uninstall($args, $options)
    {
        if ($options['plugin']) {
            PEAR2_Pyrus::$options['install-plugins'] = true;
        }
        PEAR2_Pyrus_Uninstaller::begin();
        try {
            $packages = $non = $failed = array();
            foreach ($args['package'] as $arg) {
                try {
                    if (!isset(PEAR2_Pyrus_Config::current()->registry->package[$arg])) {
                        $non[] = $arg;
                        continue;
                    }
                    $packages[] = PEAR2_Pyrus_Uninstaller::prepare($arg);
                } catch (Exception $e) {
                    $failed[] = $arg;
                }
            }
            PEAR2_Pyrus_Uninstaller::commit();
            foreach ($non as $package) {
                echo "Package $package not installed, cannot uninstall\n";
            }
            foreach ($packages as $package) {
                echo 'Uninstalled ', $package->channel, '/', $package->name, "\n";
            }
            foreach ($failed as $package) {
                echo "Package $package could not be uninstalled\n";
            }
        } catch (Exception $e) {
            echo $e;
            exit -1;
        }
    }

    /**
     * download a remote package
     *
     * @param array $args
     */
    function download($args)
    {
        PEAR2_Pyrus_Config::current()->download_dir = getcwd();
        $packages = array();
        foreach ($args['package'] as $arg) {
            try {
                $packages[] = array(new PEAR2_Pyrus_Package($arg), $arg);
            } catch (Exception $e) {
                echo "failed to init $arg for download (", $e->getMessage(), ")\n";
            }
        }
        foreach ($packages as $package) {
            $arg = $package[1];
            $package = $package[0];
            echo "Downloading ", $arg, '...';
            try {
                if ($package->isRemote()) {
                    $package->download();
                } else {
                    $package->copyTo(getcwd());
                }
                $path = $package->getInternalPackage()->getTarballPath();
                echo "done ($path)\n";
            } catch (Exception $e) {
                echo 'failed! (', $e->getMessage(), ")\n";
            }
        }
    }

    /**
     * Upgrade a package
     *
     * @param array $args
     */
    function upgrade($args, $options)
    {
        PEAR2_Pyrus::$options['upgrade'] = true;
        $this->install($args, $options);
    }

    /**
     * list all the installed packages
     *
     * @param array $args
     */
    function listPackages()
    {
        $reg = PEAR2_Pyrus_Config::current()->registry;
        $creg = PEAR2_Pyrus_Config::current()->channelregistry;
        $cascade = array(array($reg, $creg));
        $p = $reg;
        $c = $creg;
        while ($p = $p->getParent()) {
            $c = $c->getParent();
            $cascade[] = array($p, $c);
        }
        array_reverse($cascade);
        foreach ($cascade as $p) {
            $c = $p[1];
            $p = $p[0];
            echo "Listing installed packages [", $p->getPath(), "]:\n";
            $packages = array();
            foreach ($c as $channel) {
                PEAR2_Pyrus_Config::current()->default_channel = $channel->name;
                foreach ($p->package as $package) {
                    $packages[$channel->name][] = $package->name;
                }
            }
            asort($packages);
            foreach ($packages as $channel => $stuff) {
                echo "[channel $channel]:\n";
                foreach ($stuff as $package) {
                    echo " $package\n";
                }
            }
        }
    }

    /**
     * List all the known channels
     *
     * @param array $args
     */
    function listChannels()
    {
        $creg = PEAR2_Pyrus_Config::current()->channelregistry;
        $cascade = array($creg);
        while ($c = $creg->getParent()) {
            $cascade[] = $c;
            $creg = $c;
        }
        array_reverse($cascade);
        foreach ($cascade as $c) {
            echo "Listing channels [", $c->getPath(), "]:\n";
            foreach ($c as $channel) {
                echo $channel->name . ' (' . $channel->alias . ")\n";
            }
        }
    }

    /**
     * remotely connect to a channel server and grab the channel information,
     * then add it to the current pyrus managed repo
     *
     * @param array $args $args[0] should be the channel name, eg:pear.unl.edu
     */
    function channelDiscover($args)
    {
        // try secure first
        $chan = 'https://' . $args['channel'] . '/channel.xml';
        $dl = self::$downloadClass;
        $http = new $dl($chan);
        try {
            $response = $http->sendRequest();
            if ($response->code != 200) {
                throw new Exception('Download of channel.xml failed');
            }
addchan_success:    
            $chan = new PEAR2_Pyrus_Channel(new PEAR2_Pyrus_ChannelFile($response->body, true));
            PEAR2_Pyrus_Config::current()->channelregistry->add($chan);
            echo "Discovery of channel ", $chan->name, " successful\n";
        } catch (Exception $e) {
            try {
                $chan = 'http://' . $args['channel'] . '/channel.xml';
                $http = new $dl($chan);
                $response = $http->sendRequest();
                if ($response->code != 200) {
                    throw new Exception('Download of channel.xml failed');
                }
                goto addchan_success;
            } catch (Exception $e) {
                // failed, re-throw original error
                echo "Discovery of channel ", $args['channel'], " failed: ", $e->getMessage();
            }
        }
    }

    /**
     * add a channel to the current pyrus managed path using the raw channel.xml
     *
     * @param array $args $args[0] should be the channel.xml filename
     */
    function channelAdd($args)
    {
        echo "Adding channel from channel.xml:\n";
        $chan = new PEAR2_Pyrus_Channel(new PEAR2_Pyrus_ChannelFile($args['channelfile']));
        PEAR2_Pyrus_Config::current()->channelregistry->add($chan);
        echo "Adding channel ", $chan->name, " successful\n";
    }

    function channelDel($args)
    {
        $chan = PEAR2_Pyrus_Config::current()->channelregistry->get($args['channel'], false);
        if (count(PEAR2_Pyrus_Config::current()->registry->listPackages($chan->name))) {
            echo "Cannot remove channel ", $chan->name, " packages are installed\n";
            exit -1;
        }
        PEAR2_Pyrus_Config::current()->channelregistry->delete($chan);
        echo "Deleting channel ", $chan->name, " successful\n";
    }

    function upgradeRegistry($args, $options)
    {
        if (!file_exists($args['path']) || !is_dir($args['path'])) {
            echo "Cannot upgrade registries at ", $args['path'], ", path does not exist or is not a directory\n";
            exit -1;
        }
        echo "Upgrading registry at path ", $args['path'], "\n";
        $registries = PEAR2_Pyrus_Registry::detectRegistries($args['path']);
        if (!count($registries)) {
            echo "No registries found\n";
            exit;
        }
        if (!in_array('Pear1', $registries)) {
            echo "Registry already upgraded\n";
            exit;
        }
        $pear1 = new PEAR2_Pyrus_Registry_Pear1($args['path']);
        if (!in_array('Sqlite3', $registries)) {
            $sqlite3 = new PEAR2_Pyrus_Registry_Sqlite3($args['path']);
            $sqlite3->cloneRegistry($pear1);
        }
        if (!in_array('Xml', $registries)) {
            $xml = new PEAR2_Pyrus_Registry_Xml($args['path']);
            $sqlite3 = new PEAR2_Pyrus_Registry_Sqlite3($args['path']);
            $xml->cloneRegistry($sqlite3);
        }
        if ($options['removeold']) {
            PEAR2_Pyrus_Registry_Pear1::removeRegistry($args['path']);
        }
    }

    function runScripts($args)
    {
        $runner = new PEAR2_Pyrus_ScriptRunner($this);
        $reg = PEAR2_Pyrus_Config::current()->registry;
        foreach ($args['package'] as $package) {
            $package = $reg->package[$package];
            $runner->run($package);
        }
    }

    /**
     * Display pyrus configuration vars
     *
     */
    function configShow()
    {
        $conf = PEAR2_Pyrus_Config::current();
        echo "System paths:\n";
        foreach ($conf->mainsystemvars as $var) {
            echo "  $var => " . $conf->$var . "\n";
        }
        echo "Custom System paths:\n";
        foreach ($conf->customsystemvars as $var) {
            echo "  $var => " . $conf->$var . "\n";
        }
        echo "User config (from " . $conf->userfile . "):\n";
        foreach ($conf->mainuservars as $var) {
            echo "  $var => " . $conf->$var . "\n";
        }
        echo "Custom User config (from " . $conf->userfile . "):\n";
        foreach ($conf->customuservars as $var) {
            echo "  $var => " . $conf->$var . "\n";
        }
    }

    /**
     * Set a configuration option.
     *
     * @param array $args
     */
    function set($args)
    {
        $conf = PEAR2_Pyrus_Config::current();
        if (in_array($args['variable'], $conf->uservars)) {
            echo "Setting $args[0] in " . $conf->userfile . "\n";
            $conf->{$args['variable']} = $args[1];
        } elseif (in_array($args['variable'], $conf->systemvars)) {
            echo "Setting $args[variable] in system paths\n";
            $conf->{$args['variable']} = $args['value'];
        } else {
            echo "Unknown config variable: $args[variable]\n";
            exit -1;
        }
        $conf->saveConfig();
    }

    /**
     * Set up a pear path managed by pyrus.
     *
     * @param array $args Arguments
     */
    function mypear($args)
    {
        echo "Setting my pear repositories to:\n";
        echo implode("\n", $args['path']) . "\n";
        $args = implode(PATH_SEPARATOR, $args['path']);
        PEAR2_Pyrus_Config::current()->my_pear_path = $args;
        PEAR2_Pyrus_Config::current()->saveConfig();
    }

    function build($args)
    {
        echo "Building PECL extensions\n";
        $builder = new PEAR2_Pyrus_PECLBuild($this);
        foreach ($args['PackageName'] as $arg) {
            $package = PEAR2_Pyrus_Config::current()->registry->package[$arg];
            $builder->installBuiltStuff($package, $builder->build($package));
        }
    }

    /**
     * This is why we need to move to a better CLI system...
     *
     * make it possible to call confirmDialog() without it showing up as a command
     */
    function __call($func, $params)
    {
        if ($func === 'confirmDialog') {
            return $this->_confirmDialog($params[0]);
        }
        if ($func === 'display') {
            return $this->_display($params[0]);
        }
        if ($func === 'ask') {
            return call_user_func_array(array($this, '_ask'), $params);
        }
        throw new \Exception('Unknown method ' . $func . ' in class PEAR2_Pyrus_ScriptFrontend\Commands');
    }

    /**
     * Ask for user input, confirm the answers and continue until the user is satisfied
     * @param array an array of arrays, format array('name' => 'paramname', 'prompt' =>
     *              'text to display', 'type' => 'string'[, default => 'default value'])
     * @return array
     */
    function _confirmDialog($params)
    {
        $answers = $prompts = $types = array();
        foreach ($params as $param) {
            $prompts[$param['name']] = $param['prompt'];
            $types[$param['name']]   = $param['type'];
            $answers[$param['name']] = isset($param['default']) ? $param['default'] : '';
        }

        $tried = false;
        do {
            if ($tried) {
                $i = 1;
                foreach ($answers as $var => $value) {
                    if (!strlen($value)) {
                        echo $this->_bold("* Enter an answer for #" . $i . ": ({$prompts[$var]})\n");
                    }
                    $i++;
                }
            }

            $answers = $this->_userDialog('', $prompts, $types, $answers);
            $tried   = true;
        } while (is_array($answers) && count(array_filter($answers)) != count($prompts));

        return $answers;
    }

    function _display($text)
    {
        echo $text, "\n";
    }

    function _userDialog($command, $prompts, $types = array(), $defaults = array(), $screensize = 20)
    {
        if (!is_array($prompts)) {
            return array();
        }

        $testprompts = array_keys($prompts);
        $result      = $defaults;

        reset($prompts);
        if (count($prompts) === 1) {
            foreach ($prompts as $key => $prompt) {
                $type    = $types[$key];
                $default = isset($defaults[$key]) ? $defaults[$key] : false;
                print "$prompt ";
                if ($default) {
                    print "[$default] ";
                }
                print ": ";

                $line         = $this->_readStdin(2048);
                $result[$key] =  ($default && trim($line) == '') ? $default : trim($line);
            }

            return $result;
        }

        $first_run = true;
        while (true) {
            $descLength = max(array_map('strlen', $prompts));
            $descFormat = "%-{$descLength}s";
            $last       = count($prompts);

            $i = 0;
            foreach ($prompts as $n => $var) {
                $res = isset($result[$n]) ? $result[$n] : null;
                printf("%2d. $descFormat : %s\n", ++$i, $prompts[$n], $res);
            }
            print "\n1-$last, 'all', 'abort', or Enter to continue: ";

            $tmp = $this->_readStdin();
            if (empty($tmp)) {
                break;
            }

            if ($tmp == 'abort') {
                return false;
            }

            if (isset($testprompts[(int)$tmp - 1])) {
                $var     = $testprompts[(int)$tmp - 1];
                $desc    = $prompts[$var];
                $current = @$result[$var];
                print "$desc [$current] : ";
                $tmp = $this->_readStdin();
                if ($tmp !== '') {
                    $result[$var] = $tmp;
                }
            } elseif ($tmp == 'all') {
                foreach ($prompts as $var => $desc) {
                    $current = $result[$var];
                    print "$desc [$current] : ";
                    $tmp = $this->_readStdin();
                    if (trim($tmp) !== '') {
                        $result[$var] = trim($tmp);
                    }
                }
            }

            $first_run = false;
        }

        return $result;
    }

    function log($level, $message)
    {
        static $data = array();
        if (PEAR2_Pyrus_Config::initializing()) {
            // we can't check verbose until initializing is complete, so save
            // the message, and only display the log after config is initialized
            $data[] = array($level, $message);
            return;
        }
        if (count($data)) {
            $save = $data;
            $data = array();
            foreach ($save as $info) {
                $this->log($info[0], $info[1]);
            }
        }
        if ($level <= PEAR2_Pyrus_Config::current()->verbose) {
            echo $message, "\n";
        }
    }
}
