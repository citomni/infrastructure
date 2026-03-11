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

use CitOmni\Infrastructure\Exception\DbConnectException;
use CitOmni\Infrastructure\Exception\DbQueryException;
use CitOmni\Kernel\Service\BaseService;

/**
 * Db: MySQLi database service with lazy connection, prepared statements, and bounded statement cache.
 *
 * Wraps MySQLi directly: no ORM, no query builder, no hidden state.
 * The physical connection is deferred until first use. All configuration
 * is read and validated eagerly in init() before any connection is opened.
 *
 * Behavior:
 * - mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT) is set in init(). This is a
 *   global driver flag; it affects all mysqli operations in the process for the request lifetime.
 * - Connection is lazy; opened on first getConnection() call.
 * - Statement cache is bounded (FIFO eviction). Set limit to 0 to disable.
 * - All queries use positional ? placeholders. Types are auto-detected.
 * - Errors surface as DbConnectException (connection/session) or DbQueryException (queries).
 *   Both extend DbException for catch-all DB-layer error handling.
 *
 * Notes:
 * - select() and its dependents require mysqlnd. For mysqlnd-free streaming use selectNoMysqlnd().
 * - insertBatch() owns its own transaction in the chunked fallback. Do not call from within
 *   an active transaction; wrap the outer operation instead.
 * - Config key for password is "pass" (matches $app->cfg->db->pass).
 *
 * Config node: $app->cfg->db
 *   host, user, pass, name               (required)
 *   charset                              (optional; default: utf8mb4)
 *   port                                 (optional; default: 3306)
 *   socket                               (optional; default: null)
 *   connect_timeout                      (optional; default: 5)
 *   sql_mode                             (optional; explicit session sql_mode)
 *   timezone                             (optional; explicit session time_zone override;
 *                                         if omitted, UTC offset is derived from PHP's active timezone)
 *   statement_cache_limit                (optional; int >= 0, default: 128; 0 disables caching)
 *
 * Typical usage:
 *   $row = $this->app->db->fetchRow('SELECT id, email FROM users WHERE id = ?', [$userId]);
 *
 * @throws \CitOmni\Infrastructure\Exception\DbConnectException On connection or session init failures.
 * @throws \CitOmni\Infrastructure\Exception\DbQueryException   On query prepare, bind, or execute failures.
 */
final class Db extends BaseService {
	private const DEFAULT_CHARSET         = 'utf8mb4';
	private const DEFAULT_PORT            = 3306;
	private const DEFAULT_CONNECT_TIMEOUT = 5;
	private const DEFAULT_STMT_CACHE      = 128;
	private const BATCH_ROW_LIMIT         = 1000;
	private const BATCH_BYTE_LIMIT        = 4 * 1024 * 1024; // 4 MB coarse estimate
	private const BATCH_CHUNK_SIZE        = 1000;

	// Connection settings resolved once in init(). Never read from cfg again after that.
	private string  $cfgHost    = '';
	private string  $cfgUser    = '';
	private string  $cfgPass    = '';
	private string  $cfgName    = '';
	private string  $cfgCharset = self::DEFAULT_CHARSET;
	private int     $cfgPort    = self::DEFAULT_PORT;
	private ?string $cfgSocket  = null;
	private int     $cfgConnectTimeout = self::DEFAULT_CONNECT_TIMEOUT;
	private ?string $cfgSqlMode = null;
	private ?string $cfgTimezone = null;

	/** @var ?\mysqli Active connection; null until first use or after close(). */
	private ?\mysqli $connection = null;

	/** @var bool True while a transaction is active on this connection. */
	private bool $inTransaction = false;

	/** @var array<string, \mysqli_stmt> Statement cache, keyed by trimmed SQL string. */
	private array $statementCache = [];

	private int $statementCacheLimit = self::DEFAULT_STMT_CACHE;
	private int $queryCount = 0;



	// -------------------------------------------------------------------------
	// Initialization
	// -------------------------------------------------------------------------

