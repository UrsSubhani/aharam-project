<?php
/**
 * database.php — PDO database connection (Singleton)
 *
 * Usage: $pdo = Database::getInstance();
 *
 * Uses PDO with prepared statements throughout.
 * Connection is lazy: only created on first call.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

class Database
{
    private static ?PDO $instance = null;

    // Prevent direct instantiation
    private function __construct() {}
    private function __clone() {}

    /**
     * Returns the shared PDO connection.
     * Creates it on first call (lazy singleton).
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
        }
        return self::$instance;
    }

    private static function createConnection(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        try {
            return new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Do NOT expose database credentials or detailed errors in production
            $message = APP_DEBUG
                ? 'Database connection failed: ' . $e->getMessage()
                : 'Service temporarily unavailable. Please try again later.';

            http_response_code(503);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
    }

    /**
     * Shorthand: run a query and return PDOStatement.
     * Not for prepared statements — use getPdo() for those.
     */
    public static function query(string $sql): \PDOStatement
    {
        return self::getInstance()->query($sql);
    }

    /**
     * Run a prepared statement with bindings and return statement.
     *
     * Example:
     *   $stmt = Database::execute('SELECT * FROM users WHERE id = ?', [$id]);
     *   $user = $stmt->fetch();
     */
    public static function execute(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch all rows as associative arrays.
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::execute($sql, $params)->fetchAll();
    }

    /**
     * Fetch a single row.
     */
    public static function fetchOne(string $sql, array $params = []): array|false
    {
        return self::execute($sql, $params)->fetch();
    }

    /**
     * Returns the last inserted ID.
     */
    public static function lastInsertId(): string
    {
        return self::getInstance()->lastInsertId();
    }

    public static function inTransaction(): bool
    {
        return self::getInstance()->inTransaction();
    }

    /**
     * Begin transaction.
     */
    public static function beginTransaction(): void
    {
        self::getInstance()->beginTransaction();
    }

    /**
     * Commit transaction.
     */
    public static function commit(): void
    {
        self::getInstance()->commit();
    }

    /**
     * Rollback transaction.
     */
    public static function rollback(): void
    {
        self::getInstance()->rollBack();
    }
}
