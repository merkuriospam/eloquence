<?php namespace Sofa\Eloquence;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Sofa\Eloquence\Pipeline\Pipeline;
use Sofa\Eloquence\Contracts\Mutator as MutatorContract;
use Sofa\Eloquence\Mutator\Mutator;

/**
 * @version 0.4
 *
 * @method \Illuminate\Database\Connection getConnection()
 * @method string getTable()
 */
trait Eloquence
{
    /**
     * Attribute mutator instance.
     *
     * @var \Sofa\Eloquence\Contracts\Mutator
     */
    protected static $attributeMutator;

    /**
     * Model's table column listing.
     *
     * @var array
     */
    protected static $columnListing = [];

    /**
     * Wrapped hooks on Eloquent methods.
     *
     * @var array
     */
    protected static $wrappedHooks = [];

    /**
     * Unwrapped hooks bound to the instance.
     *
     * @var array
     */
    protected $unwrappedHooks = [];

    /**
     * Boot the trait.
     *
     * @codeCoverageIgnore
     *
     * @return void
     */
    public static function bootEloquence()
    {
        if (!isset(static::$attributeMutator)) {
            if (function_exists('app') && isset(app()['eloquence.mutator'])) {
                static::setAttributeMutator(app('eloquence.mutator'));
            } else {
                static::setAttributeMutator(new Mutator);
            }
        }
    }

    /**
     * Register hook on Eloquent method.
     *
     * @param  string  $method
     * @param  string  $hook
     * @return void
     */
    public static function hook($method, $hook)
    {
        static::$wrappedHooks[$method][] = static::wrapHook($hook);
    }

    /**
     * Wrap hook method in a closure so it can be bound to the instance later.
     *
     * @param  string $hook
     * @return void
     */
    protected static function wrapHook($hook)
    {
        return function ($model) use ($hook) {
            return $model->{$hook}();
        };
    }

    /**
     * Unwrap hooks, in order to bind them to instance context, so they can use $this.
     *
     * @param  string $method
     * @return void
     */
    protected function unwrapHooks($method)
    {
        if (array_key_exists($method, $this->unwrappedHooks)) {
            return;
        }

        $this->unwrappedHooks[$method] = [];

        if (static::hasHook($method)) {
            foreach (static::$wrappedHooks[$method] as $wrapped) {
                $this->unwrappedHooks[$method][] = call_user_func($wrapped, $this);
            }
        }
    }

    /**
     * Determine whether a method has any hooks registered.
     *
     * @param  string  $method
     * @return boolean
     */
    public static function hasHook($method)
    {
        return array_key_exists($method, static::$wrappedHooks);
    }

    /**
     * Determine whether the key is meta attribute or actual table field.
     *
     * @param  string  $key
     * @return boolean
     */
    public static function hasColumn($key)
    {
        static::loadColumnListing();

        return in_array($key, static::$columnListing);
    }

    /**
     * Get model table columns.
     *
     * @return array
     */
    public static function getColumnListing()
    {
        static::loadColumnListing();

        return static::$columnListing;
    }

    /**
     * Fetch model table columns.
     *
     * @return void
     */
    protected static function loadColumnListing()
    {
        if (empty(static::$columnListing)) {
            $instance = new static;

            static::$columnListing = $instance->getConnection()
                                        ->getSchemaBuilder()
                                        ->getColumnListing($instance->getTable());
        }
    }

    /**
     * Create new Eloquence query builder for the instance.
     *
     * @param  \Illuminate\Database\Query\Builder $query
     * @return \Sofa\Eloquence\Builder
     */
    public function newEloquentBuilder($query)
    {
        return new Builder($query);
    }

    /**
     * Set attribute mutator instance.
     *
     * @codeCoverageIgnore
     *
     * @param  \Sofa\Eloquence\Contracts\Mutator $mutator
     * @return void
     */
    public static function setAttributeMutator(MutatorContract $mutator)
    {
        static::$attributeMutator = $mutator;
    }

    /**
     * Get attribute mutator instance.
     *
     * @codeCoverageIgnore
     *
     * @return \Sofa\Eloquence\Contracts\Mutator
     */
    public static function getAttributeMutator()
    {
        return static::$attributeMutator;
    }

    /*
    |--------------------------------------------------------------------------
    | Register hooks
    |--------------------------------------------------------------------------
    */