	/**
	 * Read and validate all database configuration. Called once by BaseService constructor.
	 *
	 * Behavior:
	 * - Reads $app->cfg->db and merges with service options (options win on conflict).
	 * - Validates mandatory keys and fails fast on missing or invalid values.
	 * - Sets mysqli global reporting mode (MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT).
	 * - Does NOT open a database connection.
	 *
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException On missing or invalid config.
	 */
	protected function init(): void {
		// Merge config node with service options. Options take precedence.
		// ?? is safe here: Cfg::__isset() is implemented, so a missing "db" key returns null
		// without throwing. Genuine errors (e.g. toArray() failure) bubble up as intended.
		$cfg = [];
		$raw = $this->app->cfg->db ?? null;
		if ($raw instanceof \CitOmni\Kernel\Cfg) {
			$cfg = $raw->toArray();
		} elseif (\is_array($raw)) {
			$cfg = $raw;
		}

		// Service options win over config.
		$opt = $this->options + $cfg;
		$this->options = []; // free; never read again

		// Mandatory.
		$this->cfgHost = \trim((string)($opt['host'] ?? ''));
		$this->cfgUser = \trim((string)($opt['user'] ?? ''));
		$this->cfgPass = (string)($opt['pass'] ?? '');
		$this->cfgName = \trim((string)($opt['name'] ?? ''));

		if ($this->cfgHost === '') {
			throw new DbConnectException('DB config: "host" is required.');
		}
		if ($this->cfgUser === '') {
			throw new DbConnectException('DB config: "user" is required.');
		}
		if ($this->cfgName === '') {
			throw new DbConnectException('DB config: "name" is required.');
		}

		// Optional with safe defaults. Omitted keys use defaults; supplied-but-invalid keys throw.
		$charset = \trim((string)($opt['charset'] ?? ''));
		$this->cfgCharset = ($charset !== '') ? $charset : self::DEFAULT_CHARSET;

		if (\array_key_exists('port', $opt)) {
			$rawPort = $opt['port'];
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
		} else {
			$this->cfgPort = self::DEFAULT_PORT;
		}

		$socket = $opt['socket'] ?? null;
		$this->cfgSocket = ($socket !== null && \trim((string)$socket) !== '')
			? (string)$socket
			: null;

		if (\array_key_exists('connect_timeout', $opt)) {
			$rawTimeout = $opt['connect_timeout'];
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
		} else {
			$this->cfgConnectTimeout = self::DEFAULT_CONNECT_TIMEOUT;
		}

		$sqlMode = $opt['sql_mode'] ?? null;
		$this->cfgSqlMode = ($sqlMode !== null && \trim((string)$sqlMode) !== '')
			? (string)$sqlMode
			: null;

		$tz = $opt['timezone'] ?? null;
		$this->cfgTimezone = ($tz !== null && \trim((string)$tz) !== '')
			? (string)$tz
			: null;

		if (\array_key_exists('statement_cache_limit', $opt)) {
			$rawLimit = $opt['statement_cache_limit'];
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
		} else {
			$this->statementCacheLimit = self::DEFAULT_STMT_CACHE;
		}

		// Global MySQLi reporting mode - affects all mysqli calls in this process.
		// This is a driver-level flag, not per-connection. Set once per request.
		\mysqli_report(\MYSQLI_REPORT_ERROR | \MYSQLI_REPORT_STRICT);
	}




	// -------------------------------------------------------------------------
	// Read API
	// -------------------------------------------------------------------------

