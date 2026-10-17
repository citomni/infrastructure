<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\Infrastructure\Service;

use CitOmni\Infrastructure\Enum\TransactionIsolation;
use CitOmni\Infrastructure\Exception\DbConnectException;
use CitOmni\Infrastructure\Exception\DbQueryException;
use CitOmni\Kernel\Service\BaseService;

/**
 * Db: Service that runs MySQL and MariaDB statements through mysqli with prepared statements.
 *
 * The one connection per request or process that Repositories run their SQL on, opened on
 * first use. It binds values to the placeholders of the SQL it is given and offers small
 * helpers for single-table writes; it builds no other queries and maps no objects. Which SQL
 * runs belongs to the Repositories.
 *
 * Rules:
 * - Open the connection on first use, and read the password from the secrets service only
 *   while connecting; never keep it on the service.
 * - Report a failure to set up the connection as DbConnectException, and a failing statement
 *   or transaction call as DbQueryException, with the mysqli exception as previous when there
 *   is one.
 * - Add to SQL only fixed text, placeholders, values that the service formats itself, and
 *   identifiers checked to hold letters, digits, _ and $ only; bind every other value.
 * - Never nest transactions.
 */
class Db extends BaseService {

	/** Most rows that insertBatch() sends as one multi-row INSERT. */
	private const BATCH_ROW_LIMIT         = 1000;

	/** Largest estimated payload in bytes that insertBatch() sends as one multi-row INSERT. */
	private const BATCH_BYTE_LIMIT        = 4 * 1024 * 1024; // 4 MB coarse estimate

	/** Rows per executeMany() call when insertBatch() splits a larger payload. */
	private const BATCH_CHUNK_SIZE        = 1000;

	/** Server host name or IP address. */
	private string  $cfgHost;

	/** Database user. */
	private string  $cfgUser;

	/** Database (schema) name. */
	private string  $cfgName;

	/** Connection character set. */
	private string  $cfgCharset;

	/** TCP port, 1-65535. */
	private int     $cfgPort;

	/** Unix socket path, or null for the mysqli default. */
	private ?string $cfgSocket;

	/** Connect timeout in seconds, >= 1. */
	private int     $cfgConnectTimeout;

	/** Secrets key of the database password. */
	private string  $cfgPasswordSecret;

	/** Session sql_mode, or null to keep the server default. */
	private ?string $cfgSqlMode;

	/** Session time_zone, or null for the UTC offset of PHP's active time zone. */
	private ?string $cfgTimezone;

	/** Open connection, or null before first use and after close(). */
	private ?\mysqli $connection = null;

	/** Whether a transaction opened through this service is active; START TRANSACTION sent as SQL and implicit commits are not seen. */
	private bool $inTransaction = false;

	/** @var array<string,\mysqli_stmt> Cached prepared statements by SQL, oldest first. */
	private array $statementCache = [];

	/** Maximum number of cached statements; 0 disables the cache. */
	private int $statementCacheLimit;

	/** Statements run since construction or the last reset by countQueries(). */
	private int $queryCount = 0;



	// ----------------------------------------------------------------
	// Initialization
	// ----------------------------------------------------------------

	/**
	 * Read and check the db cfg once, without connecting.
	 *
	 * Behavior:
	 * - A service option that is not null wins over the cfg key of the same name.
	 * - Reads the name of the password secret, not the password.
	 * - Rejects the removed key pass in cfg and options, so a password left there is not
	 *   silently ignored.
	 * - Switches mysqli error reporting to MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT, for every
	 *   mysqli call in the process.
	 *
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When host, user, name or charset is empty, password_secret is not a non-empty string, port, connect_timeout or statement_cache_limit is not an integer or string of digits in range (port 1-65535, connect_timeout >= 1, statement_cache_limit >= 0), or pass is set.
	 * @throws \OutOfBoundsException  When the db node, or a key that no option overrides, is missing.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		// Service options win over cfg; an option that is null falls back to the cfg key.
		$cfg = $this->app->cfg->db;
		$opt = $this->options;
		$this->options = []; // free; never read again

		if (\array_key_exists('pass', $opt) || \array_key_exists('pass', $cfg->toArray())) {
			throw new DbConnectException(
				'DB config key "pass" is no longer supported; use a Secrets key via "password_secret".'
			);
		}

		$this->cfgHost = \trim((string)($opt['host'] ?? $cfg->host));
		$this->cfgUser = \trim((string)($opt['user'] ?? $cfg->user));
		$this->cfgName = \trim((string)($opt['name'] ?? $cfg->name));

		if ($this->cfgHost === '') {
			throw new DbConnectException('DB config: "host" is required.');
		}
		if ($this->cfgUser === '') {
			throw new DbConnectException('DB config: "user" is required.');
		}
		if ($this->cfgName === '') {
			throw new DbConnectException('DB config: "name" is required.');
		}

		$charset = \trim((string)($opt['charset'] ?? $cfg->charset));
		if ($charset === '') {
			throw new DbConnectException('DB config: "charset" must not be empty.');
		}
		$this->cfgCharset = $charset;

		$rawPort = $opt['port'] ?? $cfg->port;
		if (\is_int($rawPort)) {
			$port = $rawPort;
		} elseif (\is_string($rawPort) && \ctype_digit($rawPort) && $rawPort !== '') {
			$port = (int)$rawPort;
		} else {
			throw new DbConnectException(
				'DB config: "port" must be a positive integer, got: ' . \get_debug_type($rawPort) . '.'
			);
		}
		if ($port < 1 || $port > 65535) {
			throw new DbConnectException(
				'DB config: "port" must be between 1 and 65535, got: ' . $port . '.'
			);
		}
		$this->cfgPort = $port;

		$socket = $opt['socket'] ?? $cfg->socket;
		$this->cfgSocket = ($socket !== null && \trim((string)$socket) !== '')
			? (string)$socket
			: null;

		$rawPasswordSecret = $opt['password_secret'] ?? $cfg->password_secret;
		if (!\is_string($rawPasswordSecret)) {
			throw new DbConnectException(
				'DB config: "password_secret" must be a non-empty string, got: '
				. \get_debug_type($rawPasswordSecret) . '.'
			);
		}
		$passwordSecret = \trim($rawPasswordSecret);
		if ($passwordSecret === '') {
			throw new DbConnectException('DB config: "password_secret" must not be empty.');
		}
		$this->cfgPasswordSecret = $passwordSecret;

		$rawTimeout = $opt['connect_timeout'] ?? $cfg->connect_timeout;
		if (\is_int($rawTimeout)) {
			$timeout = $rawTimeout;
		} elseif (\is_string($rawTimeout) && \ctype_digit($rawTimeout) && $rawTimeout !== '') {
			$timeout = (int)$rawTimeout;
		} else {
			throw new DbConnectException(
				'DB config: "connect_timeout" must be a positive integer, got: '
				. \get_debug_type($rawTimeout) . '.'
			);
		}
		if ($timeout < 1) {
			throw new DbConnectException(
				'DB config: "connect_timeout" must be >= 1, got: ' . $timeout . '.'
			);
		}
		$this->cfgConnectTimeout = $timeout;

		$sqlMode = $opt['sql_mode'] ?? $cfg->sql_mode;
		$this->cfgSqlMode = ($sqlMode !== null && \trim((string)$sqlMode) !== '')
			? (string)$sqlMode
			: null;

		$tz = $opt['timezone'] ?? $cfg->timezone;
		$this->cfgTimezone = ($tz !== null && \trim((string)$tz) !== '')
			? (string)$tz
			: null;

		$rawLimit = $opt['statement_cache_limit'] ?? $cfg->statement_cache_limit;
		if (\is_int($rawLimit)) {
			$cacheLimit = $rawLimit;
		} elseif (\is_string($rawLimit) && \ctype_digit($rawLimit) && $rawLimit !== '') {
			$cacheLimit = (int)$rawLimit;
		} else {
			throw new DbConnectException(
				'DB config: "statement_cache_limit" must be an integer >= 0, got: '
				. \get_debug_type($rawLimit) . '.'
			);
		}
		if ($cacheLimit < 0) {
			throw new DbConnectException(
				'DB config: "statement_cache_limit" must be >= 0, got: ' . $cacheLimit . '.'
			);
		}
		$this->statementCacheLimit = $cacheLimit;

		// Global MySQLi reporting mode - affects all mysqli calls in this process.
		// This is a driver-level flag, not per-connection. Set once per request.
		\mysqli_report(\MYSQLI_REPORT_ERROR | \MYSQLI_REPORT_STRICT);
	}




	// ----------------------------------------------------------------
	// Read API
	// ----------------------------------------------------------------

	/**
	 * Return the first column of the first row of a SELECT.
	 *
	 * Typical usage:
	 *   $count = (int)$this->app->db->fetchValue('SELECT COUNT(*) FROM users WHERE active = ?', [1]);
	 *
	 * @param  string  $sql  SELECT with ? placeholders; trimmed.
	 * @param  list<scalar|null>  $params  Values for the placeholders, in order; a bool is bound as 0 or 1.
	 * @return mixed  Value as mysqlnd returns it; null when there is no row or the value is NULL.
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When the SQL is empty, the statement fails or it produces no result set.
	 */
	public function fetchValue(string $sql, array $params = []): mixed {
		$result = $this->select($sql, $params);
		try {
			$row = $result->fetch_row();
			return ($row !== null) ? ($row[0] ?? null) : null;
		} finally {
			$result->free();
		}
	}


