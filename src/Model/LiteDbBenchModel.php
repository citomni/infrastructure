<?php
declare(strict_types=1);

namespace CitOmni\Infrastructure\Model;

/**
 * LiteDbBenchModel: Benchmark model for the LiteMySQLi path.
 *
 * Gives benchmarks a fixed set of small LiteMySQLi calls, so that they can compare the
 * LiteMySQLi path with the db service on the same statements. Timing and comparing belong to
 * the benchmark that calls it.
 */
class LiteDbBenchModel extends BaseModelLiteMySQLi {

	/**
	 * Let the database add 1 to a value.
	 *
	 * @param  int  $value
	 * @return mixed  The sum as LiteMySQLi returns it.
	 */
	public function fetchScalar(int $value): mixed {
		return $this->db->fetchValue('SELECT ? + 1 AS val', [$value]);
	}

	/**
	 * Let the database return two values as one row.
	 *
	 * @param  int  $a
	 * @param  string  $b
	 * @return array<string,mixed>|null  Row with keys a and b, as LiteMySQLi returns it.
	 */
	public function fetchRowBench(int $a, string $b): ?array {
		return $this->db->fetchRow('SELECT ? AS a, ? AS b', [$a, $b]);
	}

	/**
	 * Insert one row with a name into a benchmark table.
	 *
	 * @param  string  $table  Benchmark table with a name column.
	 * @param  string  $name  Value of the name column.
	 * @return int  LiteMySQLi's result, cast to int.
	 * @see \LiteMySQLi\LiteMySQLi::insert()  What the result is.
	 */
	public function insertBench(string $table, string $name): int {
		return (int)$this->db->insert($table, [
			'name' => $name,
		]);
	}

	/**
	 * Change the name of one row in a benchmark table.
	 *
	 * @param  string  $table  Benchmark table with id and name columns.
	 * @param  int  $id  Row id.
	 * @param  string  $name  New value of the name column.
	 * @return int  LiteMySQLi's result, cast to int.
	 * @see \LiteMySQLi\LiteMySQLi::update()  What the result is.
	 */
	public function updateBench(string $table, int $id, string $name): int {
		return (int)$this->db->update($table, [
			'name' => $name,
		], 'id = ?', [$id]);
	}
}
