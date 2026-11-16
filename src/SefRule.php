<?php

namespace rkdev\sef;

use yii\base\BaseObject;
use yii\web\UrlRuleInterface;

/**
 * Bidirectional lookup: /news/a-post <=> news/view?id=123.
 *
 * Return false for routes we do not own so the next Yii URL rule can handle them.
 */
class SefRule extends BaseObject implements UrlRuleInterface
{
    /** @var class-string<SefRoute> */
    public $routeModelClass = SefRoute::class;

    /** @var string Parameter identifying the record (default: id). */
    public $keyParam = 'id';

    public function createUrl($manager, $route, $params)
    {
        $route = ltrim($route, '/');

        // Only keyParam is consumed by the alias mapping. All other GET
        // parameters are passed through unchanged.
        if (array_key_exists($this->keyParam, $params)) {
            $id = $params[$this->keyParam];
            if (!is_scalar($id) || $id === '') {
                return false;
            }
            $target = $route . '?' . http_build_query(
                [$this->keyParam => $id], '', '&', PHP_QUERY_RFC3986
            );
            unset($params[$this->keyParam]);
        } else {
            // Also supports manually configured static routes, e.g. site/about.
            $target = $route;
        }

        $class = $this->routeModelClass;
        $alias = $class::find()->where(['target' => $target])->one();
        if ($alias === null) {
            return false;
        }

        $url = ltrim($alias->path, '/');
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        return $query === '' ? $url : $url . '?' . $query;
    }

    public function parseRequest($manager, $request)
    {
        $path = '/' . trim($request->getPathInfo(), '/');

        $class = $this->routeModelClass;
        $alias = $class::find()->where(['path' => $path])->one();
        if ($alias === null) {
            return false;
        }

        [$route, $query] = array_pad(explode('?', $alias->target, 2), 2, '');
        $params = [];
        if ($query !== '') {
            parse_str($query, $params);
        }

        return [$route, $params];
    }
}