	/**
	 * Fetch the first column of the first row.
	 *
	 * Behavior:
	 * - Returns null when no rows are found.
	 * - Always frees the result before returning.
	 *
	 * @param string $sql    SELECT query with ? placeholders.
	 * @param array  $params Positional parameter values.
	 * @return mixed First column of the first row, or null.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On failure.
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
	 * Fetch the first row as an associative array.
	 *
	 * Behavior:
	 * - Returns null when no rows are found.
	 * - Always frees the result before returning.
	 *
	 * @param string $sql    SELECT query with ? placeholders.
	 * @param array  $params Positional parameter values.
	 * @return array<string,mixed>|null First row as assoc array, or null.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On failure.
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
	 * Fetch all rows as associative arrays.
	 *
	 * Behavior:
	 * - Returns an empty array when no rows are found.
	 * - Always frees the result before returning.
	 *
	 * @param string $sql    SELECT query with ? placeholders.
	 * @param array  $params Positional parameter values.
	 * @return array<int,array<string,mixed>> All rows.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On failure.
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
	 * Count rows from an existing result object or a SQL string.
	 *
	 * Behavior:
	 * - Returns num_rows directly when a mysqli_result is passed (no extra query).
	 * - Executes the SQL and frees the result otherwise.
	 *
	 * @param \mysqli_result|string $resultOrSql Existing result or SQL SELECT string.
	 * @param array                 $params      Positional params when SQL is provided.
	 * @return int Row count (0 when empty).
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On failure.
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
	 * Test whether at least one row exists for a WHERE clause.
	 *
	 * Behavior:
	 * - Builds SELECT 1 ... LIMIT 1. Minimal server-side work.
	 * - $where must be non-empty (without the WHERE keyword); fails fast if empty.
	 *
	 * @param string $table  Table name (may be schema-qualified).
	 * @param string $where  WHERE fragment without the WHERE keyword.
	 * @param array  $params Positional params for the WHERE fragment.
	 * @return bool True when at least one matching row exists.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On empty WHERE or failure.
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
	 * Execute a SELECT and return the raw mysqli_result.
	 *
	 * Behavior:
	 * - Uses the prepared-statement path (cached if limit > 0).
	 * - Frees any pending state on cached statements before execute.
	 * - Uncached statements are closed after get_result(); the buffered result
	 *   (mysqlnd) is independent and safe to read after statement close.
	 * - Caller owns the returned result and must free it.
	 *
	 * Notes:
	 * - Requires mysqlnd. Use selectNoMysqlnd() when mysqlnd is unavailable.
	 *
	 * @param string $sql    SELECT query with ? placeholders.
	 * @param array  $params Positional parameter values.
	 * @return \mysqli_result Active result set (caller must free).
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On failure or no result set.
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
	 * Execute a SELECT without relying on mysqlnd. Streams rows via a Generator.
	 *
	 * Behavior:
	 * - Always uses an uncached (fresh) prepared statement.
	 * - Yields rows one by one; the statement is closed via finally when done or abandoned.
	 *
	 * Notes:
	 * - "$detached = $row; yield $detached" does NOT produce a deep copy. $row is built
	 *   with references from bind_result(). Array assignment copies the structure, but
	 *   PHP reference chains are preserved: $detached shares the same underlying zval
	 *   as $row for each column. A subsequent fetch() call updates both.
	 *   This is safe for standard foreach usage where each row is processed before the
	 *   generator advances. Do NOT accumulate yielded rows across iterations without
	 *   an explicit scalar copy (e.g. array_map) - you will read the last fetched values.
	 *   The pattern is kept because it matches LiteMySQLi and is correct in all normal usage.
	 * - Do not run concurrent generators on the same connection.
	 *
	 * @param string $sql    SELECT query with ? placeholders.
	 * @param array  $params Positional parameter values.
	 * @return \Generator<int,array<string,mixed>> Yields each row as an assoc array.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On failure.
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
			// See Notes in class PHPDoc for the reference-semantics caveat.
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
				// Safe for standard foreach; see class-level Notes for accumulation caveat.
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




	// -------------------------------------------------------------------------
	// Write API
	// -------------------------------------------------------------------------

	/**
	 * Execute a non-SELECT statement and return the affected row count.
	 *
	 * Behavior:
	 * - Uses the prepared-statement path (cached if limit > 0).
	 * - Returns mysqli::affected_rows.
	 *
	 * @param string $sql    SQL with ? placeholders.
	 * @param array  $params Positional parameter values.
	 * @return int Affected row count.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On failure.
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
			// Read affected_rows from the connection, not the statement, to stay consistent
			// with how LiteMySQLi reads it. Both are valid; connection is more canonical.
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
	 * Execute the same statement repeatedly with different parameter sets.
	 *
	 * Behavior:
	 * - Prepares (or reuses cached) the statement once, then executes per param set.
	 * - Returns total affected rows across all executions.
	 *
	 * Notes:
	 * - For large batches, wrap in transaction() to avoid per-row fsync overhead.
	 *
	 * @param string  $sql       SQL with ? placeholders (prepared once, reused).
	 * @param array[] $paramSets Ordered array of parameter arrays.
	 * @return int Total affected row count.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On failure.
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
	 * Insert a single row and return the insert id.
	 *
	 * Behavior:
	 * - Validates and quotes table and column identifiers.
	 * - Builds a parameterized INSERT.
	 * - Returns the current mysqli insert_id.
	 *
	 * @param string              $table Table name (may be schema-qualified).
	 * @param array<string,mixed> $data  Column/value map.
	 * @return int Insert id.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On empty data or failure.
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
	 * Insert multiple rows efficiently.
	 *
	 * Behavior:
	 * - Strategy 1 (small payload): single multi-row INSERT, one round trip.
	 *   Threshold: <= 1000 rows AND <= 4 MB coarse byte estimate.
	 * - Strategy 2 (large payload): chunked executeMany() inside its own transaction.
	 *   Throws if a transaction is already active; nested transactions are not supported
	 *   and would implicitly commit the outer transaction under InnoDB.
	 * - All rows must have the same columns as the first row (same keys, same count).
	 *   Validation uses a count guard followed by one array_diff_key() check:
	 *   if count matches and no expected key is missing, no extra key can exist either.
	 *
	 * @param string                         $table Table name (may be schema-qualified).
	 * @param array<int,array<string,mixed>> $rows  Rows to insert; all must share the first row's column set.
	 * @return int Total affected row count.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On empty input, schema mismatch,
	 *         active transaction (chunked path only), or failure.
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
	 * Update rows and return the affected row count.
	 *
	 * Behavior:
	 * - Validates and quotes table and column identifiers.
	 * - WHERE must be non-empty; fails fast otherwise.
	 * - WHERE params are appended after SET params.
	 *
	 * @param string              $table  Table name (may be schema-qualified).
	 * @param array<string,mixed> $data   Column/value map for the SET clause.
	 * @param string              $where  WHERE fragment without the WHERE keyword.
	 * @param array               $params Positional params for the WHERE fragment.
	 * @return int Affected row count.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On empty data, empty WHERE, or failure.
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
	 * Delete rows and return the affected row count.
	 *
	 * Behavior:
	 * - WHERE must be non-empty; fails fast otherwise.
	 *
	 * @param string $table  Table name (may be schema-qualified).
	 * @param string $where  WHERE fragment without the WHERE keyword.
	 * @param array  $params Positional params for the WHERE fragment.
	 * @return int Affected row count.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On empty WHERE or failure.
	 */
	public function delete(string $table, string $where, array $params = []): int {
		$where = \trim($where);
		if ($where === '') {
			throw new DbQueryException('delete(): WHERE clause must not be empty.');
		}

		$sql = 'DELETE FROM ' . $this->quoteIdentifierPath($table) . ' WHERE ' . $where;
		return $this->execute($sql, $params);
	}




