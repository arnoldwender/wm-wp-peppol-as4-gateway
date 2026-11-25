<?php
/**
 * A $wpdb for the standalone suite that runs its SQL on SQLite (PDO, in memory).
 *
 * The tables come from the plugin's own CREATE TABLE statements through dbDelta(), translated to SQLite, so a
 * write to a column that does not exist, a NOT NULL without a value or a duplicate UNIQUE key fails here the way
 * it fails in MySQL. Mirrors of core behaviour the plugin relies on:
 *   - prepare(): %s quoted, %d integer, %f float, %i identifier; a placeholder count that does not match the
 *     arguments throws (core refuses such a query);
 *   - insert()/update(): a string longer than its varchar column is refused (wpdb::process_fields);
 *   - get_var()/get_row()/get_results(): values come back as strings or null, as mysqli returns them.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

final class Test_WPDB {

	public string $prefix    = 'wp_';
	public string $options   = 'wp_options';
	public int $insert_id    = 0;
	public string $last_error = '';
	/** @var string[] */
	public array $queries = [];
	/** When true the next insert() returns false without writing. */
	public bool $fail_next_insert = false;

	private PDO $pdo;
	/** @var array<string, array<string, int>> table => column => varchar length */
	private array $lengths = [];

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->query( 'CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT NOT NULL UNIQUE, option_value TEXT NOT NULL)' );
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * @param string $query Query with placeholders.
	 * @param mixed  ...$args Values, or one array of values.
	 */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$expected = preg_match_all( '/(?<!%)%[sdfi]/', $query );
		if ( $expected !== count( $args ) ) {
			throw new LogicException( "wpdb::prepare: {$expected} placeholders, " . count( $args ) . " arguments in: {$query}" );
		}
		$i = 0;
		return (string) preg_replace_callback(
			'/%%|%([sdfi])/',
			function ( array $m ) use ( &$i, $args ): string {
				if ( '%%' === $m[0] ) {
					return '%';
				}
				$value = $args[ $i++ ];
				return match ( $m[1] ) {
					'i'     => '`' . str_replace( '`', '``', (string) $value ) . '`',
					'd'     => (string) (int) $value,
					'f'     => sprintf( '%F', (float) $value ),
					default => $this->quote( $value ),
				};
			},
			$query
		);
	}

	/** Run a statement; rows affected, or false. */
	public function query( string $sql ): int|bool {
		$statement = $this->run( $sql );
		return false === $statement ? false : $statement->rowCount();
	}

	public function get_var( string $sql ): ?string {
		$statement = $this->run( $sql );
		if ( false === $statement ) {
			return null;
		}
		$row = $statement->fetch( PDO::FETCH_NUM );
		return ( false === $row || null === $row[0] ) ? null : (string) $row[0];
	}

	public function get_row( string $sql, string $output = 'OBJECT' ): array|object|null {
		$rows = $this->get_results( $sql, $output );
		return $rows[0] ?? null;
	}

	/** @return array<int, array<string, ?string>|object> */
	public function get_results( string $sql, string $output = 'OBJECT' ): array {
		$statement = $this->run( $sql );
		if ( false === $statement ) {
			return [];
		}
		$out = [];
		foreach ( $statement->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
			$row   = array_map( static fn( $v ) => null === $v ? null : (string) $v, $row );
			$out[] = 'ARRAY_A' === $output ? $row : (object) $row;
		}
		return $out;
	}

	/** @param array<string, mixed> $data */
	public function insert( string $table, array $data, $format = null ): int|false {
		if ( $this->fail_next_insert ) {
			$this->fail_next_insert = false;
			return false;
		}
		if ( ! $this->fits( $table, $data ) ) {
			return false;
		}
		$columns = implode( ', ', array_map( static fn( string $c ): string => '`' . $c . '`', array_keys( $data ) ) );
		$values  = implode( ', ', array_map( [ $this, 'quote' ], array_values( $data ) ) );
		if ( false === $this->run( "INSERT INTO `{$table}` ({$columns}) VALUES ({$values})" ) ) {
			return false;
		}
		$this->insert_id = (int) $this->pdo->lastInsertId();
		return 1;
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 */
	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|false {
		if ( ! $this->fits( $table, $data ) ) {
			return false;
		}
		$set = implode( ', ', array_map( fn( string $c ): string => "`{$c}` = " . $this->quote( $data[ $c ] ), array_keys( $data ) ) );
		$statement = $this->run( "UPDATE `{$table}` SET {$set} WHERE " . $this->where( $where ) );
		return false === $statement ? false : $statement->rowCount();
	}

	/** @param array<string, mixed> $where */
	public function delete( string $table, array $where, $where_format = null ): int|false {
		$statement = $this->run( "DELETE FROM `{$table}` WHERE " . $this->where( $where ) );
		return false === $statement ? false : $statement->rowCount();
	}

	/** Create a table from a MySQL CREATE TABLE statement as dbDelta() receives it. */
	public function create_from_mysql( string $sql ): void {
		if ( ! preg_match( '/CREATE TABLE\s+(\S+)\s*\((.*)\)[^)]*;?\s*$/is', $sql, $m ) ) {
			throw new LogicException( "dbDelta stub cannot read: {$sql}" );
		}
		$table = trim( $m[1], '`' );
		$defs  = [];
		foreach ( preg_split( '/\R/', $m[2] ) as $line ) {
			$line = rtrim( trim( $line ), ',' );
			if ( '' === $line || preg_match( '/^KEY\s/i', $line ) ) {
				continue;
			}
			if ( preg_match( '/^UNIQUE KEY\s+\S+\s*(\(.*\))$/i', $line, $u ) ) {
				$defs[] = 'UNIQUE ' . $u[1];
				continue;
			}
			if ( preg_match( '/^(\w+)\s+varchar\((\d+)\)/i', $line, $v ) ) {
				$this->lengths[ $table ][ $v[1] ] = (int) $v[2];
			}
			$line = (string) preg_replace( '/bigint\(\d+\)\s+unsigned|bigint\s+unsigned/i', 'INTEGER', $line );
			$line = (string) preg_replace( '/\s+AUTO_INCREMENT/i', '', $line );
			$line = (string) preg_replace( '/varchar\(\d+\)|longtext|datetime|\bdate\b|decimal\(\d+,\d+\)/i', 'TEXT', $line );
			$defs[] = $line;
		}
		$this->pdo->query( "CREATE TABLE IF NOT EXISTS `{$table}` (" . implode( ', ', $defs ) . ')' );
	}

	/** Rows of a table, for assertions. */
	public function rows( string $table ): array {
		return $this->get_results( "SELECT * FROM `{$table}` ORDER BY rowid", 'ARRAY_A' );
	}

	private function run( string $sql ): PDOStatement|false {
		$this->queries[] = $sql;
		// SHOW TABLES LIKE 'name' (the name escaped with esc_like).
		if ( preg_match( "/^\s*SHOW TABLES LIKE '((?:[^']|'')*)'\s*$/i", $sql, $m ) ) {
			$sql = "SELECT name FROM sqlite_master WHERE type = 'table' AND name = '" . stripslashes( $m[1] ) . "'";
		}
		// MySQL treats a backslash in a LIKE pattern as escape character; SQLite needs it declared.
		$sql = (string) preg_replace( "/LIKE ('(?:[^']|'')*')(?!\s*ESCAPE)/i", "LIKE $1 ESCAPE '\\'", $sql );
		try {
			$this->last_error = '';
			return $this->pdo->query( $sql );
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	private function quote( mixed $value ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		return "'" . str_replace( "'", "''", (string) $value ) . "'";
	}

	/** @param array<string, mixed> $where */
	private function where( array $where ): string {
		return implode( ' AND ', array_map( fn( string $c ): string => "`{$c}` = " . $this->quote( $where[ $c ] ), array_keys( $where ) ) );
	}

	/** @param array<string, mixed> $data */
	private function fits( string $table, array $data ): bool {
		foreach ( $data as $column => $value ) {
			$max = $this->lengths[ $table ][ $column ] ?? null;
			if ( null !== $max && is_string( $value ) && mb_strlen( $value ) > $max ) {
				$this->last_error = "value too long for {$table}.{$column}";
				return false;
			}
		}
		return true;
	}
}
