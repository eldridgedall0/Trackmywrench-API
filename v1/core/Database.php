<?php
/**
 * GarageMinder Mobile API - Database Wrapper
 * 
 * Supports TWO database connections:
 * - GarageMinder DB (garage): vehicles, entries, reminders, api_* tables
 * - WordPress DB (wordpress): wp_users, wp_usermeta (authentication)
 * 
 * Usage:
 *   $db = Database::getInstance();          // GarageMinder DB (default)
 *   $db->fetchAll("SELECT * FROM vehicles WHERE user_id = ?", [1]);
 * 
 *   $wpDb = Database::getWordPress();       // WordPress DB
 *   $wpDb->fetchAll("SELECT * FROM wp_users WHERE ID = ?", [1]);
 */

namespace GarageMinder\API\Core;

class Database
{
    private static ?Database $gmInstance = null;
    private static ?Database $wpInstance = null;
    private \PDO $pdo;

    private function __construct(string $type = 'garage')
    {
        if ($type === 'wordpress') {
            $config = get_wp_db_config();
        } else {
            $config = get_db_config();
        }

        $dsn = "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4";
        if (!empty($config['port'])) {
            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset=utf8mb4";
        }

        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        $this->pdo = new \PDO($dsn, $config['user'], $config['pass'], $options);
    }

    /**
     * Get GarageMinder database instance (default)
     * Used for: vehicles, entries, reminders, api_* tables
     */
    public static function getInstance(): self
    {
        if (self::$gmInstance === null) {
            self::$gmInstance = new self('garage');
        }
        return self::$gmInstance;
    }

    /**
     * Get WordPress database instance
     * Used for: wp_users, wp_usermeta (authentication)
     */
    public static function getWordPress(): self
    {
        if (self::$wpInstance === null) {
            self::$wpInstance = new self('wordpress');
        }
        return self::$wpInstance;
    }

    /**
     * Get WordPress table name with correct prefix
     * Usage: Database::wpTable('users') returns '89bPD7p_users'
     */
    public static function wpTable(string $table): string
    {
        $prefix = defined('WP_TABLE_PREFIX') ? WP_TABLE_PREFIX : 'wp_';
        return $prefix . $table;
    }

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function fetchColumn(string $sql, array $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_map(fn($col) => "`{$col}`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})";
        $this->execute($sql, array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $setParts = array_map(fn($col) => "`{$col}` = ?", array_keys($data));
        $setClause = implode(', ', $setParts);
        $sql = "UPDATE `{$table}` SET {$setClause} WHERE {$where}";
        $params = array_merge(array_values($data), $whereParams);
        return $this->execute($sql, $params);
    }

    public function beginTransaction(): bool { return $this->pdo->beginTransaction(); }
    public function commit(): bool { return $this->pdo->commit(); }
    public function rollback(): bool { return $this->pdo->rollBack(); }

    public function tableExists(string $table): bool
    {
        $result = $this->fetchOne(
            "SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?",
            [$table]
        );
        return $result && $result['cnt'] > 0;
    }

    private function __clone() {}
    public function __wakeup() { throw new \RuntimeException("Cannot deserialize singleton"); }
}
