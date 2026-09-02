<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $configFile = APP_ROOT . '/config/database.php';
        if (!is_file($configFile)) {
            throw new RuntimeException('Databaseconfiguratie ontbreekt. Deploy de applicatie via GitHub Actions.');
        }

        $config = require $configFile;
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['name']
        );

        self::$pdo = new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        self::normalizeMemberNames(self::$pdo);
        return self::$pdo;
    }

    private static function normalizeMemberNames(PDO $pdo): void
    {
        try {
            $rows = $pdo->query('SELECT id, first_name, last_name FROM members')->fetchAll();
            $update = $pdo->prepare('UPDATE members SET first_name=:first_name,last_name=:last_name WHERE id=:id');
            foreach ($rows as $row) {
                $first = self::nameCase((string)($row['first_name'] ?? ''));
                $last = self::nameCase((string)($row['last_name'] ?? ''));
                if ($first === (string)$row['first_name'] && $last === (string)$row['last_name']) continue;
                $update->execute(['first_name'=>$first,'last_name'=>$last,'id'=>(int)$row['id']]);
            }
        } catch (Throwable) {
            // Tijdens de eerste setup kan de members-tabel nog niet bestaan.
        }
    }

    private static function nameCase(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($name === '') return '';
        if (function_exists('mb_convert_case')) return mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        return ucwords(strtolower($name));
    }
}
