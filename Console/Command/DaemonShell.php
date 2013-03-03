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
App::import('Console/Command', 'AppShell');
App::import('Vendor', 'CakeDaemon.SystemDaemon', array('file' => 'SystemDaemon'.DS.'System'.DS.'Daemon.php'));

class DaemonShell extends AppShell {

	public $config = array();

	public $uses = array(
		'CakeDaemon.DaemonQueue',
		'CakeDaemon.DaemonRunner'
	);

/**
 * getOptionParser function.
 * @see http://book.cakephp.org/2.0/en/console-and-shells.html
 */
	public function getOptionParser() {
		$parser = parent::getOptionParser();
		$parser->addSubcommand('start', array(
			'help' => __('Start the Daemon.')
		))->addOption('no-daemon', array(
			'boolean' => true,
			'help' => __('Prevent background forking')
		));
		$parser->addSubcommand('stop', array(
			'help' => __('Stop the Daemon.')
		));
		$parser->addSubcommand('status', array(
			'help' => __('Check the state of the Daemon.')
		));
		return $parser;
	}

/**
 * stop function.
 * Force stop the running instance.
 */
	public function stop() {
		// Setup
		$options = array(
			'appName' => 'cakedaemon',
			'logLocation' => TMP . "logs" . DS . 'cakedaemon.log'
		);

		System_Daemon::setOptions($options);
		System_Daemon::stopRunning();
	}

/**
 * start function.
 * Start an instance of the worker.
 */
	public function start() {
		// Setup
		$options = array(
			'appName' => 'cakedaemon',
			'appDescription' => 'Runs extended tasks defined in the host app in the background',
			'sysMaxExecutionTime' => '0',
			'sysMaxInputTime' => '0',
			'sysMemoryLimit' => '1024M',
			'logLocation' => TMP . "logs" . DS . 'cakedaemon.log',
			'logVerbosity' => System_Daemon::LOG_INFO
		);
		System_Daemon::setOptions($options);
		System_Daemon::setSigHandler('SIGCHLD', array(&$this, 'handleSIGCHLD'));

		// This program can also be run in the forground with argument no-daemon
		if (!$this->params['no-daemon']) {
			// Spawn Daemon
			System_Daemon::start();
		}

		$this->__initConfig();

		// Here we have to do several things
		// 1) Figure out how many stale static nodes we have
		// 2) Ask the DB for one one job for each node
		// 3) Start these jobs on each of the corresponding nodes
		// 4) Wait for a pre-determined amount of time
		// 5) Check if any children have finished and start again
		while (!System_Daemon::isDying()) {
			$taskIds = $this->DaemonRunner->findStaleJobTypes();
			$currentlyRunningJobs = $this->DaemonRunner->findProcessingJobs();

			System_Daemon::debug("Stale Process Types: " . json_encode($taskIds));
			System_Daemon::debug("Aware of currently running job ids: " . json_encode($currentlyRunningJobs));
			foreach ($taskIds as $id) {
				if ($job = $this->DaemonQueue->findJob($id, $currentlyRunningJobs)) {
					$currentlyRunningJobs[] = $job['DaemonQueue']['id'];
					$this->__spawnChild($job, $this->DaemonRunner->findStaleRunnerByJobType($id));
				}
			}

			// Sometimes our sleep is inturrupted
			// This could be by a very noisy owl, or by a completed child process.
			// Either way, we should get our beauty sleep!
			$timeToSleep = $this->config['timeout'];
			while ($timeToSleep > 0) {
				$timeToSleep = sleep($timeToSleep);
			}
			clearstatcache();

			$this->__reconnectDB();

			// For some reason the whole SIG handle thing doesnt work in
			// foreground mode
			if ($this->params['no-daemon']) {
				$this->__mopUp();
			}
		}

		System_Daemon::stop();
	}

/**
 * status function.
 * Check the status of the daemon
 */
	public function status() {
		// Setup
		$options = array(
			'appName' => 'cakedaemon',
			'logLocation' => TMP . "logs" . DS . 'cakedaemon.log'
		);
		System_Daemon::setOptions($options);

		if (System_Daemon::isRunning() == true) {
			$this->out('Daemon is running');
			exit(0);
		} else {
			$this->out('Daemon is not running');
			exit(1);
		}
	}

/**
 * handleSIGCHLD function.
 * Handle any finished children
 *
 * @param mixed $signo
 */
	public function handleSIGCHLD($signo) {
		System_Daemon::debug("signal caught in SIGCHLD handler: " . $signo);
		$this->__mopUp();
	}

/**
 * __initConfig function.
 *
 * @access private
 * @return void
 */
	private function __initConfig() {
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
 * __mopUp function.
 *
 * @access private
 * @return void
 */
	private function __mopUp() {
		$pid = pcntl_waitpid($pid = -1, $status, WNOHANG);

		// While we have children to tend to
		while ($pid > 0) {
			$runner = $this->DaemonRunner->findByPid($pid);
			if ($runner != null) {
				$runnerUuid = $runner['DaemonRunner']['uuid'];
				if (pcntl_wifexited($status) && $this->DaemonRunner->setFinished($runnerUuid)) {
					System_Daemon::debug("PROCESS[$pid] - complete");
				} else {
					System_Daemon::crit("PROCESS[$pid] - irregular termination");
					//TODO RESTART TASK
				}
			}
			// Check for other child tasks that have finished
			$pid = pcntl_waitpid($pid = -1, $status, WNOHANG);
		}
	}

/**
 * __reconnectDB function.
 *
 * @access private
 * @return void
 */
	private function __reconnectDB() {
		$this->DaemonQueue->getDatasource()->reconnect();
	}

/**
 * __spawnChild function.
 *
 * @access private
 * @param mixed $task
 * @param mixed $runner
 * @return void
 */
	private function __spawnChild($task, $runner) {
		$taskName = $runner['DaemonRunner']['taskName'];
		$taskId = $task['DaemonQueue']['id'];

		$pid = pcntl_fork();

		// Everytime we fork we break our connection
		$this->__reconnectDB();

		if ($pid == -1) {
			System_Daemon::crit("TASK[$taskId] - failed to execute");
			return false;
		} else if ($pid) {
			System_Daemon::debug("PROCESS[$pid] - started");
			return true;
		} else {
			$pid = posix_getpid();
			System_Daemon::info("TASK[$taskId] - started");

			// Register that we are running this job
			$this->DaemonRunner->setRunning($runner['DaemonRunner']['uuid'], $pid, $taskId);

			$nodeInstance = $this->Tasks->load($taskName);
			if ($nodeInstance->execute($task)) {
				if ($cron = $runner['DaemonRunner']['cron']) {
					if (!$this->DaemonQueue->reschedule($cron, $task)) {
						System_Daemon::warn("TASK[$taskId] - could not be recheduled");
					} else {
						System_Daemon::info("TASK[$taskId] - complete & recheduled");
					}
				} else {
					if (!$this->DaemonQueue->setComplete($taskId)) {
						System_Daemon::crit("TASK[$taskId] - may not be complete");
					} else {
						System_Daemon::info("TASK[$taskId] - complete");
					}
				}

				exit;
			} else {
				System_Daemon::crit("TASK[$taskId] - failed to execute");
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
