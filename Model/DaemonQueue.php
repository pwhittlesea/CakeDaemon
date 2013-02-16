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

	public function findTask($task) {
		return $this->find(
			'first',
			array(
				'conditions' => array(
					'task' => $task,
					'created <=' => date('Y-m-d H:i:s')
				),
				'order' => array('created')
			)
		);
	}

	public function reschedule($mins, $task) {
		$this->create();
		$newTask = array(
			'created' => $this->_findNextSlot($task['DaemonQueue']['created'], $mins),
			'task' => $task['DaemonQueue']['task'],
			'subtask' => $task['DaemonQueue']['subtask'],
			'focus' => $task['DaemonQueue']['focus']
		);
		return $this->save($newTask);
	}

	private function _findNextSlot($date, $mins) {
		$now = strtotime(date('Y-m-d H:i:s'));
		$then = $mins;
		while ($now > strtotime($date . " +$then minutes")) {
			$then += $mins;
		}
		return date('Y-m-d H:i:s', strtotime($date . " +$then minutes"));
	}
}
