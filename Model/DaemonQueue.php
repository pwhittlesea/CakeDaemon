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

class DaemonQueue extends CakeDaemonAppModel {

	public $useTable = 'daemon_queue';

	public function findJob($task, $exclusions = array()) {
		// TODO exclude in progress tasks
		return $this->find(
			'first',
			array(
				'conditions' => array(
					'NOT' => array(
						'id' => $exclusions
					),
					'task' => $task,
					'created <=' => date('Y-m-d H:i:s')
				),
				'order' => array('created')
			)
		);
	}

/**
 * reschedule function.
 * Ensure that the job is run again after a certain amoun of time
 *
 * @param mixed $mins the mins to delay by
 * @param mixed $task the task to rechedule
 * @return true if all went well
 */
	public function reschedule($mins, $task) {
		$this->id = $task['DaemonQueue']['id'];
		return $this->saveField('created', $this->__findNextSlot($task['DaemonQueue']['created'], $mins));
	}

/**
 * setComplete function.
 * Mark a task as complete
 *
 * @param mixed $taskId the task id
 * @return true if all went ok
 */
	public function setComplete($taskId) {
		$task = $this->findById($taskId);
		if ($task == null) {
			return true;
		}

		$taskTime = $task['DaemonQueue']['created'];
		if (strtotime('now') > strtotime($taskTime)) {
			return $this->delete($taskId);
		}

		return true;
	}

/**
 * __findNextSlot function.
 * Find the next slot that is in the future
 *
 * @param mixed $date the original date for the task
 * @param mixed $mins the number of mins to add to the original date
 * @return the new time
 */
	private function __findNextSlot($date, $mins) {
		$now = strtotime(date('Y-m-d H:i:s'));
		$then = $mins;
		while ($now > strtotime($date . " +$then minutes")) {
			$then += $mins;
		}
		return date('Y-m-d H:i:s', strtotime($date . " +$then minutes"));
	}
}