    /**
     * Allow custom where method calls on the builder.
     *
     * @codeCoverageIgnore
     *
     * @param  \Sofa\Eloquence\Builder  $query
     * @param  string  $method
     * @param  \Sofa\Eloquence\ArgumentBag  $args
     * @return \Sofa\Eloquence\Builder
     */
    public function queryHook(Builder $query, $method, ArgumentBag $args)
    {
        $this->unwrapHooks(__FUNCTION__);
        $pipes = $this->unwrappedHooks[__FUNCTION__];

        return (new Pipeline($pipes))
                ->send($query)
                ->with(new ArgumentBag(['method' => $method, 'args' => $args]))
                ->to(function ($query) use ($method, $args) {
                    return call_user_func_array([$query, 'callParent'], [$method, $args->all()]);
                });
    }

    /**
     * Register hook for getAttribute.
     *
     * @codeCoverageIgnore
     *
     * @param  string $key
     * @return mixed
     * @return mixed
     */
    public function getAttribute($key)
    {
        $this->unwrapHooks(__FUNCTION__);
        $pipes = $this->unwrappedHooks[__FUNCTION__];
        $parcel = parent::getAttribute($key);

        return (new Pipeline($pipes))
                ->send($parcel)
                ->with(new ArgumentBag(compact('key')))
                ->to(function ($attribute) {
                    return $attribute;
                });
    }

    /**
     * Register hook for setAttribute.
     *
     * @codeCoverageIgnore
     *
     * @param  string $key
     * @param  mixed  $value
     * @return void
     */
    public function setAttribute($key, $value)
    {
        $this->unwrapHooks(__FUNCTION__);
        $pipes = array_reverse($this->unwrappedHooks[__FUNCTION__]);

        return (new Pipeline($pipes))
                ->send($value)
                ->with(new ArgumentBag(compact('key')))
                ->to(function ($value) use ($key) {
                    parent::setAttribute($key, $value);
                });
    }

    /**
     * Register hook for save.
     *
     * @codeCoverageIgnore
     *
     * @param  array  $options
     * @return boolean
     */
    public function save(array $options = [])
    {
        $this->unwrapHooks(__FUNCTION__);
        $pipes = $this->unwrappedHooks[__FUNCTION__];
        $saved = parent::save($options);

        if (!$saved) {
            return false;
        }

        return (new Pipeline($pipes))
                ->send(true)
                ->with(new ArgumentBag(compact('options')))
                ->to(function () {
                    return true;
                });
    }

    /**
     * Register hook for toArray.
     *
     * @codeCoverageIgnore
     *
     * @return mixed
     */
    public function toArray()
    {
        $this->unwrapHooks(__FUNCTION__);
        $pipes = $this->unwrappedHooks[__FUNCTION__];
        $parcel = parent::toArray();

        return (new Pipeline($pipes))
                ->send($parcel)
                ->to(function ($array) {
                    return $array;
                });
    }

    /**
     * Register hook for replicate.
     *
     * @codeCoverageIgnore
     *
     * @return mixed
     */
    public function replicate(array $except = null)
    {
        $this->unwrapHooks(__FUNCTION__);
        $pipes = $this->unwrappedHooks[__FUNCTION__];
        $parcel = parent::replicate($except);
        $original = $this;

        return (new Pipeline($pipes))
                ->send($parcel)
                ->with(new ArgumentBag(compact('except', 'original')))
                ->to(function ($copy) {
                    return $copy;
                });
    }

    /**
     * Register hook for isset call.
     *
     * @codeCoverageIgnore
     *
     * @param  string  $key
     * @return boolean
     */
    public function __isset($key)
    {
        $this->unwrapHooks(__FUNCTION__);
        $pipes = $this->unwrappedHooks[__FUNCTION__];
        $parcel = parent::__isset($key);

        return (new Pipeline($pipes))
                ->send($parcel)
                ->with(new ArgumentBag(compact('key')))
                ->to(function ($isset) {
                    return $isset;
                });
    }

    /**
     * Register hook for isset call.
     *
     * @codeCoverageIgnore
     *
     * @param  string  $key
     * @return boolean
     */
    public function __unset($key)
    {
        $this->unwrapHooks(__FUNCTION__);
        $pipes = $this->unwrappedHooks[__FUNCTION__];

        return (new Pipeline($pipes))
                ->send(false)
                ->with(new ArgumentBag(compact('key')))
                ->to(function () use ($key) {
                    return call_user_func('parent::__unset', $key);
                });
    }
}