	/**
	 * Return the first row of a SELECT.
	 *
	 * Typical usage:
	 *   $user = $this->app->db->fetchRow('SELECT id, email FROM users WHERE id = ?', [$id]);
	 *
	 * @param  string  $sql  SELECT with ? placeholders; trimmed.
	 * @param  list<scalar|null>  $params  Values for the placeholders, in order; a bool is bound as 0 or 1.
	 * @return array<string,mixed>|null  Values by column name, as mysqlnd returns them; null when there is no row.
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When the SQL is empty, the statement fails or it produces no result set.
	 */
	public function fetchRow(string $sql, array $params = []): ?array {
		$result = $this->select($sql, $params);
		try {
			$row = $result->fetch_assoc();
			return $row ?: null;
		} finally {
			$result->free();
		}
	}


	/**
	 * Return every row of a SELECT.
	 *
	 * Typical usage:
	 *   $rows = $this->app->db->fetchAll('SELECT id, title FROM articles WHERE author_id = ?', [$authorId]);
	 *
	 * @param  string  $sql  SELECT with ? placeholders; trimmed.
	 * @param  list<scalar|null>  $params  Values for the placeholders, in order; a bool is bound as 0 or 1.
	 * @return list<array<string,mixed>>  Rows of values by column name, as mysqlnd returns them; [] when there is none.
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When the SQL is empty, the statement fails or it produces no result set.
	 */
	public function fetchAll(string $sql, array $params = []): array {
		$result = $this->select($sql, $params);
		try {
			return $result->fetch_all(\MYSQLI_ASSOC);
		} finally {
			$result->free();
		}
	}


	/**
	 * Return the number of rows of a result set, or of a SELECT run for the purpose.
	 *
	 * Behavior:
	 * - A result set is counted without a query. A SELECT is run, counted and freed.
	 *
	 * Notes:
	 * - Counting a SELECT transfers all its rows; SELECT COUNT(*) through fetchValue() lets the
	 *   server count instead.
	 *
	 * @param  \mysqli_result|string  $resultOrSql  Buffered result set, or SELECT with ? placeholders.
	 * @param  list<scalar|null>  $params  Values for the placeholders when $resultOrSql is SQL.
	 * @return int
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When SQL is given and the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When SQL is given and it is empty, fails or produces no result set.
	 */
	public function countRows(\mysqli_result|string $resultOrSql, array $params = []): int {
		if ($resultOrSql instanceof \mysqli_result) {
			return $resultOrSql->num_rows;
		}

		$result = $this->select($resultOrSql, $params);
		try {
			return $result->num_rows;
		} finally {
			$result->free();
		}
	}


	/**
	 * Tell whether a table has a row that matches a condition.
	 *
	 * Behavior:
	 * - The server stops at the first matching row.
	 *
	 * Typical usage:
	 *   $taken = $this->app->db->exists('users', 'email = ?', [$email]);
	 *
	 * @param  string  $table  Table name, or schema.table; letters, digits, _ and $ in each part.
	 * @param  string  $where  Condition without the WHERE keyword, with ? placeholders; trimmed, not empty. Values belong in $params, never in this text.
	 * @param  list<scalar|null>  $params  Values for the placeholders of $where.
	 * @return bool
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When $where is empty, the table name is invalid or the statement fails.
	 */
	public function exists(string $table, string $where, array $params = []): bool {
		$where = \trim($where);
		if ($where === '') {
			throw new DbQueryException('exists(): WHERE clause must not be empty.');
		}

		$sql    = 'SELECT 1 FROM ' . $this->quoteIdentifierPath($table) . ' WHERE ' . $where . ' LIMIT 1';
		$result = $this->select($sql, $params);
		try {
			return ($result->num_rows > 0);
		} finally {
			$result->free();
		}
	}


	/**
	 * Run a SELECT and return its buffered result set.
	 *
	 * Behavior:
	 * - The caller owns the result set and frees it. It stays readable after the statement is
	 *   closed.
	 *
	 * Notes:
	 * - Requires mysqlnd. selectNoMysqlnd() works without it.
	 *
	 * Typical usage:
	 *   $result = $this->app->db->select('SELECT id FROM users WHERE active = ?', [1]);
	 *   try {
	 *       while ($row = $result->fetch_assoc()) { ... }
	 *   } finally {
	 *       $result->free();
	 *   }
	 *
	 * @param  string  $sql  SELECT with ? placeholders; trimmed.
	 * @param  list<scalar|null>  $params  Values for the placeholders, in order; a bool is bound as 0 or 1.
	 * @return \mysqli_result
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When the SQL is empty, the statement fails or it produces no result set.
	 */
	public function select(string $sql, array $params = []): \mysqli_result {
		$sql = \trim($sql);
		if ($sql === '') {
			throw new DbQueryException('select(): SQL must not be empty.');
		}

		$this->queryCount++;
		$statement = $this->getPreparedStatement($sql);

		try {
			// Discard any pending result from a prior execution of this statement.
			// No-op on a fresh statement; essential when the statement is reused from cache.
			$this->freeStatementResultIfPossible($statement);

			$this->bindParams($statement, $params);
			$statement->execute();

			$result = $statement->get_result();
			if (!$result instanceof \mysqli_result) {
				throw new DbQueryException('select(): Expected a result set but none was produced. SQL: ' . $sql);
			}

			return $result;
		} catch (\mysqli_sql_exception $e) {
			throw $this->createQueryException($e->getMessage(), $sql, $params, (int)$e->getCode(), $e);
		} finally {
			// Close only when caching is disabled. With caching enabled, getPreparedStatement()
			// always stores the statement in the cache - closing it here would corrupt the cache.
			// With mysqlnd, the result is buffered and independent of the statement lifetime.
			if ($this->statementCacheLimit === 0) {
				try { $statement->close(); } catch (\Throwable) {}
			}
		}
	}


