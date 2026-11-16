# Yii2 SEF Router

Database-driven, bidirectional SEO URLs for Yii2. Turn an internal Yii route such as
`news/view?id=123` into `/news/my-article`, and back, without hardcoding
a separate `UrlRule` for every record.

Includes a small migration, ActiveRecord model, and behavior to keep URLs in sync with the owner.

## Install

```bash
composer require ktulchu/yii2-sef-router
```

The package must be registered on Packagist (or added to Composer as a VCS repository) before Composer can resolve it.

Apply the migration explicitly:

```bash
php yii migrate --migrationPath=@vendor/ktulchu/yii2-sef-router/src/migrations
```

This creates one table:

| Column | Purpose |
| --- | --- |
| `id` | Primary key |
| `path` | Unique public URL path, e.g. `/news/my-article` |
| `target` | Unique Yii route and record key, e.g. `news/view?id=123` |

There is no duplicate `seourl` column. Both mapping directions are backed by unique indexes. Existing applications migrating from a different schema must transfer data separately.

## Configure Yii URL manager

```php
'urlManager' => [
    'enablePrettyUrl' => true,
    'showScriptName' => false,
    'rules' => [
        ['class' => \rkdev\sef\SefRule::class],
        // ...other URL rules...
    ],
],
```

The rule returns `false` for unmapped paths and routes, so other rules may handle them.
Place this rule before more general or catch-all rules.

## Automatically maintain a route for an AR model

```php
use rkdev\sef\SefBehavior;
use yii\db\ActiveRecord;

class Article extends ActiveRecord
{
    public function behaviors()
    {
        return [
            'sef' => [
                'class' => SefBehavior::class,
                'route' => 'news/view',
                'slugAttribute' => 'slug', // existing owner attribute
                'prefix' => 'news',
            ],
        ];
    }

    public function transactions()
    {
        // IMPORTANT: makes the owner and its SEO route atomic.
        return ['default' => self::OP_INSERT | self::OP_UPDATE | self::OP_DELETE];
    }
}
```

Now a normal `$article->save()` creates or updates the corresponding mapping
as soon as the owner has an ID. Deleting the owner deletes its mapping. Updates to
unrelated attributes do not cause a route query.

**Atomicity:** The behavior uses Yii's `EVENT_AFTER_INSERT`, `EVENT_AFTER_UPDATE`
and `EVENT_AFTER_DELETE`. Enable AR transactions as above, or wrap each owner
`save()`/`delete()` in an explicit database transaction. Otherwise a failed
alias write may leave the owner saved without a matching URL. Database unique constraints
prevent aliases from silently overwriting each other.

The behavior does **not** generate or transliterate slugs. The application owns the slug
field and its validation. Supported options:

| Option | Default | Meaning |
| --- | --- | --- |
| `route` | required | Yii route, e.g. `news/view` |
| `slugAttribute` | `slug` | Owner's slug attribute |
| `prefix` | empty | Public path prefix |
| `keyAttribute` | `id` | Owner's record key attribute |
| `keyParam` | `id` | Query parameter in the target |
| `routeModelClass` | `SefRoute::class` | Custom route ActiveRecord |

When changing `keyParam` on the behavior, set the same option on the
`SefRule`. The default is `id`.

## How URL mapping works

Given a record with ID `123` and slug `my-article`:

```text
Yii::getAlias / Url::to(['news/view', 'id' => 123, 'page' => 2])
                |
                v
/news/my-article?page=2

Browser requests /news/my-article
                |
                v
news/view?id=123   --> NewsController::actionView(123)
```

Other GET parameters are encoded using `http_build_query`, including arrays.
Static routes (e.g. `/about` -> `site/about`) can be inserted manually.

## Scope of 0.x

- MySQL, PostgreSQL, and SQLite through Yii2's ActiveRecord / Query Builder.
- The behavior owns **one canonical path** per `route + key`.
- An update to the slug **replaces** the URL: old URLs are not redirected.
- No path ranking, caching, automatic transliteration or 301 redirects yet.
- Route collisions with unrelated static rules must be avoided by the application.
- A database lookup is performed for every mapped reverse/forward lookup.

## Tests

```bash
composer install
php tests/integration.php
```

GitHub Actions runs the same integration test against PHP 8.0, 8.2 and 8.4.

## License

MIT.
