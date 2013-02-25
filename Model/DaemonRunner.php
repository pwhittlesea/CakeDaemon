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

	public function init() {
		DaemonRunner::sessionWrite('runners', array());
	}

	public function findByPid($pid) {
		$runners = DaemonRunner::sessionRead('runners');
		$matchingRunners = Set::extract("/DaemonRunner[pid=$pid]", $runners);
		return $matchingRunners[0];
	}

	public function findStaleJobByJobType($jobType) {
		$runners = DaemonRunner::sessionRead('runners');
		$matchingRunners = Set::extract("/DaemonRunner[jobType=$jobType][pid=-1]", $runners);
		return $matchingRunners[0];
	}

	public function findStaleJobTypes() {
		$runners = DaemonRunner::sessionRead('runners');
		$taskIds = Set::extract("/DaemonRunner[pid=-1]/jobType", $runners);
		sort($taskIds);
		return $taskIds;
	}

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

	public static function sessionClose() {
		session_write_close();
	}

	public static function sessionOpen() {
		session_name("DaemonSession");
		if (!@session_start()) {
			die("Could not create session!");
		}
	}
}
