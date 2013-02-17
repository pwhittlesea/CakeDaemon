<?php
/**
 * ExampleTask class.
 * An example of a task that can be run by the DaemonRunner.
 *
 * Create tasks in your APP/Console/Command/Task folder and
 * specify their inclusion in the core config of your application:
 * $config['daemon']['nodes'] = array('Example');
 *
 * Each task will be given a job from the job queue to process in the
 * background.
 *
 * Its recommended that all log output from this task should be directed to
 * the worker log.
 *
 * @extends Shell
 */

class ExampleTask extends Shell {

	public static $taskName = 'Example';

/**
 * singleton
 * True if only one instance of this task can be run at any
 * one time
 *
 * @var bool
 * @static
 */
	public static $singleton = true;

/**
 * cron function.
 * Used to establish if the job that was executed by this task
 * needs to be rescheduled in a certain period of time.
 *
 * For example:
 * if 1 is returned then the task will be executed every minute
 * unless the job takes more than a minute to execute, in which
 * case the cron time will be incremented until the new scheduled
 * time is after the current time
 *
 * @return the number of mins between execution times, null if the job
 *         is not to be rescheduled
 */
	public function cron() {
		return 1;
	}

/**
 * execute function.
 * This function is called when a job is to be processed.
 *
 * @param array $task in the format
 *        array('DaemonQueue'=>array('task'=>1,'subtask'=>1,'focus'=>1))
 *        where subtask is the method to action on and the focus is an
 *        object in the system
 * @return true if the execution was successful, false if otherwise
 */
	public function execute($task) {
		$this->log("Executing {$this->name}", "worker");
		return true;
	}

/**
 * getTaskId function.
 * This is used to collect a UNIQUE id that identifys
 * jobs that this Task is responisble for processing.
 *
 * For example:
 * If this method returns 1, then all tasks in the RunnerQueue
 * which have the task id of 1 will be passed to the execute
 * function above
 *
 * Warning:
 * If the tasks in your system do not return unique values then
 * it is not guaranteed that tasks will run the correct jobs
 *
 * @return the unique id
 */
	public function getTaskId() {
		return 1;
	}
}
