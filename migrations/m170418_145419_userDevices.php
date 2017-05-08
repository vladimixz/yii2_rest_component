<?php

use yii\db\Migration;

class m170418_145419_userDevices extends Migration
{
    public function up()
    {
        $tableName = 'users';
        $table = \Yii::$app->db->schema->getTableSchema($tableName);
        $columns = [
            "id" => $this->primaryKey(),
            "fullName" => $this->string(255),
            "email" => $this->string(255)->null(),
            "password" => $this->string(255)->null(),
            "facebookId" => $this->string(32)->null(),
            "twitterId" => $this->string(32)->null(),
            "token" => $this->string(255),
            "created" => $this->string(30)->null(),
            "updated" => $this->string(30)->null(),
        ];
        if (is_null($table)) {
            $this->createTable('users', $columns, "ENGINE=InnoDB DEFAULT CHARSET=utf8");
        } else {
            foreach ($columns as $name => $type) {
                if (!isset($table->columns[$name])) {
                    $this->addColumn($tableName, $name, $type);
                }
            }
        }
    }

    public function down()
    {
        echo "m170418_145419_userDevices reverted.\n";
    }
}
