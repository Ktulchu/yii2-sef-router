<?php

use yii\db\Migration;

/**
 * One row per canonical path. No redundant slug column:
 * the slug is part of the path, and the internal key is part of the target.
 */
class m261116_000000_create_sef_route_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%sef_route}}', [
            'id' => $this->primaryKey(),
            'path' => $this->string(255)->notNull(),
            'target' => $this->string(255)->notNull(),
        ]);
        $this->createIndex('uq_sef_route_path', '{{%sef_route}}', 'path', true);
        $this->createIndex('uq_sef_route_target', '{{%sef_route}}', 'target', true);
    }

    public function safeDown()
    {
        $this->dropTable('{{%sef_route}}');
    }
}