	/**
	 * Run a SELECT without mysqlnd and yield its rows one at a time.
	 *
	 * Behavior:
	 * - Nothing runs until the generator is first advanced, so the exceptions come from the
	 *   iteration, not from the call.
	 * - Uses a statement of its own, never cached, that is closed when the generator finishes
	 *   or is destroyed.
	 *
	 * Notes:
	 * - The values of a yielded row are references to the bind buffer, which the next fetch
	 *   overwrites. Use each row before advancing; to keep rows, copy their values first, such
	 *   as with array_map().
	 * - Do not run two of these generators on one connection at the same time.
	 *
	 * Typical usage:
	 *   foreach ($this->app->db->selectNoMysqlnd('SELECT id, email FROM users') as $row) { ... }
	 *
	 * @param  string  $sql  SELECT with ? placeholders; trimmed.
	 * @param  list<scalar|null>  $params  Values for the placeholders, in order; a bool is bound as 0 or 1.
	 * @return \Generator<int,array<string,mixed>>  Rows of values by column name.
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When the SQL is empty, the statement fails or it produces no result set.
	 */
	public function selectNoMysqlnd(string $sql, array $params = []): \Generator {
		$sql = \trim($sql);
		if ($sql === '') {
			throw new DbQueryException('selectNoMysqlnd(): SQL must not be empty.');
		}

		$this->queryCount++;
		$statement = $this->prepareUncached($sql);

		try {
			$this->bindParams($statement, $params);
			$statement->execute();

			$meta = $statement->result_metadata();
			if (!$meta instanceof \mysqli_result) {
				throw new DbQueryException(
					'selectNoMysqlnd(): Expected a result set but none was produced. SQL: ' . $sql
				);
			}

			$fields = $meta->fetch_fields();
			$meta->free();

			// Build the bind buffer. $row values are references (required by bind_result).
			// See the Notes of this method for what that means for the yielded rows.
			$row  = [];
			$bind = [];
			foreach ($fields as $f) {
				$row[$f->name] = null;
				$bind[]        = &$row[$f->name];
			}

			if ($bind !== []) {
				$statement->bind_result(...$bind);
			}

			while ($statement->fetch()) {
				// Array assignment preserves the reference chain from bind_result().
				// Safe for standard foreach; keeping rows needs a copy, see the Notes of this method.
				$detached = $row;
				yield $detached;
			}
		} catch (\mysqli_sql_exception $e) {
			throw $this->createQueryException($e->getMessage(), $sql, $params, (int)$e->getCode(), $e);
		} finally {
			try { $statement->free_result(); } catch (\Throwable) {}
			try { $statement->close(); }       catch (\Throwable) {}
		}
	}




	// ----------------------------------------------------------------
	// Write API
	// ----------------------------------------------------------------

	/**
	 * Run a statement and return the number of rows it affected.
	 *
	 * Notes:
	 * - For an UPDATE, the server counts changed rows: A matching row that already holds the
	 *   new values is not counted.
	 *
	 * Typical usage:
	 *   $ended = $this->app->db->execute('UPDATE sessions SET ended_at = NOW() WHERE user_id = ?', [$userId]);
	 *
	 * @param  string  $sql  SQL with ? placeholders; trimmed.
	 * @param  list<scalar|null>  $params  Values for the placeholders, in order; a bool is bound as 0 or 1.
	 * @return int
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When the SQL is empty or the statement fails.
	 */
	public function execute(string $sql, array $params = []): int {
		$sql = \trim($sql);
		if ($sql === '') {
			throw new DbQueryException('execute(): SQL must not be empty.');
		}

		$this->queryCount++;
		$statement = $this->getPreparedStatement($sql);

		try {
			$this->freeStatementResultIfPossible($statement);
			$this->bindParams($statement, $params);
			$statement->execute();
			// The connection reports the statement that just ran, so its affected_rows equals the statement's.
			return $this->getConnection()->affected_rows;
		} catch (\mysqli_sql_exception $e) {
			throw $this->createQueryException($e->getMessage(), $sql, $params, (int)$e->getCode(), $e);
		} finally {
			if ($this->statementCacheLimit === 0) {
				try { $statement->close(); } catch (\Throwable) {}
			}
		}
	}


	/**
	 * Run one statement once per set of values, and return the number of rows they affected together.
	 *
	 * Behavior:
	 * - Prepares the statement once and runs it for each set, in order.
	 * - An empty list runs nothing and returns 0, whatever the SQL.
	 * - Opens no transaction: The runs before a failing one stay applied unless the caller runs
	 *   the call inside transaction().
	 *
	 * Notes:
	 * - Inside a transaction, a large batch saves a commit per run.
	 *
	 * @param  string  $sql  SQL with ? placeholders; trimmed.
	 * @param  array<array-key,list<scalar|null>>  $paramSets  One list of placeholder values per run.
	 * @return int
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When the SQL is empty or a run fails.
	 */
	public function executeMany(string $sql, array $paramSets): int {
		if ($paramSets === []) {
			return 0;
		}

		$sql = \trim($sql);
		if ($sql === '') {
			throw new DbQueryException('executeMany(): SQL must not be empty.');
		}

		$this->queryCount += \count($paramSets);
		$statement = $this->getPreparedStatement($sql);
		$conn      = $this->getConnection();
		$total     = 0;

		try {
			foreach ($paramSets as $params) {
				$this->freeStatementResultIfPossible($statement);
				$this->bindParams($statement, $params);
				$statement->execute();
				$total += $conn->affected_rows;
			}
		} catch (\mysqli_sql_exception $e) {
			throw $this->createQueryException($e->getMessage(), $sql, [], (int)$e->getCode(), $e);
		} finally {
			if ($this->statementCacheLimit === 0) {
				try { $statement->close(); } catch (\Throwable) {}
			}
		}

		return $total;
	}


	/**
	 * Insert one row and return its AUTO_INCREMENT id.
	 *
	 * Typical usage:
	 *   $id = $this->app->db->insert('users', ['email' => $email, 'active' => 1]);
	 *
	 * @param  string  $table  Table name, or schema.table; letters, digits, _ and $ in each part.
	 * @param  array<string,scalar|null>  $data  Values by column name; letters, digits, _ and $ in each name.
	 * @return int  0 when the table has no AUTO_INCREMENT column.
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When $data is empty, a name is invalid or the statement fails.
	 */
	public function insert(string $table, array $data): int {
		if ($data === []) {
			throw new DbQueryException('insert(): data array must not be empty.');
		}

		$cols   = [];
		$phs    = [];
		$params = [];

		foreach ($data as $col => $value) {
			$cols[]   = $this->quoteIdentifier((string)$col);
			$phs[]    = '?';
			$params[] = $value;
		}

		$sql = 'INSERT INTO ' . $this->quoteIdentifierPath($table)
			. ' (' . \implode(',', $cols) . ')'
			. ' VALUES (' . \implode(',', $phs) . ')';

		$this->execute($sql, $params);
		return $this->lastInsertId();
	}