	// -------------------------------------------------------------------------
	// Raw query escape hatches
	// -------------------------------------------------------------------------

	/**
	 * Run a raw SQL query without parameter binding.
	 *
	 * Notes:
	 * - No binding. Never pass untrusted input.
	 * - Use for DDL, administrative commands, or quick debug.
	 * - Caller owns any returned mysqli_result and must free it.
	 *
	 * @param string $sql Raw SQL to execute.
	 * @return \mysqli_result|bool Result for reads; true for writes.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On empty SQL or failure.
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
	 * Execute multiple raw SQL statements via multi_query().
	 *
	 * Behavior:
	 * - Results are collected in order: mysqli_result for SELECT/SHOW, true for others.
	 * - Under MYSQLI_REPORT_STRICT (active globally), any mid-batch MySQL error is thrown
	 *   as mysqli_sql_exception immediately. The catch block then:
	 *   1) Frees all mysqli_result objects collected before the failure.
	 *   2) Drains remaining pending results best-effort to re-sync the connection.
	 * - Query counter is incremented once per processed result slot.
	 *
	 * Notes:
	 * - Caller owns returned mysqli_result objects and must free them.
	 * - No parameter binding. Never pass untrusted input.
	 * - Use for migrations, DDL batches, or seeding.
	 *
	 * @param string $sql Multiple raw SQL statements separated by semicolons.
	 * @return array<int,\mysqli_result|bool> Ordered results.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On dispatch or mid-batch failure.
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




	// -------------------------------------------------------------------------
	// Transactions
	// -------------------------------------------------------------------------

	/**
	 * Begin a transaction.
	 *
	 * Notes:
	 * - Opens a lazy connection if not yet connected (beginning a transaction is
	 *   a meaningful first operation; this is intentional and correct).
	 *
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On failure.
	 */
	public function beginTransaction(): void {
		try {
			$this->getConnection()->begin_transaction();
			$this->inTransaction = true;
		} catch (\mysqli_sql_exception $e) {
			throw new DbQueryException($e->getMessage(), (int)$e->getCode(), $e);
		}
	}


