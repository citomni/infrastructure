<?php
declare(strict_types=1);

namespace CitOmni\Infrastructure\Model;

/**
 * Thin benchmark model for the legacy LiteMySQLi path.
 *
 * Behavior:
 * - Exposes a minimal set of methods that mirror the new Db service benchmark cases.
 * - Keeps benchmark code explicit instead of calling LiteMySQLi ad hoc from controllers.
 * - Uses the same lazy establish() behavior as the previous implementation style.
 */
final class LiteDbBenchModel extends BaseModelLiteMySQLi {

	/**
	 * Fetch a scalar value through LiteMySQLi.
	 *
	 * @param int $value Input value.
	 * @return mixed
	 */
	public function fetchScalar(int $value): mixed {
		return $this->db->fetchValue('SELECT ? + 1 AS val', [$value]);
	}

	/**
	 * Fetch a single associative row through LiteMySQLi.
	 *
	 * @param int    $a First value.
	 * @param string $b Second value.
	 * @return array<string,mixed>|null
	 */
	public function fetchRowBench(int $a, string $b): ?array {
		return $this->db->fetchRow('SELECT ? AS a, ? AS b', [$a, $b]);
	}

	/**
	 * Insert one benchmark row.
	 *
	 * @param string $table Benchmark table name.
	 * @param string $name  Row value.
	 * @return int
	 */
	public function insertBench(string $table, string $name): int {
		return (int)$this->db->insert($table, [
			'name' => $name,
		]);
	}

	/**
	 * Update one benchmark row.
	 *
	 * @param string $table Benchmark table name.
	 * @param int    $id    Row id.
	 * @param string $name  New value.
	 * @return int
	 */
	public function updateBench(string $table, int $id, string $name): int {
		return (int)$this->db->update($table, [
			'name' => $name,
		], 'id = ?', [$id]);
	}
}
