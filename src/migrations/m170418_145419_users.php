<?php

use yii\db\Migration;
use yii\helpers\Console;

class m170418_145419_users extends Migration
{
    /**
     * columns for component tables
     * @var array
     */
    private $columns = [];
    
    /**
     * define columns names and types
     */
    public function init()
    {
        $this->columns = [
            "user" => [
                "id" => $this->primaryKey(),
                "fullName" => $this->string(255),
                "email" => $this->string(255)->null(),
                "password" => $this->string(255)->null(),
                "facebookId" => $this->string(32)->null(),
                "twitterId" => $this->string(32)->null(),
                "token" => $this->string(255),
                "created" => $this->string(30)->null(),
                "updated" => $this->string(30)->null(),
            ],
            "userDevice" => [
                "id" => $this->primaryKey(),
                "userId" => $this->integer(11)->notNull(),
                "token" => $this->string(255),
                "lastLogin" => $this->string(30)->null(),
                "production" => $this->boolean()->defaultValue("0"),
                "timezone" => $this->string(6)->defaultValue("+00:00"),
                "application" => $this->string(255)->notNull(),
                "userToken" => $this->string(255),
                "created" => $this->string(30)->null(),
            ]
        ];
    }

    /**
     * Create user and devices tables with names from config
     */
    public function up()
    {
        $params = \Yii::$app->params;
        if (isset($params["apiAuthCredentials"]["tables"]) && is_array($params["apiAuthCredentials"]["tables"])) {
            foreach ($params["apiAuthCredentials"]["tables"] as $tableKey => $tableName) {
                $table = \Yii::$app->db->schema->getTableSchema($tableName);
                if (is_null($table)) {
                    $this->createTable($tableName, $this->columns[$tableKey], "ENGINE=InnoDB DEFAULT CHARSET=utf8");
                } else {
                    $this->createColumns($table, $this->columns[$tableKey]);
                }
            }
        } else {
            $this->sendError("Tables not specified in params config!");
        }
    }

    /**
     * Create columns if it does not exist
     * @param  yii\db\TableSchema $table   table name
     * @param  array $columns columns with types
     */
    private function createColumns($table, $columns)
    {
        foreach ($columns as $name => $type) {
            if (!isset($table->columns[$name])) {
                $this->addColumn($table, $name, $type);
            }
        }
    }

    /**
     * Print error in terminal and finish executing without migrate
     * @param  string $string error message
     */
    private function sendError($string)
    {
        Console::stdout(Console::ansiFormat("\n$string\n\n", [Console::FG_RED]));
        die;
    }

    /**
     * revert migration must be empty!
     */
    public function down()
    {
        echo "m170418_145419_userDevices reverted.\n";
    }
}
