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

	public $useTable = 'daemon_runners';

	public function findStale() {
		return $this->find(
			'all',
			array(
				'conditions' => array(
					'pid' => -1,
					'job' => -1
				)
			)
		);
	}

	public function setFinished($pid) {
		$runnerId = $this->findByPid($pid, 'id');
		return $this->_setState($runnerId, -1, -1);
	}

	public function setRunning($runnerId, $pid = -1, $jobId = -1) {
		return $this->_setState($runnerId, $pid, $jobId);
	}

	private function _setState($runnerId, $pid, $jobId) {
		$this->id = $runnerId;
		$this->set('pid', $pid);
		$this->set('job', $jobId);
		return $this->save();
	}
}
