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
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);

        self::ensureMemberTypes(self::$pdo);
        self::normalizeMemberNames(self::$pdo);
        return self::$pdo;
    }

    private static function ensureMemberTypes(PDO $pdo): void
    {
        try {
            $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
            $stmt = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME='members' AND COLUMN_NAME='member_type'");
            $stmt->execute(['db'=>$db]);
            $columnType = strtolower((string)$stmt->fetchColumn());
            if ($columnType === '' || (str_contains($columnType, "'viewer'") && str_contains($columnType, "'flyer'") && str_contains($columnType, "'pilot'") && !str_contains($columnType, "'supporting'") && !str_contains($columnType, "'flying'"))) {
                return;
            }

            $pdo->exec("ALTER TABLE members MODIFY member_type ENUM('supporting','flying','viewer','flyer','pilot') NOT NULL DEFAULT 'viewer'");
            $pdo->exec("ALTER TABLE memberships MODIFY membership_type ENUM('supporting','flying','viewer','flyer','pilot') NOT NULL DEFAULT 'viewer'");

            $pdo->exec("UPDATE members SET member_type='viewer' WHERE member_type='supporting'");
            $pdo->exec("UPDATE members SET member_type='flyer' WHERE member_type='flying'");
            $pdo->exec("UPDATE memberships SET membership_type='viewer' WHERE membership_type='supporting'");
            $pdo->exec("UPDATE memberships SET membership_type='flyer' WHERE membership_type='flying'");

            $pdo->exec("UPDATE memberships SET free_flight_entitled=CASE WHEN membership_type='flyer' THEN 1 ELSE 0 END, free_flight_date=CASE WHEN membership_type='flyer' THEN free_flight_date ELSE NULL END");

            $pdo->exec("ALTER TABLE members MODIFY member_type ENUM('viewer','flyer','pilot') NOT NULL DEFAULT 'viewer'");
            $pdo->exec("ALTER TABLE memberships MODIFY membership_type ENUM('viewer','flyer','pilot') NOT NULL DEFAULT 'viewer'");
        } catch (Throwable $e) {
            // Tijdens de eerste setup kunnen de tabellen nog niet bestaan.
        }
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
