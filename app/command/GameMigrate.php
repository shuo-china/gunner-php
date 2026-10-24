<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

final class GameMigrate extends Command
{
    protected function configure() { $this->setName('game:migrate')->setDescription('Create game login, cloud save and reward schemas (idempotent)'); }
    protected function execute(Input $input, Output $output)
    {
        $prefix = (string) config('database.connections.mysql.prefix', '');
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) throw new \RuntimeException('Invalid table prefix');
        foreach (['001_game_login.sql', '002_game_save.sql', '003_game_rewards.sql', '004_game_share.sql'] as $migration) {
            $sql = preg_replace('/^--.*$/m', '', file_get_contents(root_path() . 'database/migrations/' . $migration));
            foreach (explode(';', str_replace('{{prefix}}', $prefix, $sql)) as $statement) {
                if (trim($statement) !== '') Db::execute($statement);
            }
        }
        $engine = Db::query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$prefix . 'user']);
        if (strtoupper($engine[0]['ENGINE'] ?? '') !== 'INNODB') {
            throw new \RuntimeException('Unsupported user table schema: InnoDB is required.');
        }
        $column = Db::query('SHOW COLUMNS FROM `' . $prefix . 'user` LIKE \'last_login_ip\'');
        if (strtolower($column[0]['Type'] ?? '') !== 'varchar(45)') {
            throw new \RuntimeException('Unsupported user table schema: last_login_ip must be VARCHAR(45).');
        }
        $output->writeln('Game login, cloud save and reward schemas ready. No existing player records were replaced.');
        return 0;
    }
}