	/**
	 * Insert many rows and return the number of rows inserted.
	 *
	 * Behavior:
	 * - Every row has exactly the columns of the first row, in any key order.
	 * - Up to BATCH_ROW_LIMIT rows within an estimated BATCH_BYTE_LIMIT bytes go in one
	 *   multi-row INSERT.
	 * - A larger payload goes in chunks of BATCH_CHUNK_SIZE rows inside a transaction of its
	 *   own, so it is inserted completely or not at all. That path throws while a transaction
	 *   opened through this service is active, instead of nesting.
	 *
	 * Typical usage:
	 *   $this->app->db->insertBatch('order_lines', $lines);
	 *
	 * @param  string  $table  Table name, or schema.table; letters, digits, _ and $ in each part.
	 * @param  array<array-key,array<string,scalar|null>>  $rows  Rows of values by column name.
	 * @return int
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When $rows or its first row is empty, a row is not an array or has other columns than the first, a name is invalid, a large payload meets an active transaction, or a statement fails.
	 */
	public function insertBatch(string $table, array $rows): int {
		if ($rows === []) {
			throw new DbQueryException('insertBatch(): rows array must not be empty.');
		}

		$firstRow = \reset($rows);
		if (!\is_array($firstRow) || $firstRow === []) {
			throw new DbQueryException('insertBatch(): first row must be a non-empty associative array.');
		}

		$columns       = \array_keys($firstRow);
		$expectedCount = \count($columns);
		// Pre-build the expected key set once; reused for every row check below.
		$expectedKeys  = \array_fill_keys($columns, true);

		// Validate all rows: O(1) count guard first, then one array_diff_key().
		// Rationale: if count matches AND no expected key is missing from $row,
		// then $row cannot contain extra keys - one diff is sufficient for both checks.
		foreach ($rows as $idx => $row) {
			if (!\is_array($row)) {
				throw new DbQueryException('insertBatch(): row #' . $idx . ' is not an array.');
			}
			if (\count($row) !== $expectedCount || \array_diff_key($expectedKeys, $row) !== []) {
				throw new DbQueryException(
					'insertBatch(): row #' . $idx . ' has a different column set than the first row.'
				);
			}
		}

		// Quote column identifiers once, shared by both strategies.
		$quotedCols = [];
		foreach ($columns as $col) {
			$quotedCols[] = $this->quoteIdentifier((string)$col);
		}
		$columnList = \implode(',', $quotedCols);

		// Coarse byte estimate to choose strategy. Intentionally conservative.
		$estimatedBytes = 0;
		foreach ($rows as $row) {
			foreach ($columns as $col) {
				$v = $row[$col] ?? null;
				$estimatedBytes += \is_string($v) ? \strlen($v) : 8;
			}
			$estimatedBytes += 16; // per-row protocol overhead
		}

		// Strategy 1: single multi-row INSERT (one round trip).
		if (\count($rows) <= self::BATCH_ROW_LIMIT && $estimatedBytes <= self::BATCH_BYTE_LIMIT) {
			$rowPh     = '(' . \implode(',', \array_fill(0, $expectedCount, '?')) . ')';
			$allRowPhs = \implode(', ', \array_fill(0, \count($rows), $rowPh));
			$params    = [];

			foreach ($rows as $row) {
				foreach ($columns as $col) {
					$params[] = $row[$col] ?? null;
				}
			}

			$sql = 'INSERT INTO ' . $this->quoteIdentifierPath($table)
				. ' (' . $columnList . ') VALUES ' . $allRowPhs;

			return $this->execute($sql, $params);
		}

		// Strategy 2: chunked fallback in one transaction.
		// Guard against nesting: InnoDB does not support true nested transactions.
		// An implicit BEGIN inside an active transaction would commit the outer one silently.
		if ($this->inTransaction) {
			throw new DbQueryException(
				'insertBatch(): the chunked fallback cannot start a transaction while one is already'
				. ' active. Either keep the payload within the single-INSERT threshold, or manage'
				. ' the transaction yourself and use executeMany() directly.'
			);
		}
		$sqlSingle = 'INSERT INTO ' . $this->quoteIdentifierPath($table)
			. ' (' . $columnList . ') VALUES (' . \implode(',', \array_fill(0, $expectedCount, '?')) . ')';

		return (int)$this->transaction(function(self $db) use ($rows, $columns, $sqlSingle): int {
			$total    = 0;
			$rowCount = \count($rows);

			for ($offset = 0; $offset < $rowCount; $offset += self::BATCH_CHUNK_SIZE) {
				$chunk     = \array_slice($rows, $offset, self::BATCH_CHUNK_SIZE);
				$paramSets = [];

				foreach ($chunk as $row) {
					$params = [];
					foreach ($columns as $col) {
						$params[] = $row[$col] ?? null;
					}
					$paramSets[] = $params;
				}

				$total += $db->executeMany($sqlSingle, $paramSets);
			}

			return $total;
		});
	}


	/**
	 * Update the rows of a table that match a condition, and return the number of rows changed.
	 *
	 * Notes:
	 * - The server counts changed rows: A matching row that already holds the new values is
	 *   not counted.
	 *
	 * Typical usage:
	 *   $this->app->db->update('users', ['active' => 0], 'id = ?', [$id]);
	 *
	 * @param  string  $table  Table name, or schema.table; letters, digits, _ and $ in each part.
	 * @param  array<string,scalar|null>  $data  New values by column name; letters, digits, _ and $ in each name.
	 * @param  string  $where  Condition without the WHERE keyword, with ? placeholders; trimmed, not empty. Values belong in $params, never in this text.
	 * @param  list<scalar|null>  $params  Values for the placeholders of $where; bound after the values of $data.
	 * @return int
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When $data or $where is empty, a name is invalid or the statement fails.
	 */
	public function update(string $table, array $data, string $where, array $params = []): int {
		if ($data === []) {
			throw new DbQueryException('update(): data array must not be empty.');
		}

		$where = \trim($where);
		if ($where === '') {
			throw new DbQueryException('update(): WHERE clause must not be empty.');
		}

		$set = [];
		foreach (\array_keys($data) as $col) {
			$set[] = $this->quoteIdentifier((string)$col) . ' = ?';
		}

		$sql       = 'UPDATE ' . $this->quoteIdentifierPath($table)
			. ' SET ' . \implode(',', $set)
			. ' WHERE ' . $where;
		$allParams = \array_merge(\array_values($data), $params);

		return $this->execute($sql, $allParams);
	}


	/**
	 * Delete the rows of a table that match a condition, and return how many were deleted.
	 *
	 * Typical usage:
	 *   $this->app->db->delete('sessions', 'expires_at < NOW()');
	 *
	 * @param  string  $table  Table name, or schema.table; letters, digits, _ and $ in each part.
	 * @param  string  $where  Condition without the WHERE keyword, with ? placeholders; trimmed, not empty. Values belong in $params, never in this text.
	 * @param  list<scalar|null>  $params  Values for the placeholders of $where.
	 * @return int
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When $where is empty, the table name is invalid or the statement fails.
	 */
	public function delete(string $table, string $where, array $params = []): int {
		$where = \trim($where);
		if ($where === '') {
			throw new DbQueryException('delete(): WHERE clause must not be empty.');
		}

		$sql = 'DELETE FROM ' . $this->quoteIdentifierPath($table) . ' WHERE ' . $where;
		return $this->execute($sql, $params);
	}




