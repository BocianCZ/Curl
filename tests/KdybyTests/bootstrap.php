<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.md that was distributed with this source code.
 */

if (@!include __DIR__ . '/../../vendor/autoload.php') {
	echo 'Install Nette Tester using `composer update --dev`';
	exit(1);
}

// configure environment
Tester\Helpers::setup();
class_alias('Tester\Assert', 'Assert');
date_default_timezone_set('Europe/Prague');

// create temporary directory
define('TEMP_DIR', __DIR__ . '/../tmp/' . (isset($_SERVER['argv']) ? md5(serialize($_SERVER['argv'])) : getmypid()));
Tester\Helpers::purge(TEMP_DIR);


$_SERVER = array_intersect_key($_SERVER, array_flip(array(
	'PHP_SELF', 'SCRIPT_NAME', 'SERVER_ADDR', 'SERVER_SOFTWARE', 'HTTP_HOST', 'DOCUMENT_ROOT', 'OS', 'argc', 'argv')));
$_SERVER['REQUEST_TIME'] = 1234567890;
$_ENV = $_GET = $_POST = array();


if (extension_loaded('xdebug')) {
	xdebug_disable();
	Tester\CodeCoverage\Collector::start(__DIR__ . '/coverage.dat');
}

function id($val) {
	return $val;
}

function run(Tester\TestCase $testCase) {
	$testCase->run(isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : NULL);
}



class HttpServer extends Nette\Object
{

	/**
	 * @var array
	 */
	private $pipes = array();

	/**
	 * @var resource
	 */
	private $process;

	private static $spec = array(
		0 => array("pipe", "r"), // stdin is a pipe that the child will read from
		1 => array("pipe", "w"), // stdout is a pipe that the child will write to
		2 => array("pipe", "w"), // errors
	);

	public function __destruct()
	{
		$this->slaughter();
	}

	public function start($router, $port = NULL, $ip = '127.0.0.1')
	{
		$this->slaughter();

		$port = $port ? : rand(8000, 10000);
		$cmd = sprintf('php -S %s:%d %s', $ip, $port, escapeshellarg($router));
		if (!is_resource($this->process = proc_open($cmd, self::$spec, $this->pipes))) {
			throw new \RuntimeException("Could not execute: `$cmd`");
		}

		sleep(1); // give him some time to boot up

		return 'http://' . $ip .':' . $port;
	}

	public function slaughter()
	{
		if (!is_resource($this->process)) {
			return;
		}

		$status = proc_get_status($this->process);
		if ($status['running'] == true) {
			fclose($this->pipes[1]); //stdout
			fclose($this->pipes[2]); //stderr

			//get the parent pid of the process we want to kill
			$pPid = $status['pid'];

			//use ps to get all the children of this process, and kill them
			foreach (array_filter(preg_split('/\s+/', `ps -o pid --no-heading --ppid $pPid`)) as $pid) {
				if (is_numeric($pid)) {
					posix_kill($pid, 9); // SIGKILL signal
				}
			}
		}

		fclose($this->pipes[0]);
		proc_close($this->process);

		$this->process = NULL;
	}

}