	/**
	 * Commit the current transaction.
	 *
	 * Notes:
	 * - Requires an active connection. Throws if no connection exists, because
	 *   there is no transaction to commit without one.
	 *
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On failure or no active connection.
	 */
	public function commit(): void {
		try {
			$this->requireConnection()->commit();
			$this->inTransaction = false;
		} catch (\mysqli_sql_exception $e) {
			throw new DbQueryException($e->getMessage(), (int)$e->getCode(), $e);
		}
	}


	/**
	 * Roll back the current transaction.
	 *
	 * Notes:
	 * - Requires an active connection. Throws if no connection exists, because
	 *   there is no transaction to roll back without one.
	 *
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On failure or no active connection.
	 */
	public function rollback(): void {
		try {
			$this->requireConnection()->rollback();
			$this->inTransaction = false;
		} catch (\mysqli_sql_exception $e) {
			throw new DbQueryException($e->getMessage(), (int)$e->getCode(), $e);
		}
	}


	/**
	 * Run a callable inside a transaction. Commits on success, rolls back on any exception.
	 *
	 * Behavior:
	 * - Passes the current Db instance to the callback.
	 * - On success: commits and returns the callback's return value.
	 * - On callback failure + successful rollback: rethrows the original exception unchanged.
	 * - On callback failure + rollback failure: throws a new DbQueryException whose message
	 *   describes both failures, with the original callback exception chained as $previous.
	 *   This ensures neither failure is silently discarded.
	 *
	 * Typical usage:
	 *   $orderId = $this->app->db->transaction(function(Db $db) use ($data): int {
	 *       $id = $db->insert('orders', $data);
	 *       $db->insert('order_lines', ['order_id' => $id, ...]);
	 *       return $id;
	 *   });
	 *
	 * @param callable $callback Callback receiving the Db instance; may return any value.
	 * @return mixed Callback return value.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException When rollback also fails.
	 * @throws \Throwable Rethrows the original callback exception when rollback succeeds.
	 */
	public function transaction(callable $callback): mixed {
		$this->beginTransaction();
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
	 * Alias for transaction(). Provided for backward compatibility with LiteMySQLi callers.
	 *
	 * @param callable $callback Callback receiving the Db instance.
	 * @return mixed Callback return value.
	 * @throws \Throwable Rethrows on failure after rollback attempt.
	 */
	public function easyTransaction(callable $callback): mixed {
		return $this->transaction($callback);
	}




	// -------------------------------------------------------------------------
	// Meta
	// -------------------------------------------------------------------------

	/**
	 * Return the insert id from the most recent INSERT.
	 *
	 * Behavior:
	 * - Returns 0 if no connection has been opened yet (no INSERT has run).
	 *
	 * @return int Insert id, or 0.
	 */
	public function lastInsertId(): int {
		return $this->connection !== null ? $this->connection->insert_id : 0;
	}


	/**
	 * Return the affected row count from the most recent write statement.
	 *
	 * Behavior:
	 * - Returns 0 if no connection has been opened yet.
	 *
	 * @return int Affected row count, or 0.
	 */
	public function affectedRows(): int {
		return $this->connection !== null ? $this->connection->affected_rows : 0;
	}


	/**
	 * Return (and optionally reset) the internal query counter.
	 *
	 * @param bool $reset Reset the counter after reading.
	 * @return int Query count.
	 */
	public function countQueries(bool $reset = false): int {
		$count = $this->queryCount;
		if ($reset) {
			$this->queryCount = 0;
		}
		return $count;
	}


	/**
	 * Return the last MySQLi error string from the active connection.
	 *
	 * Behavior:
	 * - Returns null if no connection has been opened yet.
	 * - Returns null if the last operation produced no error.
	 *
	 * @return string|null Last error string, or null.
	 */
	public function getLastError(): ?string {
		if ($this->connection === null) {
			return null;
		}
		$err = $this->connection->error;
		return ($err !== '') ? $err : null;
	}


	/**
	 * Return the last MySQLi error code from the active connection.
	 *
	 * Behavior:
	 * - Returns 0 if no connection has been opened yet.
	 * - Returns 0 if the last operation produced no error.
	 *
	 * @return int Error code, or 0.
	 */
	public function getLastErrorCode(): int {
		return $this->connection !== null ? $this->connection->errno : 0;
	}




	// -------------------------------------------------------------------------
	// Cache management
	// -------------------------------------------------------------------------

	/**
	 * Update the statement cache size limit at runtime.
	 *
	 * Behavior:
	 * - Values below 0 are normalized to 0 (disables caching).
	 * - 0 immediately clears all cached statements.
	 * - Shrinking the limit evicts oldest entries (FIFO) until within bounds.
	 *
	 * @param int $limit New cache size limit (>= 0).
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
	 * Close and clear all cached prepared statements.
	 *
	 * Behavior:
	 * - Each statement is closed best-effort. A stale or already-closed statement will
	 *   not interrupt clearing of the remaining entries.
	 * - The connection remains open and usable after this call.
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
	 * Close all cached statements and the active connection.
	 *
	 * Behavior:
	 * - Safe to call multiple times.
	 * - After close(), any subsequent query will re-open the connection (lazy).
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

	public function __destruct() {
		try {
			$this->close();
		} catch (\Throwable) {
			// Never allow exceptions from a destructor.
		}
	}




	// -------------------------------------------------------------------------
	// Private: Connection lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Return the active connection, opening it lazily on first call.
	 *
	 * Behavior:
	 * - $this->connection is assigned only after real_connect(), set_charset(), and
	 *   applySessionSettings() have all succeeded. A partial failure leaves the object
	 *   with no connection, and the local $conn is closed best-effort before throwing.
	 *   This guarantees the object is always in one of two states: no connection, or a
	 *   fully initialized connection. Never a half-open one.
	 *
	 * @return \mysqli Active connection.
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException On connect or session failure.
	 */
	private function getConnection(): \mysqli {
		if ($this->connection instanceof \mysqli) {
			return $this->connection;
		}

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
				$this->cfgPass,
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
	 * Assert that the connection is already open and return it.
	 *
	 * Used exclusively by commit() and rollback() where opening a new connection
	 * would be meaningless (there is no transaction to act on).
	 *
	 * @return \mysqli Active connection.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException If no active connection exists.
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
	 * Apply per-session settings after connect: sql_mode and time zone.
	 *
	 * Behavior:
	 * 1) Optional sql_mode applied via prepared statement (config value is user-supplied).
	 * 2) Explicit timezone config wins: applied via prepared statement and returns early.
	 * 3) No explicit timezone: derives UTC offset from PHP's active default timezone and
	 *    applies it via a raw query. The value is sprintf-controlled (not user input).
	 *    DST-aware because offset is computed for "now" in the active zone.
	 *    Mirrors LiteMySQLi behavior exactly.
	 *
	 * Notes:
	 * - Receives $conn explicitly. $this->connection is not yet assigned when this runs;
	 *   that assignment happens in getConnection() only after this method succeeds.
	 *
	 * @param \mysqli $conn The newly opened, not yet owned connection.
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException On session statement failure.
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

		// Always derive UTC offset from PHP's active timezone - same as LiteMySQLi.
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
	 * Execute a session initialization statement using a one-off prepared statement.
	 *
	 * Used for sql_mode and explicit timezone where the value comes from config and
	 * must be treated as user-supplied (not safe to embed raw).
	 *
	 * @param \mysqli $conn   Active connection.
	 * @param string  $sql    SQL with one ? placeholder.
	 * @param array   $params Parameter values.
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\DbConnectException On prepare or execute failure.
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




	// -------------------------------------------------------------------------
	// Private: Statement cache
	// -------------------------------------------------------------------------

	/**
	 * Return a prepared statement for the given SQL, using the cache when enabled.
	 *
	 * Behavior:
	 * - Cache hit: returns the existing statement.
	 * - Cache miss: prepares, caches, and returns.
	 * - Cache full: evicts oldest entry (FIFO via array_key_first + unset) before inserting.
	 * - Cache disabled (limit 0): always prepares a fresh uncached statement.
	 *
	 * Notes:
	 * - SQL is used verbatim as the cache key (callers must pass trimmed SQL;
	 *   all public entry points trim before calling here).
	 *
	 * @param string $sql Trimmed SQL to prepare.
	 * @return \mysqli_stmt Prepared statement.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On prepare failure.
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
	 * Prepare a statement without adding it to the cache.
	 *
	 * @param string $sql SQL to prepare.
	 * @return \mysqli_stmt Freshly prepared statement.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On prepare failure.
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
	 * Discard any pending buffered result on a statement. No-op on clean statements.
	 *
	 * Needed before reusing a cached statement that previously produced a result set.
	 * Called as a best-effort cleanup; failures are silently ignored.
	 *
	 * @param \mysqli_stmt $stmt Statement to clean.
	 * @return void
	 */
	private function freeStatementResultIfPossible(\mysqli_stmt $stmt): void {
		try {
			$stmt->free_result();
		} catch (\Throwable) {}
	}




	// -------------------------------------------------------------------------
	// Private: Parameter binding
	// -------------------------------------------------------------------------

	/**
	 * Bind positional parameters to a prepared statement with automatic type detection.
	 *
	 * Type mapping:
	 * - null    -> 's' (mysqli always transmits SQL NULL regardless of type letter)
	 * - int     -> 'i'
	 * - float   -> 'd'
	 * - bool    -> 'i' (converted to 0/1 before binding)
	 * - other   -> 's'
	 *
	 * @param \mysqli_stmt $stmt   Prepared statement.
	 * @param array        $params Positional parameter values.
	 * @return void
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On bind failure.
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




	// -------------------------------------------------------------------------
	// Private: Identifier quoting
	// -------------------------------------------------------------------------

	/**
	 * Validate and quote a single SQL identifier with backticks.
	 *
	 * Allows only [A-Za-z0-9_$].
	 *
	 * @param string $identifier Identifier to validate and quote.
	 * @return string Quoted identifier.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On invalid characters.
	 */
	private function quoteIdentifier(string $identifier): string {
		if (!\preg_match('/^[A-Za-z0-9_\$]+$/', $identifier)) {
			throw new DbQueryException('Invalid SQL identifier: ' . $identifier);
		}
		return '`' . $identifier . '`';
	}



	/**
	 * Validate and quote a dot-separated SQL identifier path (e.g., schema.table).
	 *
	 * Each segment must match [A-Za-z0-9_$].
	 *
	 * @param string $path Dot-separated identifier path.
	 * @return string Quoted identifier path, e.g. `schema`.`table`.
	 * @throws \CitOmni\Infrastructure\Exception\DbQueryException On invalid segment.
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





	// -------------------------------------------------------------------------
	// Private: Error context
	// -------------------------------------------------------------------------

	/**
	 * Build a DbQueryException with SQL and parameter types included in the message.
	 *
	 * Raw parameter values are intentionally excluded: they may contain passwords,
	 * email addresses, tokens, or other sensitive runtime data that must not appear
	 * in log files. Parameter types (e.g. [string, int, null]) are included instead
	 * - sufficient for diagnosing type mismatches and placeholder count errors without
	 * leaking data.
	 *
	 * @param string          $message  Driver error message.
	 * @param string          $sql      SQL involved in the failure.
	 * @param array           $params   Bound parameters (types extracted; values discarded).
	 * @param int             $code     Driver error code.
	 * @param \Throwable|null $previous Causing exception.
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
	 * Produce a compact type-only description of a parameter list.
	 *
	 * Examples: [int, string, null]  /  [string, float, bool]
	 *
	 * @param array $params Parameter values.
	 * @return string Type list in brackets.
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