	// ----------------------------------------------------------------
	// Raw query escape hatches
	// ----------------------------------------------------------------

	/**
	 * Run one SQL statement without parameter binding.
	 *
	 * For DDL, administrative statements and debugging. The SQL runs as given, so it must never
	 * contain input.
	 *
	 * Behavior:
	 * - Returns the result set of a statement that produces one, which the caller frees, and
	 *   true otherwise.
	 *
	 * @param  string  $sql  Complete SQL; trimmed.
	 * @return \mysqli_result|bool
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When the SQL is empty or the statement fails.
	 */
	public function queryRaw(string $sql): \mysqli_result|bool {
		$sql = \trim($sql);
		if ($sql === '') {
			throw new DbQueryException('queryRaw(): SQL must not be empty.');
		}

		$this->queryCount++;

		try {
			return $this->getConnection()->query($sql);
		} catch (\mysqli_sql_exception $e) {
			throw $this->createQueryException($e->getMessage(), $sql, [], (int)$e->getCode(), $e);
		}
	}


	/**
	 * Run several SQL statements, separated by semicolons, without parameter binding.
	 *
	 * For migrations, DDL batches and seeding. The SQL runs as given, so it must never contain
	 * input.
	 *
	 * Behavior:
	 * - Returns one entry per statement, in order: the result set of a statement that produces
	 *   one, which the caller frees, and true otherwise.
	 * - Empty SQL returns [] without connecting.
	 * - When a statement fails, the statements before it are not undone; the result sets
	 *   collected so far are freed and the pending results drained, so the connection stays
	 *   usable.
	 *
	 * @param  string  $sql  Statements separated by semicolons; trimmed.
	 * @return list<\mysqli_result|true>
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When a statement fails.
	 */
	public function queryRawMulti(string $sql): array {
		$sql = \trim($sql);
		if ($sql === '') {
			return [];
		}

		$conn    = $this->getConnection();
		$results = [];

		try {
			if (!$conn->multi_query($sql)) {
				throw new DbQueryException(
					'queryRawMulti(): dispatch failed: ' . $conn->error,
					$conn->errno
				);
			}

			do {
				$result    = $conn->store_result();
				$results[] = ($result instanceof \mysqli_result) ? $result : true;
				$this->queryCount++;
			} while ($conn->more_results() && $conn->next_result());

		} catch (\mysqli_sql_exception $e) {
			// Free any results collected before the failure - caller never receives them.
			foreach ($results as $r) {
				if ($r instanceof \mysqli_result) {
					try { $r->free(); } catch (\Throwable) {}
				}
			}
			// Drain remaining pending results best-effort to leave the connection in a
			// usable state. next_result() or store_result() may themselves throw; absorb.
			while (true) {
				try {
					if (!$conn->more_results()) { break; }
					$conn->next_result();
					$pending = $conn->store_result();
					if ($pending instanceof \mysqli_result) {
						try { $pending->free(); } catch (\Throwable) {}
					}
				} catch (\Throwable) {
					break;
				}
			}
			throw $this->createQueryException($e->getMessage(), $sql, [], (int)$e->getCode(), $e);
		}

		return $results;
	}




	// ----------------------------------------------------------------
	// Transactions
	// ----------------------------------------------------------------

	/**
	 * Open a transaction, optionally with an isolation level for this transaction only.
	 *
	 * Behavior:
	 * - Without $isolation, the transaction runs at the session's isolation level.
	 * - With $isolation, the level applies to this transaction only: The session level stays
	 *   unchanged, and later transactions run at it again.
	 * - Opens the connection when none is open.
	 * - When the level cannot be set, no transaction is opened.
	 *
	 * Notes:
	 * - transaction() opens, commits and rolls back in one call, and is the safer choice.
	 *
	 * @param  TransactionIsolation|null  $isolation  Isolation level of this transaction, or null for the session's.
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When a transaction opened through this service is active, or the server refuses the level or the transaction.
	 */
	public function beginTransaction(?TransactionIsolation $isolation = null): void {
		if ($this->inTransaction) {
			throw new DbQueryException('Transaction already active. Nested transactions are not supported.');
		}

		try {
			$conn = $this->getConnection();
			if ($isolation !== null) {
				// Bind the level to the next transaction, i.e. the one opened right below.
				$this->applyNextTransactionIsolation($conn, $isolation);
			}
			$conn->begin_transaction();
			$this->inTransaction = true;
		} catch (\mysqli_sql_exception $e) {
			throw new DbQueryException($e->getMessage(), (int)$e->getCode(), $e);
		}
	}


	/**
	 * Commit the current transaction.
	 *
	 * Behavior:
	 * - Counts no transaction as active afterwards, also when the commit fails.
	 * - Opens no connection: Without one there is no transaction to commit.
	 *
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When no connection is open or the commit fails.
	 */
	public function commit(): void {
		try {
			$this->requireConnection()->commit();
		} catch (\mysqli_sql_exception $e) {
			throw new DbQueryException($e->getMessage(), (int)$e->getCode(), $e);
		} finally {
			$this->inTransaction = false;
		}
	}


	/**
	 * Roll back the current transaction.
	 *
	 * Behavior:
	 * - Counts no transaction as active afterwards, also when the rollback fails.
	 * - Opens no connection: Without one there is no transaction to roll back.
	 *
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When no connection is open or the rollback fails.
	 */
	public function rollback(): void {
		try {
			$this->requireConnection()->rollback();
		} catch (\mysqli_sql_exception $e) {
			throw new DbQueryException($e->getMessage(), (int)$e->getCode(), $e);
		} finally {
			$this->inTransaction = false;
		}
	}


	/**
	 * Run a callback inside a transaction: Commit when it returns, roll back when it throws.
	 *
	 * Behavior:
	 * - Passes this service to the callback and returns what the callback returns.
	 * - When the callback or the commit throws and the rollback succeeds, rethrows that
	 *   exception unchanged.
	 * - When the rollback fails too, throws a DbQueryException that names both failures, with
	 *   the first exception as previous, so neither is lost.
	 *
	 * Typical usage:
	 *   $orderId = $this->app->db->transaction(function (Db $db) use ($order, $lines): int {
	 *       $id = $db->insert('orders', $order);
	 *       $db->insertBatch('order_lines', $lines);
	 *       return $id;
	 *   });
	 *
	 * @param  callable(Db): mixed  $callback  Work to run inside the transaction.
	 * @param  TransactionIsolation|null  $isolation  Isolation level of this transaction, or null for the session's.
	 * @return mixed  What the callback returns.
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When the transaction cannot be opened, or the rollback after a failure fails.
	 * @throws \Throwable  The exception of the callback or the commit, after a successful rollback.
	 * @see \CitOmni\Infrastructure\Service\Db::beginTransaction()  How the isolation level applies.
	 */
	public function transaction(callable $callback, ?TransactionIsolation $isolation = null): mixed {
		$this->beginTransaction($isolation);
		try {
			$result = $callback($this);
			$this->commit();
			return $result;
		} catch (\Throwable $callbackException) {
			try {
				$this->rollback();
			} catch (\Throwable $rollbackException) {
				// Both the callback and the rollback failed.
				// Chain original exception as $previous so neither failure is lost.
				throw new DbQueryException(
					'Transaction rollback failed after callback exception ('
						. $callbackException->getMessage() . '): '
						. $rollbackException->getMessage(),
					(int)$rollbackException->getCode(),
					$callbackException
				);
			}
			throw $callbackException;
		}
	}


