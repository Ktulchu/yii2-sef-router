<?php

namespace rkdev\sef;

use yii\base\Behavior;
use yii\base\InvalidConfigException;
use yii\db\ActiveRecord;
use yii\db\AfterSaveEvent;

/**
 * Synchronize one SefRoute for an ActiveRecord owner.
 *
 * For atomic changes, the owner must save/delete inside a transaction
 * (for example by enabling Yii's ActiveRecord::transactions()).
 */
class SefBehavior extends Behavior
{
    /** @var string Yii route without a leading slash, e.g. news/view. */
    public $route;

    /** @var string Owner's existing slug attribute; this class does not transliterate. */
    public $slugAttribute = 'slug';

    /** @var string Public path prefix, e.g. news or blog/articles. */
    public $prefix = '';

    /** @var string Owner attribute containing its identity, e.g. id. */
    public $keyAttribute = 'id';

    /** @var string URL parameter name used in the internal Yii route. */
    public $keyParam = 'id';

    /** @var class-string<SefRoute> */
    public $routeModelClass = SefRoute::class;

    public function events()
    {
        return [
            ActiveRecord::EVENT_AFTER_INSERT => 'onAfterInsert',
            ActiveRecord::EVENT_AFTER_UPDATE => 'onAfterUpdate',
            ActiveRecord::EVENT_AFTER_DELETE => 'onAfterDelete',
        ];
    }

    public function onAfterInsert(AfterSaveEvent $event)
    {
        $this->synchronize();
    }

    public function onAfterUpdate(AfterSaveEvent $event)
    {
        // No query at all for updates unrelated to the URL.
        if (array_key_exists($this->slugAttribute, $event->changedAttributes)) {
            $this->synchronize();
        }
    }

    public function onAfterDelete($event)
    {
        $class = $this->routeModelClass;
        $class::deleteAll(['target' => $this->buildTarget()]);
    }

    private function synchronize()
    {
        $class = $this->routeModelClass;
        $target = $this->buildTarget();
        $path = $this->buildPath();

        $alias = $class::find()->where(['target' => $target])->one();
        if ($alias === null) {
            $alias = new $class();
            $alias->target = $target;
        } elseif ($alias->path === $path) {
            return;
        }

        $alias->path = $path;
        // Database unique constraints protect against a conflicting public
        // path or target. Never silently overwrite an existing mapping.
        if (!$alias->save(false)) {
            throw new \RuntimeException('Unable to save SEO route: ' . $target);
        }
    }

    private function buildTarget()
    {
        $route = trim((string) $this->route, '/');
        if ($route === '' || strpbrk($route, '?#') !== false) {
            throw new InvalidConfigException('SefBehavior::route must be a plain Yii route');
        }

        $key = $this->owner->{$this->keyAttribute};
        if (!is_scalar($key) || $key === '') {
            throw new InvalidConfigException('Owner key attribute is empty');
        }
        return $route . '?' . http_build_query(
            [$this->keyParam => $key], '', '&', PHP_QUERY_RFC3986
        );
    }

    private function buildPath()
    {
        $slug = trim((string) $this->owner->{$this->slugAttribute}, '/');
        $prefix = trim($this->prefix, '/');
        $path = $prefix === '' ? $slug : $prefix . '/' . $slug;

        if ($slug === '' || strpbrk($path, '?#\\') !== false) {
            throw new InvalidConfigException('SEO slug must be a non-empty URL path without query or fragment');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidConfigException('SEO URL cannot contain empty or traversal segments');
            }
        }

        if (strlen($path) > 254) {
            throw new InvalidConfigException('SEO path exceeds 255 characters');
        }

        return '/' . $path;
    }
}
