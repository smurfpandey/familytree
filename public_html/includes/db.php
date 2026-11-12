<?php
/**
 * includes/db.php - one place that opens a database.
 *
 * FamilyTree speaks a small, portable subset of SQL, so the same queries run on
 * SQLite (the self-hosted default). The app never talks to MySQL, MariaDB or Postgres
 * directly - it uses SQLite for all data storage.
 *
 *   $pdo = ft_connect(['driver' => 'sqlite', 'file' => '/path/family.sqlite']);
 *
 * ft_db_config_from_env() reads DB_* from the environment, which is what config.php uses.
 */

/** Shared SQLite setup; the parent is Pdo\Sqlite (PHP 8.4+) or plain PDO. */
trait FtSqliteMethods {
    public function __construct(string $dsn) {
        parent::__construct($dsn, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->exec('PRAGMA foreign_keys = ON');
        $this->exec('PRAGMA journal_mode = WAL');
        $this->exec('PRAGMA busy_timeout = 5000');
        // MySQL functions the app calls, as SQLite user functions. PHP 8.4 moved
        // createFunction to Pdo\Sqlite and 8.5 deprecates the old PDO method.
        $create = method_exists($this, 'createFunction') ? 'createFunction' : 'sqliteCreateFunction';
        $this->$create('NOW', fn() => date('Y-m-d H:i:s'), 0);
        $this->$create('UUID', fn() => ft_uuid(), 0);
    }
    /** Rewrite the MySQL idioms the app uses into SQLite ones. */
    public static function translate(string $sql): string {
        $sql = preg_replace('/\bINSERT\s+IGNORE\s+INTO\b/i', 'INSERT OR IGNORE INTO', $sql);
        // INSERT ... ON DUPLICATE KEY UPDATE a = VALUES(a), b = VALUES(b)  ->  ON CONFLICT DO UPDATE SET a = excluded.a, ...
        if (preg_match('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\s+(.+)$/is', $sql, $m)) {
            $set = preg_replace('/VALUES\((\w+)\)/i', 'excluded.$1', $m[1]);
            $sql = preg_replace('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\s+.+$/is', 'ON CONFLICT DO UPDATE SET ' . $set, $sql);
        }
        return $sql;
    }
    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []) { return parent::prepare(self::translate($query), $options); }
    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs) {
        $query = self::translate($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
    #[\ReturnTypeWillChange]
    public function exec(string $statement) { return parent::exec(self::translate($statement)); }
}

if (class_exists('Pdo\Sqlite')) {
    class FtSqlitePdo extends Pdo\Sqlite { use FtSqliteMethods; }
} else {
    class FtSqlitePdo extends PDO { use FtSqliteMethods; }
}

/** Where FamilyTree keeps its own files (SQLite db, caches, app.key). The desktop app points this elsewhere. */
function ft_storage_dir(): string {
    $d = getenv('FT_STORAGE_DIR') ?: (dirname(__DIR__) . '/storage');
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}

function ft_uuid(): string {
    $d = random_bytes(16);
    $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
    $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

/** Open a connection described by an array. Throws PDOException when it cannot. */
function ft_connect(array $c): PDO {
    $driver = strtolower($c['driver'] ?? 'sqlite');
    if ($driver !== 'sqlite') {
        throw new PDOException('Unsupported driver: ' . $driver . ': only SQLite is supported');
    }
    $file = $c['file'] ?? '';
    if ($file === '') throw new PDOException('SQLite needs a file path');
    if ($file !== ':memory:' && !is_dir(dirname($file))) @mkdir(dirname($file), 0750, true);
    return new FtSqlitePdo('sqlite:' . $file);
}

/** The app's own database, from DB_* in the environment. */
function ft_db_config_from_env(): array {
    $file = getenv('DB_FILE') ?: (ft_storage_dir() . '/familytree.sqlite');
    return ['driver' => 'sqlite', 'file' => $file];
}

/** Which schema file fits a connection. */
function ft_schema_file(string $driver): string {
    return dirname(__DIR__) . '/install/' . ($driver === 'sqlite' ? 'schema-sqlite.sql' : 'schema.sql');
}

/** Run a schema file statement by statement (no delimiter tricks in our schemas). */
function ft_run_sql_file(PDO $pdo, string $file): int {
    $sql = file_get_contents($file);
    if ($sql === false) throw new RuntimeException("cannot read $file");
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $n = 0;
    foreach (array_filter(array_map('trim', explode(";\n", $sql . "\n"))) as $stmt) {
        if ($stmt === '' || $stmt === ';') continue;
        try { $pdo->exec($stmt); $n++; }
        catch (PDOException $e) {
            // "duplicate column" from a re-run ALTER is fine; anything else is not
            if (!preg_match('/duplicate column|already exists/i', $e->getMessage())) throw $e;
        }
    }
    return $n;
}