	/**
	 * Run a callback inside a transaction, exactly as transaction() does.
	 *
	 * Kept for code written for LiteMySQLi's method of the same name.
	 *
	 * @param  callable(Db): mixed  $callback  Work to run inside the transaction.
	 * @param  TransactionIsolation|null  $isolation  Isolation level of this transaction, or null for the session's.
	 * @return mixed  What the callback returns.
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When the transaction cannot be opened, or the rollback after a failure fails.
	 * @throws \Throwable  The exception of the callback or the commit, after a successful rollback.
	 * @see \CitOmni\Infrastructure\Service\Db::transaction()  The full contract.
	 */
	public function easyTransaction(callable $callback, ?TransactionIsolation $isolation = null): mixed {
		return $this->transaction($callback, $isolation);
	}


	/**
	 * Set the isolation level of the next transaction of the session only.
	 *
	 * Behavior:
	 * - Sends SET TRANSACTION ISOLATION LEVEL without SESSION or GLOBAL, which on MySQL
	 *   8.0.16+ and MariaDB 10.6+ applies to the next transaction only and leaves the session
	 *   level unchanged. The statement causes no implicit commit on either.
	 * - Does not count as a query in countQueries().
	 *
	 * Notes:
	 * - Must run while no transaction is active and directly before the transaction opens, so
	 *   that the level binds to it.
	 * - The level text is fixed for each case of the enum, never caller text, so it is safe
	 *   to embed.
	 *
	 * @param  \mysqli  $conn  Open connection.
	 * @param  TransactionIsolation  $isolation  Level of the next transaction.
	 * @return void
	 * @throws \mysqli_sql_exception  When the server refuses the statement.
	 */
	private function applyNextTransactionIsolation(\mysqli $conn, TransactionIsolation $isolation): void {
		$level = match ($isolation) {
			TransactionIsolation::ReadUncommitted => 'READ UNCOMMITTED',
			TransactionIsolation::ReadCommitted   => 'READ COMMITTED',
			TransactionIsolation::RepeatableRead  => 'REPEATABLE READ',
			TransactionIsolation::Serializable    => 'SERIALIZABLE',
		};
		$conn->query('SET TRANSACTION ISOLATION LEVEL ' . $level);
	}




	// ----------------------------------------------------------------
	// Connection recovery
	// ----------------------------------------------------------------

	/**
	 * Tell whether the open connection answers, without opening one.
	 *
	 * Behavior:
	 * - Returns false when no connection is open, and when SELECT 1 fails for any reason,
	 *   such as a connection the server has closed.
	 * - Does not count as a query in countQueries().
	 *
	 * @return bool
	 */
	public function checkConnection(): bool {
		if (!$this->connection instanceof \mysqli) {
			return false;
		}

		try {
			$result = $this->connection->query('SELECT 1');

			// Non-STRICT mysqli_report mode returns false instead of throwing.
			if ($result === false) {
				return false;
			}

			if ($result instanceof \mysqli_result) {
				$result->free();
			}

			return true;
		} catch (\Throwable) {
			return false;
		}
	}


	/**
	 * Make sure an open connection answers, opening a new one when it does not.
	 *
	 * For a long-running CLI process before it resumes database work after idle time,
	 * external IO or a pause for a person, when the server may have closed the connection.
	 *
	 * Behavior:
	 * - Keeps a connection that answers; otherwise opens one as reconnect() does.
	 *
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When the connection does not answer while a transaction opened through this service is active.
	 * @see \CitOmni\Infrastructure\Service\Db::reconnect()  What reopening discards.
	 */
	public function ensureConnection(): void {
		if ($this->checkConnection()) {
			return;
		}

		$this->reconnect();
	}


	/**
	 * Close the connection and open a new one with the same settings.
	 *
	 * Behavior:
	 * - Closes the cached statements and the old connection, ignoring failures.
	 * - Refuses while a transaction opened through this service is active, because the
	 *   transaction would be lost.
	 *
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the new connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When a transaction opened through this service is active.
	 */
	public function reconnect(): void {
		if ($this->inTransaction) {
			throw new DbQueryException(
				'Refusing to reconnect inside an active transaction; transaction state would be lost.'
			);
		}

		$this->close();
		$this->getConnection();
	}




	// ----------------------------------------------------------------
	// Meta
	// ----------------------------------------------------------------

	/**
	 * Return the AUTO_INCREMENT id that the last statement on the connection generated.
	 *
	 * @return int  0 when no connection is open or the last statement generated no id.
	 */
	public function lastInsertId(): int {
		return $this->connection !== null ? $this->connection->insert_id : 0;
	}


	/**
	 * Return the number of rows that the last statement on the connection affected.
	 *
	 * @return int  0 when no connection is open.
	 */
	public function affectedRows(): int {
		return $this->connection !== null ? $this->connection->affected_rows : 0;
	}


	/**
	 * Return the number of statements run since construction or the last reset.
	 *
	 * Behavior:
	 * - Counts the statements of the caller. Session settings, transaction control and
	 *   connection checks are not counted.
	 *
	 * @param  bool  $reset  Set the count to 0 after reading it.
	 * @return int
	 */
	public function countQueries(bool $reset = false): int {
		$count = $this->queryCount;
		if ($reset) {
			$this->queryCount = 0;
		}
		return $count;
	}


	/**
	 * Describe the service for var_dump() without the password.
	 *
	 * @return array<string,mixed>  Connection settings, including the user name and the name of the password secret, and the state of connection, transaction, statement cache and query count.
	 */
	public function __debugInfo(): array {
		return [
			'cfgHost' => $this->cfgHost,
			'cfgUser' => $this->cfgUser,
			'cfgName' => $this->cfgName,
			'cfgCharset' => $this->cfgCharset,
			'cfgPort' => $this->cfgPort,
			'cfgSocket' => $this->cfgSocket,
			'cfgConnectTimeout' => $this->cfgConnectTimeout,
			'cfgPasswordSecret' => $this->cfgPasswordSecret,
			'cfgSqlMode' => $this->cfgSqlMode,
			'cfgTimezone' => $this->cfgTimezone,
			'hasConnection' => $this->connection instanceof \mysqli,
			'inTransaction' => $this->inTransaction,
			'statementCacheLimit' => $this->statementCacheLimit,
			'statementCacheCount' => \count($this->statementCache),
			'queryCount' => $this->queryCount,
		];
	}


	/**
	 * Return the error message of the last call on the connection.
	 *
	 * @return string|null  Null when no connection is open or the last call had no error.
	 */
	public function getLastError(): ?string {
		if ($this->connection === null) {
			return null;
		}
		$err = $this->connection->error;
		return ($err !== '') ? $err : null;
	}


	/**
	 * Return the server error number of the last call on the connection.
	 *
	 * @return int  0 when no connection is open or the last call had no error.
	 */
	public function getLastErrorCode(): int {
		return $this->connection !== null ? $this->connection->errno : 0;
	}




