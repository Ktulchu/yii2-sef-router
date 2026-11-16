<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

use rkdev\sef\SefBehavior;
use rkdev\sef\SefRoute;
use rkdev\sef\SefRule;
use yii\console\Application;
use yii\db\ActiveRecord;
use yii\db\IntegrityException;
use yii\db\Migration;
use yii\web\Request;
use yii\web\UrlManager;

date_default_timezone_set('UTC');

function check($condition, $label)
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
}

class DemoPost extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%test_post}}';
    }

    public function behaviors()
    {
        return [
            'sef' => [
                'class' => SefBehavior::class,
                'route' => 'news/view',
                'prefix' => 'news',
                'slugAttribute' => 'slug',
            ],
        ];
    }

    // Yii wraps the owner's INSERT/UPDATE/DELETE and all after-save hooks
    // in a single DB transaction, including writes made by SefBehavior.
    public function transactions()
    {
        return ['default' => self::OP_INSERT | self::OP_UPDATE | self::OP_DELETE];
    }
}

class DemoRequest extends Request
{
    public $testPathInfo = '';

    public function getPathInfo()
    {
        return $this->testPathInfo;
    }
}

new Application([
    'id' => 'sef-integration-tests',
    'basePath' => __DIR__,
    'components' => [
        'db' => [
            'class' => 'yii\db\Connection',
            'dsn' => 'sqlite::memory:',
        ],
    ],
]);

$db = Yii::$app->db;
$db->open();
$db->createCommand()->createTable('{{%test_post}}', [
    'id' => 'pk',
    'title' => 'varchar(255) NOT NULL',
    'slug' => 'varchar(255) NOT NULL',
])->execute();

require dirname(__DIR__) . '/src/migrations/m261116_000000_create_sef_route_table.php';
$migration = new m261116_000000_create_sef_route_table(['db' => $db]);
$migration->safeUp();

$manager = new UrlManager(['enablePrettyUrl' => true, 'showScriptName' => false]);
$rule = new SefRule();

// Insert: owner ID is available after INSERT. Alias should be created.
$post = new DemoPost();
$post->title = 'First post';
$post->slug = 'first-post';
check($post->save(), 'Owner insert succeeded');
$id = $post->id;
$target = 'news/view?id=' . $id;
$alias = SefRoute::findOne(['target' => $target]);
check($alias !== null && $alias->path === '/news/first-post', 'Behavior creates alias');

// Yii route -> canonical SEO path, retaining other GET params and arrays.
$url = $rule->createUrl($manager, 'news/view', [
    'id' => $id, 'page' => 2, 'filter' => ['new', 'top'],
]);
check(
    $url === 'news/first-post?page=2&filter%5B0%5D=new&filter%5B1%5D=top',
    'createUrl encodes additional query parameters'
);
check($rule->createUrl($manager, 'news/view', ['id' => 'unknown']) === false, 'Unknown target');
check($rule->createUrl($manager, 'news/view', ['id' => []]) === false, 'Invalid key');

// Canonical path -> Yii route and parameters.
$request = new DemoRequest();
$request->testPathInfo = 'news/first-post';
check(
    $rule->parseRequest($manager, $request) === ['news/view', ['id' => (string) $id]],
    'parseRequest resolves public path'
);
$request->testPathInfo = 'news/not-found';
check($rule->parseRequest($manager, $request) === false, 'Unknown path');

// Unrelated update must not change the mapping.
$post->title = 'Edited title';
check($post->save(), 'Unrelated update');
check(SefRoute::findOne(['target' => $target])->path === '/news/first-post', 'Unchanged slug');

// Slug update: keep same target, update path, remove the old public URL.
$post->slug = 'renamed-post';
check($post->save(), 'Slug update');
check(SefRoute::findOne(['target' => $target])->path === '/news/renamed-post', 'New canonical path');
$request->testPathInfo = 'news/first-post';
check($rule->parseRequest($manager, $request) === false, 'Old path is no longer active');

// Another record may not steal an existing public URL.
$other = new DemoPost();
$other->title = 'Other post';
$other->slug = 'another-post';
check($other->save(), 'Second post creation');

$other->slug = 'renamed-post';
try {
    $other->save();
    throw new RuntimeException('FAIL: conflicting URL did not throw');
} catch (IntegrityException $expected) {
    // Unique path constraint must reject the collision.
}
$reloaded = DemoPost::findOne($other->id);
check($reloaded->slug === 'another-post', 'Conflicting update rolled back owner');
check(
    SefRoute::findOne(['target' => 'news/view?id=' . $other->id])->path === '/news/another-post',
    'Conflicting update preserved old alias'
);

// Delete removes the alias in the same transaction.
check($post->delete() === 1, 'Owner deletion');
check(SefRoute::findOne(['target' => $target]) === null, 'Alias removed on delete');

// Manual static routes are supported without an id parameter.
$about = new SefRoute(['path' => '/about', 'target' => 'site/about']);
check($about->save(), 'Manual static route');
check($rule->createUrl($manager, 'site/about', []) === 'about', 'Static route createUrl');
$request->testPathInfo = 'about';
check($rule->parseRequest($manager, $request) === ['site/about', []], 'Static route parseRequest');

// Invalid path must reject the owning INSERT transaction.
$invalid = new DemoPost();
$invalid->title = 'Bad URL';
$invalid->slug = '../bad';
try {
    $invalid->save();
    throw new RuntimeException('FAIL: invalid path did not throw');
} catch (\yii\base\InvalidConfigException $expected) {
}
check(DemoPost::find()->where(['title' => 'Bad URL'])->count() === 0, 'Invalid URL rolls back insert');

$migration->safeDown();

echo "OK: Yii2 + SQLite integration (migration, routing, behavior, rollback)\n";
