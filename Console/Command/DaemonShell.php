<?php
/**
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     GitCake Development Team 2012
 * @link          http://github.com/pwhittlesea/daemonshell
 * @package       DaemonShell.Console.Command
 * @since         GitCake v 1.1
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

App::import('Vendor', 'CakeDaemon.SystemDaemon', array('file' => 'SystemDaemon'.DS.'System'.DS.'Daemon.php'));

class DaemonShell extends AppShell {

	public $config = array();

	public $loggingFile = "worker";

	public $uses = array(
		'CakeDaemon.DaemonQueue',
		'CakeDaemon.DaemonRunner'
	);

/**
 * stop function.
 * Force stop the running instance.
 */
	public function stop() {
		// Setup
		$options = array(
			'appName' => 'cakedaemon',
			'logLocation' => TMP . "logs" . DS . $this->loggingFile . '.log'
		);

		System_Daemon::setOptions($options);
		System_Daemon::stopRunning();
	}

/**
 * start function.
 * Start an instance of the worker.
 */
	public function start() {
		// Allowed arguments & their defaults
		$runmode = array(
			'no-daemon' => false,
		);

		// Scan command line attributes for allowed arguments
		$args = array_merge($_SERVER, $_ENV);
		foreach ($args['argv'] as $k=>$arg) {
			if (isset($runmode[$arg])) {
				$runmode[$arg] = true;
			}
		}

		// Setup
		$options = array(
			'appName' => 'cakedaemon',
			'appDescription' => 'Runs extended tasks defined in the host app in the background',
			'sysMaxExecutionTime' => '0',
			'sysMaxInputTime' => '0',
			'sysMemoryLimit' => '1024M',
			'logLocation' => TMP . "logs" . DS . $this->loggingFile . '.log'
		);

		System_Daemon::setOptions($options);

		// This program can also be run in the forground with argument no-daemon
		if (!$runmode['no-daemon']) {
			// Spawn Daemon
			System_Daemon::start();
		}

		$this->_initConfig();

		// Here we have to do several things
		// 1) Figure out how many stale static nodes we have
		// 2) Ask the DB for one one job for each node
		// 3) Start these jobs on each of the corresponding nodes
		// 4) Wait for a pre-determined amount of time
		// 5) Check if any children have finished and start again
		while (!System_Daemon::isDying()) {
			$taskIds = $this->DaemonRunner->findStaleJobTypes();

			foreach ($taskIds as $id) {
				if ($task = $this->DaemonQueue->findTask($id)) {
					$this->_spawnChild($task, $this->DaemonRunner->findStaleJobByJobType($id));
				}
			}

			System_Daemon::iterate($this->config['timeout']);

			$this->_reconnectDB();

			$this->_mopUp();
		}

		System_Daemon::stop();
	}

/**
 * _initConfig function.
 *
 * @access private
 * @return void
 */
	private function _initConfig() {
		$this->config = Configure::read('daemon');

		$nodes = $this->config['nodes'];

		if (count($nodes) < 1) {
			System_Daemon::crit("configuration error - no nodes defined");
			exit(1);
		}

		if ($this->config['maxNodes'] < count($nodes)) {
			System_Daemon::crit("configuration error - max nodes cannot be less than actual nodes");
			exit(1);
		}

		// Set the default timeout
		if (!isset($this->config['timeout'])) {
			$this->config['timeout'] = 2;
		}

		// Initialise the in-memory store of the runners
		$this->DaemonRunner->init();

		foreach ($this->config['nodes'] as $nodeName) {
			// Check that we can create an instance of a this node type and save it
			$nodeInstance = $this->Tasks->load($nodeName);

			if (method_exists($nodeInstance, 'cron')) {
				$cron = $nodeInstance->cron();
			} else {
				$cron = null;
			}

			$this->DaemonRunner->newRunner($nodeName, 1, $nodeInstance->getTaskId(), $cron);
		}
		System_Daemon::info("config loaded");
	}

/**
 * _mopUp function.
 *
 * @access private
 * @return void
 */
	private function _mopUp() {
		System_Daemon::debug("checking for finished processes");
		$pid = pcntl_waitpid($pid = -1, $status, WNOHANG);

		// While we have children to tend to
		while ($pid > 0) {
			$runner = $this->DaemonRunner->findByPid($pid);
			$jobId = $runner['DaemonRunner']['job'];
			$runnerUuid = $runner['DaemonRunner']['uuid'];
			if (pcntl_wifexited($status)) {
				if ($this->DaemonQueue->setComplete($jobId) && $this->DaemonRunner->setFinished($runnerUuid)) {
					System_Daemon::info("process[$pid] exited normally after executing job[$jobId]");
				} else {
					System_Daemon::crit("could not verify job[$jobId] was completed");
				}
			} else {
				System_Daemon::crit("process[$pid] was terminated before completion");
				//TODO RESTART TASK
			}
			// Check for other child tasks that have finished
			$pid = pcntl_waitpid($pid = -1, $status, WNOHANG);
		}
	}

/**
 * _reconnectDB function.
 *
 * @access private
 * @return void
 */
	private function _reconnectDB() {
		$this->DaemonQueue->getDatasource()->reconnect();
	}

/**
 * _spawnChild function.
 *
 * @access private
 * @param mixed $task
 * @param mixed $runner
 * @return void
 */
	private function _spawnChild($task, $runner) {
		$taskName = $runner['DaemonRunner']['taskName'];
		$taskId = $task['DaemonQueue']['id'];

		$pid = pcntl_fork();

		// Everytime we fork we break our connection
		$this->_reconnectDB();

		if ($pid == -1) {
			System_Daemon::crit("could not spawn process with type[$taskName]");
			return false;
		} else if ($pid) {
			return true;
		} else {
			$pid = posix_getpid();
			System_Daemon::debug("created process[$pid]");

			// Register that we are running this job
			$this->DaemonRunner->setRunning($runner['DaemonRunner']['uuid'], $pid, $taskId);

			$nodeInstance = $this->Tasks->load($taskName);
			if ($nodeInstance->execute($task)) {
				if ($cron = $runner['DaemonRunner']['cron']) {
					if (!$this->DaemonQueue->reschedule($cron, $task)) {
						System_Daemon::warn("task[$taskId] could not be recheduled");
					} else {
						System_Daemon::debug("task[$taskId] was recheduled");
					}
				}
				exit;
			} else {
				System_Daemon::crit("task[$taskId] failed to execute");
				exit(1);
			}
		}
	}

/**
 * Override the default welcome.
 */
	protected function _welcome(){
	}

}