	// ----------------------------------------------------------------
	// Cache management
	// ----------------------------------------------------------------

	/**
	 * Change how many prepared statements the cache keeps.
	 *
	 * Behavior:
	 * - A limit of 0 or below disables the cache and closes every cached statement.
	 * - A lower limit closes the oldest cached statements until the rest fit.
	 *
	 * @param  int  $limit  Number of statements.
	 * @return void
	 */
	public function setStatementCacheLimit(int $limit): void {
		$this->statementCacheLimit = \max(0, $limit);

		if ($this->statementCacheLimit === 0) {
			$this->clearStatementCache();
			return;
		}

		while (\count($this->statementCache) > $this->statementCacheLimit) {
			$oldestSql = \array_key_first($this->statementCache);
			if ($oldestSql !== null) {
				try { $this->statementCache[$oldestSql]->close(); } catch (\Throwable) {}
				unset($this->statementCache[$oldestSql]);
			}
		}
	}


	/**
	 * Close and forget every cached statement, keeping the connection open.
	 *
	 * Behavior:
	 * - A statement that fails to close does not stop the others.
	 *
	 * @return void
	 */
	public function clearStatementCache(): void {
		foreach ($this->statementCache as $stmt) {
			try { $stmt->close(); } catch (\Throwable) {}
		}
		$this->statementCache = [];
	}


	/**
	 * Close the cached statements and the connection.
	 *
	 * Behavior:
	 * - Ignores failures, and may be called again.
	 * - Counts no transaction as active afterwards; the server rolls back one that was open.
	 * - The next statement opens a new connection.
	 *
	 * @return void
	 */
	public function close(): void {
		$this->clearStatementCache();

		if ($this->connection !== null) {
			try {
				$this->connection->close();
			} catch (\Throwable) {}
			$this->connection = null;
		}

		$this->inTransaction = false;
	}

	/**
	 * Close the statements and the connection when the service is destroyed, never throwing.
	 */
	public function __destruct() {
		try {
			$this->close();
		} catch (\Throwable) {
			// Never allow exceptions from a destructor.
		}
	}




	// ----------------------------------------------------------------
	// Private: Connection lifecycle
	// ----------------------------------------------------------------

	/**
	 * Return the open connection, opening and setting it up on first use.
	 *
	 * Behavior:
	 * - Keeps a new connection only after connect, charset and session settings have all
	 *   succeeded. A connection that fails half way is closed, so the service holds either no
	 *   connection or a fully set-up one.
	 *
	 * @return \mysqli
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the password secret is missing or invalid, or a step of the setup fails.
	 */
	private function getConnection(): \mysqli {
		if ($this->connection instanceof \mysqli) {
			return $this->connection;
		}

		$password = $this->databasePassword();
		$conn = null;
		try {
			$conn = \mysqli_init();
			if (!$conn instanceof \mysqli) {
				throw new DbConnectException('mysqli_init() failed to return a mysqli instance.');
			}
			$conn->options(\MYSQLI_OPT_CONNECT_TIMEOUT, $this->cfgConnectTimeout);
			$conn->real_connect(
				$this->cfgHost,
				$this->cfgUser,
				$password,
				$this->cfgName,
				$this->cfgPort,
				$this->cfgSocket
			);
			$conn->set_charset($this->cfgCharset);
			$this->applySessionSettings($conn);
			// Full initialization succeeded. Own the connection only now.
			$this->connection = $conn;
			return $this->connection;
		} catch (\mysqli_sql_exception $e) {
			if ($conn instanceof \mysqli) {
				try { $conn->close(); } catch (\Throwable) {}
			}
			throw new DbConnectException($e->getMessage(), (int)$e->getCode(), $e);
		} catch (DbConnectException $e) {
			if ($conn instanceof \mysqli) {
				try { $conn->close(); } catch (\Throwable) {}
			}
			throw $e;
		}
	}


	/**
	 * Read the database password from the secrets service.
	 *
	 * @return string  May be ''.
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the secret is missing or the secret store cannot be read, with the secrets exception as previous.
	 */
	private function databasePassword(): string {
		try {
			return $this->app->secrets->get($this->cfgPasswordSecret);
		} catch (\RuntimeException $e) {
			throw new DbConnectException(
				'DB secret "' . $this->cfgPasswordSecret . '" is missing or invalid.',
				0,
				$e
			);
		}
	}


	/**
	 * Return the open connection without opening one.
	 *
	 * @return \mysqli
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When no connection is open.
	 */
	private function requireConnection(): \mysqli {
		if (!$this->connection instanceof \mysqli) {
			throw new DbQueryException(
				'No active database connection. A transaction requires an open connection.'
			);
		}
		return $this->connection;
	}


	/**
	 * Apply the session settings to a new connection.
	 *
	 * Behavior:
	 * - Sets sql_mode when one is configured.
	 * - Sets time_zone to the configured zone; without one, to the UTC offset that PHP's
	 *   active time zone has at connect time.
	 *
	 * Notes:
	 * - The derived offset is fixed for the life of the connection, so a long-running process
	 *   keeps it across a daylight saving change.
	 *
	 * @param  \mysqli  $conn  New connection, not yet kept by the service.
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When a setting fails.
	 */
	private function applySessionSettings(\mysqli $conn): void {

		if ($this->cfgSqlMode !== null) {
			$this->runSessionStatement($conn, 'SET SESSION sql_mode = ?', [$this->cfgSqlMode]);
		}

		if ($this->cfgTimezone !== null) {
			// Explicit config timezone wins. Applied as prepared statement (user-supplied value).
			$this->runSessionStatement($conn, 'SET SESSION time_zone = ?', [$this->cfgTimezone]);
			return;
		}

		// No configured zone: Use the UTC offset of PHP's active time zone, so NOW() matches PHP's clock.
		$phpTz = \date_default_timezone_get();
		if ($phpTz === '') {
			return;
		}

		try {
			$dtz         = new \DateTimeZone($phpTz);
			$offset      = $dtz->getOffset(new \DateTimeImmutable('now', $dtz));
			$hours       = \intdiv($offset, 3600);
			$minutes     = \abs(\intdiv($offset % 3600, 60));
			// sprintf produces a fixed-format value like "+02:00". Not user input; raw query is safe.
			$mysqlOffset = \sprintf('%+03d:%02d', $hours, $minutes);
			$conn->query("SET time_zone = '{$mysqlOffset}'");
		} catch (\Throwable $e) {
			throw new DbConnectException('Failed to apply session time zone: ' . $e->getMessage(), 0, $e);
		}
	}


	/**
	 * Run one session statement with a bound value, on a statement of its own.
	 *
	 * Binds the value because it comes from cfg and is not safe to embed.
	 *
	 * @param  \mysqli  $conn  New connection.
	 * @param  string  $sql  SQL with one ? placeholder.
	 * @param  list<scalar|null>  $params  Value for the placeholder.
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the statement cannot be prepared, bound or run.
	 */
	private function runSessionStatement(\mysqli $conn, string $sql, array $params): void {
		try {
			$stmt = $conn->prepare($sql);
			if (!$stmt instanceof \mysqli_stmt) {
				throw new DbConnectException('Failed to prepare session statement: ' . $sql);
			}
			$this->bindParams($stmt, $params);
			$stmt->execute();
			$stmt->close();
		} catch (DbConnectException $e) {
			throw $e;
		} catch (\Throwable $e) {
			// Includes mysqli_sql_exception from prepare/execute and DbQueryException
			// from bindParams(). All session-init failures are connection-level errors.
			throw new DbConnectException($e->getMessage(), (int)$e->getCode(), $e);
		}
	}




