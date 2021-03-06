<?php

use \vendor\DB\DB;

class service extends base {

	protected $loginRequired = false;
	protected $sessionRequired = false;

	/**
	 * This service will expect a JSON POST data of:
	 * ["data"] => {"nonce": "randomString", "message": "cipherText", "signature": "abcdef"}
	 * Signature will be a sha256 of the message pre-encrypt with nonce appended to the end
	 * ie
	 * {JSON} + nonce + sharedhash
	 * Note: sharedhash should NOT be the sharedkey that is used to encrypt the message
	 *
	 *
	 * Unencrypted cipherText will look like
	 * {"output": "stdout of run", "time_taken": 10, "result": 0}
	 * Just like in most modern programs - a result of anything but 0 indicates an error
	 *
	 * @param $jobId
	 */
	public function upload($jobId) {
		if ($jobId && is_numeric($jobId)) {

			/** @var \application\models\Jobs $job */
			$job = \application\models\Jobs::getByField("id", $jobId);
			if (!$job) {
				echo "";
				return;
			}

			$job = $job[0];
			//decrypt message
			$data = json_decode($_POST["data"], true);
			$rawMessage = aes_decrypt($job->sharedkey, $data["message"]);
			/*$rawMessage = str_replace("\\n", "", $rawMessage);
			$rawMessage = str_replace("\\r", "", $rawMessage);
			$rawMessage = str_replace("\\", "", $rawMessage);*/
			$rawMessage = preg_replace('/[^(\x20-\x7F)]*/','', $rawMessage);




			// if decryption was successful -
			// check signature
			if (hash("sha256", $rawMessage . $data["nonce"] . $job->hash) == $data["signature"]) {
				// the message is verified
				$message = json_decode($rawMessage, true);
				$replayAttackCheck = DB::fetch("SELECT id FROM histories WHERE jobs_id = ? AND nonce = ?", [$job->id, $data["nonce"]]);
				if (count($replayAttackCheck) == 0) {
					$history = \application\models\Histories::create($message);
					$history->run_date = date("Y-m-d H:i:s");
					$history->jobs_id = $job->id;
					$history->nonce = $data["nonce"];
					$history->save();
					$job->last_result = $history->result;
					$job->last_run = $history->run_date;
					$job->save();
				}
			}
		}
	}

	// http://us3.php.net/manual/en/function.rmdir.php#117354
	private function rrmdir($dir) {
		if (is_dir($dir)) {
			$objects = scandir($dir);
			foreach ($objects as $object) {
				if ($object != "." && $object != "..") {
					if (is_dir($dir."/".$object))
						rrmdir($dir."/".$object);
					else
						unlink($dir."/".$object);
				}
			}
			rmdir($dir);
		}
	}

	public function run() {
		if (in_array($_SERVER["REMOTE_ADDR"], $this->config["ACCEPTED_IPS"])) { // not very secure - but worst case they fire off the run early
			if (!file_exists("/tmp/kritbot")) {
				touch("/tmp/kritbot");
				try {
					/** @var \application\models\Jobs[] $jobs */
					$jobs = DB::fetchObject("SELECT * FROM jobs", "\\application\\models\\Jobs");
					foreach ($jobs as $job) {
						if ($job->runType == 1) {
							$cron = Cron\CronExpression::factory($job->cron);
							if ($cron->isDue() || $job->force_run == 1) {
								$output = [];
								$returnVar = 0;

								$jobName = (isset($job->jobName) && !empty($job->jobName) && $job->jobName) ? $job->jobName : "----NOT-SET----";
								$dir = __DIR__ . DIRECTORY_SEPARATOR .".." . DIRECTORY_SEPARATOR . "tmp" . DIRECTORY_SEPARATOR . $jobName;
								if (is_dir($dir)) {
									$this->rrmdir($dir . DIRECTORY_SEPARATOR);
									mkdir($dir, 0777, true);
								} else {
									mkdir($dir, 0777, true);
								}

								$start = microtime(true);
								// grumble grumble something something windows
								if (stripos(php_uname("s"), "Win") !== false) {
									file_put_contents("$dir/kritscript.bat", $job->runScript);
									exec("c:\\windows\\system32\\cmd.exe /c $dir\kritscript.bat", $output, $returnVar);
								} else {
									file_put_contents("$dir/kritscript", $job->runScript);
									chmod("$dir/kritscript", 0777);
									exec("$dir/kritscript", $output, $returnVar);
								}
								$end = microtime(true);
								$delta = $end - $start;
								$scriptOutput = implode("\n", $output);
								if ($returnVar != 0) {
									if (stripos(php_uname("s"), "Win") !== false) {
										file_put_contents("$dir/failkritscript.bat", $job->failScript);
										exec("c:\\windows\\system32\\cmd.exe /c $dir\failkirtscript.bat");
									} else {
										file_put_contents("$dir/failkritscript", $job->failScript);
										chmod("$dir/failkritscript", 0777);
										exec("$dir/failkritscript", $output, $returnVar);
									}
								}
								$historyObj = new \application\models\Histories();
								$historyObj->output = $scriptOutput;
								$historyObj->result = $returnVar;
								$historyObj->time_taken = $delta;
								$historyObj->jobs_id = $job->id;
								$now = date("Y-m-d H:i:s");
								$historyObj->run_date = $now;
								$historyObj->save();
								$job->force_run = 0;
								$job->last_run = $now;
								$job->last_result = $returnVar;
								$job->save();
							}
						}
					}
					unlink("/tmp/kritbot");
				} catch (\Exception $e) {
					unlink("/tmp/kritbot");
				}
			}

		}
	}
}