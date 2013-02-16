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

	public $tasks = array(
		'PidManagement'
	);

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

		// This program can also be run in the forground with runmode --no-daemon
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
			$stale = $this->DaemonRunner->findStale();
			$taskIds = Set::extract("/DaemonRunner/task_id", $stale);

			sort($taskIds);

			foreach ($taskIds as $t) {
				$task = $this->DaemonQueue->findTask($t);
				if ($task) {
					$runner = Set::extract("/DaemonRunner[task_id=$t]", $stale);
					$this->_spawnChild($task, $runner[0]);
				}
			}

			System_Daemon::iterate($this->config['timeout']);

			// Sleeping broke our database connecitons so lets reconnect
			$this->DaemonRunner->getDatasource()->connect();
			$this->DaemonQueue->getDatasource()->connect();

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

		// Remove all the old nodes as we are reloading from config
		$this->DaemonRunner->deleteAll(array('id >' => -2));

		foreach ($this->config['nodes'] as $node) {
			// Check that we can create an instance of a this node type
			$nodeInstance = $this->Tasks->load($node);
			$newRunner = array(
				'type' => $node,
				'task_id' => $nodeInstance->getTaskId(),
				'job' => -1,
				'pid' => -1
			);
			// Save the new node
			$this->DaemonRunner->save($newRunner);
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
		System_Daemon::debug("checking for finished child threads");
		$pid = pcntl_waitpid($pid = -1, $status, WNOHANG);

		// While we have children to tend to
		while ($pid > 0) {
			$runner = $this->DaemonRunner->findByPid($pid, 'job');
			$jobId = $runner['DaemonRunner']['job'];
			if (pcntl_wifexited($status)) {
				if ($this->DaemonQueue->delete($jobId)) {
					System_Daemon::DEBUG("child $pid exited normally");
					$this->DaemonRunner->setFinished($pid);
				} else {
					System_Daemon::crit("could not delete job $jobId");
				}
			} else {
				System_Daemon::crit("child $pid was terminated before completion");
				//TODO RESTART TASK
			}
			// Check for other child tasks that have finished
			$pid = pcntl_waitpid($pid = -1, $status, WNOHANG);
		}
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
		$pid = pcntl_fork();
		if ($pid == -1) {
			System_Daemon::crit("could not spawn child with type " . $runner['DaemonRunner']['type']);
			return false;
		} else if ($pid) {
			return true;
		} else {
			$pid = posix_getpid();
			System_Daemon::debug("created child with pid $pid");

			$runnerId = $runner['DaemonRunner']['id'];
			$taskId = $task['DaemonQueue']['id'];
			$runnerType = $runner['DaemonRunner']['type'];

			// Register that we are running this job
			$this->DaemonRunner->setRunning($runnerId, $pid, $taskId);

			$nodeInstance = $this->Tasks->load($runner['DaemonRunner']['type']);
			if ($nodeInstance->execute($task)) {
				if (method_exists($nodeInstance, 'cron')) {
					$cron = $nodeInstance->cron();
					if (!$this->DaemonQueue->reschedule($cron, $task)) {
						System_Daemon::warn("task $taskId could not be recheduled");
					} else {
						System_Daemon::debug("task $taskId was recheduled");
					}
				}
				exit;
			} else {
				System_Daemon::crit("task $taskId failed to execute");
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
