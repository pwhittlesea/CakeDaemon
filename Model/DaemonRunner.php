<?php
/**
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     GitCake Development Team 2012
 * @link          http://github.com/pwhittlesea/daemonshell
 * @package       DaemonShell.Model
 * @since         GitCake v 1.1
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

App::uses('CakeDaemonAppModel', 'CakeDaemon.Model');

class DaemonRunner extends CakeDaemonAppModel {

	public $useTable = false;

/**
 * init function.
 * Clear the old SESSION information on the runners
 */
	public function init() {
		DaemonRunner::sessionWrite('runners', array());
	}

/**
 * findByPid function.
 * Find a runner based on the PID that was assigned to it
 *
 * @param mixed $pid the PID to check
 * @return the runner details
 */
	public function findByPid($pid) {
		$runners = DaemonRunner::sessionRead('runners');
		$matchingRunners = Set::extract("/DaemonRunner[pid=$pid]", $runners);
		if (!isset($matchingRunners[0])){
			return null;
		}
		return $matchingRunners[0];
	}

/**
 * findProcessingJobs function.
 * Get a list of tasks that are currently processing.
 *
 * @return the list of jobs
 */
	public function findProcessingJobs() {
		$runners = DaemonRunner::sessionRead('runners');
		return Set::extract("/DaemonRunner/job", $runners);
	}

/**
 * findStaleRunnerByJobType function.
 * Find a runner that is capable of running a task of type.
 *
 * @param mixed $jobType the job type
 * @return the runner information
 */
	public function findStaleRunnerByJobType($jobType) {
		$runners = DaemonRunner::sessionRead('runners');
		$matchingRunners = Set::extract("/DaemonRunner[jobType=$jobType][pid=-1]", $runners);
		return $matchingRunners[0];
	}

/**
 * findStaleJobTypes function.
 * Find a list of types that we have runners for that are not
 * processing.
 *
 * @return the list of types
 */
	public function findStaleJobTypes() {
		$runners = DaemonRunner::sessionRead('runners');
		$taskIds = Set::extract("/DaemonRunner[pid=-1]/jobType", $runners);
		sort($taskIds);
		return $taskIds;
	}

/**
 * newRunner function.
 * Create a new runner
 *
 * @param mixed $name the name for the runner
 * @param mixed $singleton can we have only one of the runner
 * @param mixed $jobType the type the runner can run
 * @param mixed $cron should we rechedule not delete on completion?
 * @return void
 */
	public function newRunner($name, $singleton, $jobType, $cron) {
		$runners = DaemonRunner::sessionRead('runners');
		$runners[] = array(
			'DaemonRunner' => array(
				'cron' => $cron,
				'job' => -1,
				'jobType' => $jobType,
				'pid' => -1,
				'singleton' => (($singleton == 0) ? 0 : 1),
				'taskName' => $name,
				'uuid' => String::uuid(),
			)
		);
		DaemonRunner::sessionWrite('runners', $runners);
	}

/**
 * setFinished function.
 * Mark a runner as complete and clear its state
 *
 * @param mixed $runnerUuid the unique id for the runner
 * @return true if the runner was found
 */
	public function setFinished($runnerUuid) {
		$runners = DaemonRunner::sessionRead('runners');
		foreach ($runners as $a => $runner) {
			if ($runner['DaemonRunner']['uuid'] == $runnerUuid) {
				$runners[$a]['DaemonRunner']['job'] = -1;
				$runners[$a]['DaemonRunner']['pid'] = -1;
				DaemonRunner::sessionWrite('runners', $runners);
				return true;
			}
		}
		return false;
	}

/**
 * setRunning function.
 * Mark that the runner is processing a job
 *
 * @param mixed $runnerUuid the uuid of the runner
 * @param float $pid (default: -1) the new PID
 * @param float $job (default: -1) the new job number
 * @return true if everything went fine
 */
	public function setRunning($runnerUuid, $pid = -1, $job = -1) {
		$runners = DaemonRunner::sessionRead('runners');
		foreach ($runners as $a => $runner) {
			if ($runner['DaemonRunner']['uuid'] == $runnerUuid) {
				$runners[$a]['DaemonRunner']['job'] = $job;
				$runners[$a]['DaemonRunner']['pid'] = $pid;
				DaemonRunner::sessionWrite('runners', $runners);
				return true;
			}
		}
		return false;
	}

/**
 * sessionRead function.
 * Extract a variable from the session for the Daemon
 *
 * @param mixed $variableName the variable to extract
 * @return the value of the variable
 */
	public static function sessionRead($variableName) {
		DaemonRunner::sessionOpen();
		if (!isset($_SESSION[$variableName])) {
			DaemonRunner::sessionClose();
			return null;
		}
		DaemonRunner::sessionClose();
		return $_SESSION[$variableName];
	}

/**
 * sessionWrite function.
 * Store a variable in the session for the Daemon
 *
 * @param mixed $variableName the variable to set
 * @param mixed $value the new value
 * @return void
 */
	public static function sessionWrite($variableName, $value) {
		DaemonRunner::sessionOpen();
		if ($value == null) {
			unset($_SESSION[$variableName]);
		} else {
			$_SESSION[$variableName] = $value;
		}
		DaemonRunner::sessionClose();
	}

/**
 * sessionClose function.
 * Close a session
 */
	public static function sessionClose() {
		session_write_close();
	}

/**
 * sessionOpen function.
 * Open a new session or an existing one
 */
	public static function sessionOpen() {
		session_name("DaemonSession");
		if (!@session_start()) {
			die("Could not create session!");
		}
	}
}
