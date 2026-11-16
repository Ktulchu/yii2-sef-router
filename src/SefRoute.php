<?php

namespace rkdev\sef;

use yii\db\ActiveRecord;

/**
 * A single canonical public path mapped to a Yii route with parameters.
 *
 * @property int $id
 * @property string $path
 * @property string $target
 */
class SefRoute extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%sef_route}}';
    }

    public function rules()
    {
        return [
            [['path', 'target'], 'required'],
            [['path', 'target'], 'string', 'max' => 255],
            [['path', 'target'], 'unique'],
        ];
    }
}
