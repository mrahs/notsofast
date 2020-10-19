<?php

if(count(get_included_files()) ==1) exit("Direct access not permitted.");

class NotSoFast {

	public function __construct($base_dir = './')
	{
		$this->log_path = $base_dir.'NotSoFast.log';
		
		$msg = array();
		if (!extension_loaded('pdo_sqlite')) {
			$msg['pdo_sqlite'] = 'PDO SQLite extension is not available.';
		}

		$this->db_path = $base_dir.'NotSoFast.db';
		try {
			$this->init_db();
		} catch (Exception $e) {
			$msg['db'] = $e->getMessage();
		}

		if (@!$msg) {
			return;
		}

		$this->fail('Error initializing NotSoFast:<br/>' . join('<br/>', $msg));
	}

	public function is_so_fast($resource_id, $user_id, $interval = 1000)
	{
		// validate arguments
		if (!is_int($interval) || $interval < 0) {
			throw new Exception('Argument is not a valid integer: [' . $interval . ']');
		}

		$ts = microtime(true);
		$stmnt = $this->conn->prepare($this->get_query('select_access_log'));
		$stmnt->execute(array(':uid' => $user_id, ':rid' => $resource_id));
		$rs = $stmnt->fetchAll();
		
		$hit = @!!$rs && ($ts - (double)$rs[0]['ts'])*1000 <= $interval;
		//echo ($ts - (double)$rs[0]['ts'])*1000 .'<br>';

		/*log_access($resource_id, $user_id);*/

		// delete old entries
		$this->conn->exec($this->get_query('delete_old_records'));

		return $hit;
	}

	public function log_access($resource_id, $user_id) {
		$ts = microtime(true);
		$stmnt = $this->conn->prepare($this->get_query('insert_access_log'));
		$stmnt->execute(array(
			':uid' => $user_id,
			':rid' => $resource_id,
			':ts' => $ts
			));
	}

	public function set_debug($debug)
	{
		if (is_bool($debug)) {
			$this->debug = $debug;
		} else {
			throw new Exception('Argument is not boolean: [' . $debug . ']');
		}
	}

	private function fail($msg = '') {
		if ($this->debug) {
			exit($msg);
		} else {
			$this->log_error($msg);
			throw new Exception('NotSoFast fatal error. Check the log.');
		}
	}

	private function log_error($msg = '') {
		$msg = date('Y-m-d h:i:s A ') . str_replace('<br/>', ' ', $msg) . PHP_EOL;

		$log = fopen($this->log_path, 'at');
		fwrite($log, $msg);
		fclose($log);
	}

	private function init_db()
	{
		$msg = array();
		$rs = null;
		$count = 0;

		// Connect to DB
		try {
			$this->conn = new PDO('sqlite:' . $this->db_path, '', '');
		} catch (Exception $e) {
			throw new Exception('Connection failed: ' . $e->getMessage());
		}
		$this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// Validate
		try {
			$rs = $this->conn->query($this->get_query('validate_tables'));
		} catch (Exception $e) {
			throw new Exception('DB is invalid: ' . $e->getMessage());
		}

		$count = (int)($rs->fetch(PDO::FETCH_ASSOC)['COUNT']);
		if ($count === 0) {
			// Init
			$this->conn->exec($this->get_query('create_table'));
		} else {
			// Validate Columns
		}
		$rs->closeCursor();
	}

	private function get_query($query_name) {
		switch ($query_name) {
			case 'validate_tables':
				return 
				"SELECT count(*) COUNT 
				FROM sqlite_master 
				WHERE type = 'table' AND name IN ('access_log');";
			case 'create_table':
				return 
				"CREATE TABLE access_log (
					id 		INTEGER PRIMARY KEY, 
					uid 	TEXT 	NOT NULL,
					rid 	TEXT 	NOT NULL, 
					ts 		REAL 	NOT NULL DEFAULT (strftime('%s', 'now') || substr(strftime('%f', 'now'), 3)) 
				);";
			case 'select_access_log':
				return 'SELECT * FROM access_log WHERE uid = :uid AND rid = :rid ORDER BY ts DESC LIMIT 1';
			case 'insert_access_log':
				return 'INSERT INTO access_log (uid, rid, ts) VALUES(:uid, :rid, :ts)';
			case 'delete_old_records':
				return "DELETE FROM access_log WHERE strftime('%s','now') - ts > " . NotSoFast::MAX_RECORD_AGE;
			default:
				throw new Exception('Unkown query: ' . $query_name);
		}
	}

	function __destruct() {
		$this->conn = null;
	}

	const MAX_RECORD_AGE = 86400000; // 24 hours
	private $debug = false;
	private $conn = null;
	private $db_path = 'NotSoFast.db';
	private $log_path = 'NotSoFast.log';
}