	// ----------------------------------------------------------------
	// Private: Statement cache
	// ----------------------------------------------------------------

	/**
	 * Return a prepared statement for SQL, from the cache when the cache is enabled.
	 *
	 * Behavior:
	 * - With the cache enabled, a new statement is cached; a full cache first closes and drops
	 *   its oldest statement.
	 * - With the cache disabled, every call prepares a new statement, which the caller closes.
	 *
	 * @param  string  $sql  Trimmed SQL; used as the cache key as given.
	 * @return \mysqli_stmt
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When the statement cannot be prepared.
	 */
	private function getPreparedStatement(string $sql): \mysqli_stmt {
		if ($this->statementCacheLimit === 0) {
			return $this->prepareUncached($sql);
		}

		if (isset($this->statementCache[$sql])) {
			return $this->statementCache[$sql];
		}

		if (\count($this->statementCache) >= $this->statementCacheLimit) {
			$oldestSql = \array_key_first($this->statementCache);
			if ($oldestSql !== null) {
				try { $this->statementCache[$oldestSql]->close(); } catch (\Throwable) {}
				unset($this->statementCache[$oldestSql]);
			}
		}

		$stmt = $this->prepareUncached($sql);
		$this->statementCache[$sql] = $stmt;
		return $stmt;
	}


	/**
	 * Prepare a statement without caching it.
	 *
	 * @param  string  $sql  SQL to prepare.
	 * @return \mysqli_stmt
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException  When the connection cannot be opened.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When the statement cannot be prepared.
	 */
	private function prepareUncached(string $sql): \mysqli_stmt {
		try {
			$stmt = $this->getConnection()->prepare($sql);
			if (!$stmt instanceof \mysqli_stmt) {
				// Should not happen under STRICT mode, but guard defensively.
				throw new DbQueryException('prepare() returned false. SQL: ' . $sql);
			}
			return $stmt;
		} catch (\mysqli_sql_exception $e) {
			throw $this->createQueryException($e->getMessage(), $sql, [], (int)$e->getCode(), $e);
		}
	}


	/**
	 * Discard a pending result of a statement before it runs again, ignoring failures.
	 *
	 * @param  \mysqli_stmt  $stmt  Statement that may hold a result from its last run.
	 * @return void
	 */
	private function freeStatementResultIfPossible(\mysqli_stmt $stmt): void {
		try {
			$stmt->free_result();
		} catch (\Throwable) {}
	}




	// ----------------------------------------------------------------
	// Private: Parameter binding
	// ----------------------------------------------------------------

	/**
	 * Bind values to the placeholders of a statement, typed by their PHP type.
	 *
	 * Behavior:
	 * - An int binds as i, a float as d and a bool as i with 0 or 1. Null and every other
	 *   value bind as s; mysqli sends null as SQL NULL whatever the type.
	 * - No values binds nothing.
	 *
	 * @param  \mysqli_stmt  $stmt  Prepared statement.
	 * @param  list<scalar|null>  $params  Values for the placeholders, in order.
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When mysqli reports the binding as failed.
	 */
	private function bindParams(\mysqli_stmt $stmt, array $params): void {
		if ($params === []) {
			return;
		}

		$types = '';
		$refs  = [];

		foreach ($params as &$param) {
			if ($param === null) {
				$types .= 's';
			} elseif (\is_int($param)) {
				$types .= 'i';
			} elseif (\is_float($param)) {
				$types .= 'd';
			} elseif (\is_bool($param)) {
				$param  = $param ? 1 : 0;
				$types .= 'i';
			} else {
				$types .= 's';
			}
			$refs[] = &$param;
		}
		unset($param); // Break the foreach reference to prevent accidental aliasing.

		try {
			$stmt->bind_param($types, ...$refs);
		} catch (\mysqli_sql_exception $e) {
			throw new DbQueryException($e->getMessage(), (int)$e->getCode(), $e);
		}
	}




	// ----------------------------------------------------------------
	// Private: Identifier quoting
	// ----------------------------------------------------------------

	/**
	 * Check one SQL identifier and quote it with backticks.
	 *
	 * @param  string  $identifier  Letters, digits, _ and $ only.
	 * @return string  The identifier between backticks.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When the identifier is empty or holds another character.
	 */
	private function quoteIdentifier(string $identifier): string {
		if (!\preg_match('/^[A-Za-z0-9_\$]+$/', $identifier)) {
			throw new DbQueryException('Invalid SQL identifier: ' . $identifier);
		}
		return '`' . $identifier . '`';
	}



	/**
	 * Check a dot-separated SQL identifier path, such as schema.table, and quote each part with backticks.
	 *
	 * @param  string  $path  Parts of letters, digits, _ and $ only, separated by dots.
	 * @return string  Each part between backticks, joined by dots.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException  When a part is empty or holds another character.
	 */
	private function quoteIdentifierPath(string $path): string {
		$segments = \explode('.', $path);
		$quoted   = [];

		foreach ($segments as $seg) {
			if (!\preg_match('/^[A-Za-z0-9_\$]+$/', $seg)) {
				throw new DbQueryException('Invalid SQL identifier segment: ' . $seg);
			}
			$quoted[] = '`' . $seg . '`';
		}

		return \implode('.', $quoted);
	}





	// ----------------------------------------------------------------
	// Private: Error context
	// ----------------------------------------------------------------

	/**
	 * Build a DbQueryException whose message holds the SQL and the types of the parameters.
	 *
	 * The values themselves stay out of the message, because they may be passwords, email
	 * addresses or tokens that must not reach a log. The types, such as [string, int, null],
	 * are enough to diagnose a type mismatch.
	 *
	 * @param  string  $message  Driver error message.
	 * @param  string  $sql  SQL that failed.
	 * @param  list<scalar|null>  $params  Values that were bound; only their types are used.
	 * @param  int  $code  Server error number.
	 * @param  \Throwable|null  $previous  Driver exception.
	 * @return \CitOmni\Infrastructure\Exception\DbQueryException
	 */
	private function createQueryException(
		string $message,
		string $sql,
		array $params = [],
		int $code = 0,
		?\Throwable $previous = null
	): DbQueryException {
		$full = $message . ' SQL: ' . $sql;

		if ($params !== []) {
			$full .= ' Params: ' . $this->formatParamTypes($params);
		}

		return new DbQueryException($full, $code, $previous);
	}



	/**
	 * Describe the types of a parameter list.
	 *
	 * @param  list<scalar|null>  $params  Parameter values.
	 * @return string  Such as [int, string, null]; every type other than null, int, float and bool counts as string.
	 */
	private function formatParamTypes(array $params): string {
		$types = [];
		foreach ($params as $p) {
			$types[] = match (true) {
				$p === null    => 'null',
				\is_int($p)   => 'int',
				\is_float($p) => 'float',
				\is_bool($p)  => 'bool',
				default        => 'string',
			};
		}
		return '[' . \implode(', ', $types) . ']';
	}
}
