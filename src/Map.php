<?php
namespace Haldayne\Boost;

use Haldayne\Boost\Contract\Arrayable;
use Haldayne\Boost\Contract\Jsonable;
use Haldayne\Boost\Lambda\Expression;

/**
 * An improvement on PHP associative arrays.
 *
 * Sports a fluent interface accepting callables to drive array operations,
 * similar in spirit to jQuery. Supports keys of any type: scalars, even
 * arrays and objects!  Used in place of `array ()` and `[]`, your code will
 * be easier to write *and* read.
 *
 * In the API, a formal variable named `$collection` must be one of:
 *   - Haldayne\Boost\Map
 *   - Haldayne\Boost\Contract\Arrayable
 *   - Haldayne\Boost\Contract\Jsonable
 *   - \Traversable
 *   - object
 *   - array
 *
 * Many of these methods allow you to apply code to all elements of the map:
 * look for formal parameters named `$code` or `$expression`. Some will allow
 * `callable`, which are the usual PHP callable types. Some will allow for
 * both `callable` *and* `string`.
 *
 * When given as string, an anonymous function is built for you, using the
 * string as the meat of the function. This lets you write extremely compact
 * expressions for filtering, at the one-time cost of converting the string
 * to the body of an anonymous function.  Inside these anonymous functions
 * the variable `$_0` holds the current value, while $_1 holds the current
 * key.
 *
 * In the API, a formal variable named `$key` may be of *any* type.
 *
 * As much as possible, method names were chosen to reflect synonymous usage in
 * the PHP engine itself. When not possible or relevant, the names may reflect
 * usage from Laravel.
 */
class Map implements \Countable, Arrayable, Jsonable, \ArrayAccess, \IteratorAggregate
{
    /**
     * Should the comparison be made loosely?
     * @see Map::diff
     * @see Map::intersect
     */
    const LOOSE = true;

    /**
     * Should the comparison be made strictly?
     * @see Map::diff
     * @see Map::intersect
     */
    const STRICT = false;

    /**
     * Create a new map.
     *
     * Initialize the map with the given collection, if any. Accepts any kind
     * of collection: array, object, Traversable, another Map, etc.
     *
     * Optionally, protect all `set`-like operations (`set`, `['foo']`, 
     * `push`, etc) with a guard callable. The guard callable receives the
     * proposed value as its first argument. If the guard returns boolean
     * `false`, an exception will be raised, otherwise the value will be set
     * into the map.
     *
     * @param Map|Arrayable|Jsonable|Traversable|object|array $collection
     * @param callable $guard
     * @throws \InvalidArgumentException
     */
    public function __construct($collection = null, callable $guard = null)
    {
        // set the guard first...
        $this->guard = $guard;

        // ... now add the given collection under supervision of that guard
        if (null !== $collection) {
            $array = $this->collection_to_array($collection);
            if (false === $array) {
                throw new \InvalidArgumentException(sprintf(
                    '$collection has type %s, which is not collection-like',
                    gettype($collection)
                ));
            }
            array_walk(
                $array,
                function ($v, $k) { $this->offsetSet($k, $v); }
            );
        }
    }

    /**
     * Return a new map containing only members of this map that pass the
     * expression.
     * 
     * You give an expression deciding whether an element is in or out, and
     * this returns a new map of those that are in. Ex, find both the odd and
     * even numbers:
     *
     * ```
     * $nums = new Map(range(0, 9));
     * $even = $nums->find(function ($val, $key) { return 0 == $val % 2; });
     * $odds = $nums->find('1 == ($_0 % 2)');
     * ```
     *
     * @param callable|string $expression
     * @return Map
     */
    public function find($expression)
    {
        return $this->grep($expression);
    }

    /**
     * Return a new map containing the first N elements passing the
     * expression.
     * 
     * Like `find`, but stop after finding N elements from the front. Defaults
     * to N = 1.
     *
     * ```
     * $nums = new Map(range(0, 9));
     * $odd3 = $nums->first('1 == ($_0 % 2)', 3); // first three odds
     * ``` 
     *
     * @param callable|string $expression
     * @param int $n
     * @return Map
     */
    public function first($expression, $n = 1)
    {
        if (is_numeric($n) && intval($n) <= 0) {
            throw new \InvalidArgumentException('Argument $n must be whole number');
        }
        return $this->grep($expression, intval($n));
    }

    /**
     * Return a new map containing the last N elements passing the expression.
     * 
     * Like `first`, but stop after finding N elements from the *end*.
     * Defaults to N = 1.
     *
     * ```
     * $nums = new Map(range(0, 9));
     * $odds = $nums->last('1 == ($_0 % 2)', 2); // last two odd numbers
     * ``` 
     *
     * @param callable|string $expression
     * @param int $n
     * @return Map
     */
    public function last($expression, $n = 1)
    {
        if (is_numeric($n) && intval($n) <= 0) {
            throw new \InvalidArgumentException('Argument $n must be whole number');
        }
        return $this->grep($expression, -intval($n));
    }

    /**
     * Test if every element passes the expression.
     *
     * @param callable|string $expression
     * @bool
     */
    public function every($expression)
    {
        return $this->grep($expression)->count() === $this->count();
    }

    /**
     * Test if at least one element passes the expression.
     *
     * @param callable|string $expression
     * @bool
     */
    public function some($expression)
    {
        return 1 === $this->first($expression)->count();
    }

    /**
     * Test that no elements pass the expression.
     *
     * @param callable|string $expression
     * @bool
     */
    public function none($expression)
    {
        return 0 === $this->first($expression)->count();
    }

    /**
     * Determine if a key exists the map.
     *
     * This is the object method equivalent of the magic isset($map[$key]);
     *
     * @param mixed $key
     * @return bool
     */
    public function has($key)
    {
        return $this->offsetExists($key);
    }

    /**
     * Get the value corresponding to the given key.
     *
     * If the key does not exist in the map, return the default.
     *
     * This is the object method equivalent of the magic $map[$key].
     *
     * @param mixed $key
     * @param mixed $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        if ($this->offsetExists($key)) {
            return $this->offsetGet($key);
        }
        return $default;
    }

    /**
     * Set a key and its corresponding value into the map.
     *
     * This is the object method equivalent of the magic $map[$key] = 'foo'.
     *
     * @param mixed $key
     * @param mixed $value
     * @return $this
     */
    public function set($key, $value)
    {
        $this->offsetSet($key, $value);
        return $this;
    }

    /**
     * Remove a key and its corresponding value from the map.
     *
     * This is the object method equivalent of the magic unset($map[$key]);
     *
     * @param mixed $key
     * @return $this
     */
    public function forget($key)
    {
        $this->offsetUnset($key);
        return $this;
    }

    /**
     * Determine if any key and their values have been set into the map.
     *
     * @return bool
     */
    public function isEmpty()
    {
        return 0 === $this->count();
    }

    /**
     * Return a new map containing those keys and values that are not present in
     * the given collection.
     *
     * If comparison is loose, then only those elements whose values match will
     * be removed.  Otherwise, comparison is strict, and elements whose keys 
     * and values match will be removed.
     *
     * @param Map|Arrayable|Jsonable|Traversable|object|array $collection
     * @param enum $comparison
     * @return Map
     */
    public function diff($collection, $comparison = Map::LOOSE)
    {
        $func = ($comparison === Map::LOOSE ? 'array_diff' : 'array_diff_assoc');
        return new static(
            $func($this->toArray(), $this->collection_to_array($collection))
        );
    }

    /**
     * Return a new map containing those keys and values that are present in
     * the given collection.
     *
     * If comparison is loose, then only those elements whose value match will
     * be included.  Otherise, comparison is strict, and elements whose keys &
     * values match will be included.
     *
     * @param Map|Arrayable|Jsonable|Traversable|object|array $collection
     * @param enum $comparison
     * @return Map
     */
    public function intersect($collection, $comparison = Map::LOOSE)
    {
        $func = ($comparison === Map::LOOSE ? 'array_intersect' : 'array_intersect_assoc');
        return new static(
            $func($this->toArray(), $this->collection_to_array($collection))
        );
    }

    /**
     * Execute the given code over each element of the map. The code receives
     * the value by reference and then the key as formal parameters.
     *
     * The items are walked in the order they exist in the map. If the code
     * returns boolean false, then the iteration halts. Values can be modified
     * from within the callback, but not keys.
     *
     * Example:
     * ```
     * $map->each(function (&$value, $key) { $value++; return true; })->sum();
     * ```
     *
     * @param callable $code
     * @return $this
     */
    public function walk(callable $code)
    {
        foreach ($this->array as $hash => &$value) {
            $key = $this->hash_to_key($hash);
            if (! $this->passes($this->call($code, $value, $key))) {
                break;
            }
        }
        return $this;
    }

    /**
     * Like `walk`, except walk from the end toward the front.
     *
     * @param callable $code
     * @return $this
     */
    public function walk_backward(callable $code)
    {
        for (end($this->array); null !== ($hash = key($this->array)); prev($this->array)) {
            $key   = $this->hash_to_key($hash);
            $value =& current($this->array);
            if (! $this->passes($this->call($code, $value, $key))) {
                break;
            }
        }
        return $this;
    }

    /**
     * Groups elements of this map based on the result of an expression.
     *
     * Calls the expression for each element in this map. The expression
     * receives the value and key, respectively.  The expression may return
     * any value: this value is the grouping key and the element is put into
     * that group.
     *
     * ```
     * $nums = new Map(range(0, 9));
     * $part = $nums->partition(function ($value, $key) {
     *    return 0 == $value % 2 ? 'even' : 'odd';
     * });
     * var_dump(
     *     $part['odd']->count(), // 5
     *     array_sum($part['even']->toArray()) // 20
     * );
     * ```
     *
     * @param callable|string $expression
     * @return MapOfMaps
     */
    public function partition($expression)
    {
        $outer = new MapOfMaps();
        $proto = new static([], $this->guard);

        $this->walk(function ($v, $k) use ($expression, $outer, $proto) {
            $partition = $this->call($expression, $v, $k);

            $inner = $outer->has($partition) ? $outer->get($partition) : clone $proto;
            $inner->set($k, $v);

            $outer->set($partition, $inner);
        });

        return $outer;
    }

    /**
     * Walk the map, applying the expression to every element, transforming
     * them into a new map.
     *
     * ```
     * $nums = new Map(range(0, 9));
     * $doubles = $nums->map('$_0 * 2');
     * ```
     *
     * The expression receives two arguments:
     *   - The current value
     *   - The current key
     *
     * The keys in the resulting map will be the same as the keys in the 
     * original map: only the values have (potentially) changed.
     *
     * @param callable|string $expression
     * @return static
     */
    public function map($expression)
    {
        $new = new static();

        $this->walk(function ($v, $k) use ($expression, $new) {
            $new[$k] = $this->call($expression, $v, $k);
        });

        return $new;
    }

    /**
     * Walk the map, applying the expression to every element, so as to reduce
     * them to a single value.
     *
     * The expression receives three arguments:
     *   - The current reduction
     *   - The current value
     *   - The current key
     * 
     * The first time the expression is called, the current reduction is the
     * given initial value.
     *
     * ```
     * $nums = new Map(range(0, 3));
     * $sum = $nums->reduce(function ($sum, $value) { return $sum + $value; });
     * // $sum == 6
     * ```
     *
     * If $final is given and a callable expression, call it as the last
     * step after reducing the map.
     *
     * @param callable|string $expression
     * @param mixed $initial
     * @param callable|null $final
     * @return mixed
     *
     * @see http://php.net/manual/en/function.array-reduce.php
     */
    public function reduce($expression, $initial = null, callable $final = null)
    {
        $reduced = $initial;
        $this->walk(function ($v, $k) use ($expression, &$reduced) {
            $reduced = $this->call($expression, $reduced, $v, $k);
        });

        return is_callable($final) ? $final($reduced) : $reduced;
    }

    /**
     * Apply the filter to every element, creating a new map with only those
     * elements from the original map that do not fail this filter.
     *
     * The filter expressions receives two arguments:
     *   - The current value
     *   - The current key
     *
     * If the filter returns exactly boolean false, the element is not copied
     * into the new map.  Otherwise, it is.  Keys from the original map carry
     * into the new map.
     *
     * @param callable|string $expression
     * @return static
     */
    public function filter($expression)
    {
        $new = new static();

        $this->walk(function ($v, $k) use ($expression, $new) {
            $result = $this->call($expression, $v, $k);
            if ($this->passes($result)) {
                $new[$k] = $v;
            }
        });

        return $new;
    }

    /**
     * Apply the expression to each element of the map, and creating a new
     * map with keys corresponding to the expression's return value.
     *
     * ```
     * $counted_by_byte = new Map(count_chars('war of the worlds', 1));
     * $keyed_by_letter = $counted_by_byte->rekey('chr($_1)');
     * ```
     *
     * @param callable|string $expression
     * @return static
     */
    public function rekey($expression)
    {
        $new = new static();

        $this->walk(function ($v, $k) use ($expression, $new) {
            $new_key = $this->call($expression, $v, $k);
            $new[$new_key] = $v;
        });

        return $new;
    }

    /**
     * Treat the map as a stack and push an element onto its end.
     *
     * @return $this
     */
    public function push($element)
    {
        $this->offsetSet(null, $element);
        return $this;
    }

    /**
     * Treat the map as a stack and pop an element off its end.
     *
     * @return mixed
     */
    public function pop()
    {
        // get last key of array
        end($this->array);
        $hash = key($this->array);

        // destructively get the element there
        $element = $this->array[$hash];
        unset($this->array[$hash]);

        // return it
        return $element;
    }

    // ------------------------------------------------------------------------
    // implements \Countable

    /**
     * Count the number of items in the map.
     *
     * @return int
     */
    public function count()
    {
        return count($this->array);
    }

    // ------------------------------------------------------------------------
    // implements Arrayable

    /**
     * Copy this map into an array, recursing as necessary to convert contained
     * collections into arrays.
     */
    public function toArray()
    {
        $outer = [];
        foreach ($this->array as $hash => $value) {
            $key   = $this->hash_to_key($hash);
            $inner = $this->collection_to_array($value);
            $outer[$key] = (false === $inner ? $value : $inner);
        }
        return $outer;
    }

    // ------------------------------------------------------------------------
    // implements Jsonable

    /**
     * {@inheritDoc}
     */
    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
    }

    // ------------------------------------------------------------------------
    // implements \ArrayAccess

    /**
     * Determine if a value exists at a given key.
     *
     * @param mixed $key
     * @return bool
     */
    public function offsetExists($key)
    {
        $hash = $this->key_to_hash($key);
        return array_key_exists($hash, $this->array);
    }

    /**
     * Get a value at a given key.
     *
     * @param mixed $key
     * @return mixed
     */
    public function offsetGet($key)
    {
        $hash = $this->key_to_hash($key);
        return $this->array[$hash];
    }

    /**
     * Set the value at a given key.
     *
     * If key is null, the value is appended to the array using numeric indexes.
     * If the map was constructed with a set guard, then pass the value to the
     * guard. If the guard fails, the set throws an exception.
     *
     * @param mixed $key
     * @param mixed $value
     * @return void
     * @throws \UnexpectedValueException
     */
    public function offsetSet($key, $value)
    {
        // check the value passes our guard, if a guard is defined
        if (null !== $this->guard) {
            $guard = $this->guard;
            if (! $this->passes($guard($value))) {
                throw new \UnexpectedValueException('Value did not pass guard');
            }
        }

        // set the value into our internal array
        if (null === $key) {
            // ask PHP to give me the next index
            // http://stackoverflow.com/q/3698743/2908724
            $this->array[] = 'probe';
            end($this->array);
            $next = key($this->array);
            unset($this->array[$next]);

            // hash that
            $hash = $this->key_to_hash($next);
        } else {
            $hash = $this->key_to_hash($key);
        }
        $this->array[$hash] = $value;
    }

    /**
     * Unset the value at a given key.
     *
     * @param mixed $key
     * @return void
     */
    public function offsetUnset($key)
    {
        unset($this->array[$this->key_to_hash($key)]);
    }

    // ------------------------------------------------------------------------
    // implements \IteratorAggregate

    /**
     * Get an iterator for a copy of the map.
     *
     * @return \ArrayIterator
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->toArray());
    }


    // ========================================================================
    // PRIVATE API

    /**
     * The internal array representation of the map.
     * @var array
     */
    private $array = [];

    /**
     * The guard code protecting sets.
     * @var callable|string|null
     */
    private $guard = null;

    /**
     * Track hashes we've created for non-string keys.
     * @var array
     */
    private $map_key_to_hash = [];

    /**
     * Give me a native PHP array, regardless of what kind of collection-like
     * structure is given.
     *
     * @param Map|Traversable|Arrayable|Jsonable|object|array $items
     * @return array|boolean
     */
    private function collection_to_array($collection)
    {
        if ($collection instanceof self) {
            return $collection->toArray();

        } else if ($collection instanceof \Traversable) {
            return iterator_to_array($collection);

        } else if ($collection instanceof Arrayable) {
            return $collection->toArray();

        } else if ($collection instanceof Jsonable) {
            return json_decode($collection->toJson(), true);

        } else if (is_object($collection) || is_array($collection)) {
            return (array)$collection;

        } else {
            return false;
        }
    }

    /**
     * Calls the given code with the given value and key as first & second argument.
     * 
     * @param callable|string $expression
     * @param mixed $value
     * @param string $key
     * @throws \InvalidArgumentException
     */
    private function call($expression, &$value, $key)
    {
        $callable = new Expression($expression);
        return $callable($value, $key);
    }

    /**
     * Decide if the given value is considered "passing" or "failing".
     *
     * @param mixed $value
     * @return bool
     */
    private function passes($value)
    {
        return false === $value ? false : true;
    }

    /**
     * Lookup the hash for the given key. If a hash does not yet exist, one is
     * created.
     *
     * @param mixed $key
     * @return string
     * @throws \InvalidArgumentException
     */
    private function key_to_hash($key)
    {
        if (array_key_exists($key, $this->map_key_to_hash)) {
            return $this->map_key_to_hash[$key];
        }

        if (is_float($key) || is_int($key) || is_bool($key)) {
            $hash = intval($key);

        } else if (is_string($key)) {
            $hash = "s_$key";

        } else if (is_object($key) || is_callable($key)) {
            $hash = spl_object_hash($key);

        } else if (is_array($key)) {
            $hash = 'a_' . md5(json_encode($key));

        } else if (is_resource($key)) {
            $hash = "r_$key";

        } else {
            throw new \InvalidArgumentException(
                'Key has type %s, which is not supported',
                gettype($key)
            );
        }

        $this->map_key_to_hash[$key] = $hash;
        return $hash;
     }

     /**
      * Lookup the key for the given hash.
      *
      * @param string $hash
      * @return mixed
      */
     private function hash_to_key($hash)
     {
        foreach ($this->map_key_to_hash as $key => $candidate) {
            if ($hash === $candidate) {
                return $key;
            }
        }

        throw new \OutOfBoundsException(sprintf(
            'Hash "%s" has not been created',
            $hash
        ));
     }

    /**
     * Finds elements for which the given code passes, optionally limited to a
     * maximum count.
     *
     * If limit is null, no limit on number of matches. If limit is positive,
     * return that many from the front of the array. If limit is negative,
     * return that many from the end of the array.
     *
     * @param callable|string $expression
     * @param int|null $limit
     * @return Map
     */
    private function grep($expression, $limit = null)
    {
        // initialize our return map and bookkeeping values
        $map = new static([], $this->guard);
        $bnd = empty($limit) ? null : abs($limit);
        $cnt = 0;

        // define a helper to add matching values to our new map, stopping when
        // any designated limit is reached
        $helper = function ($value, $key) use ($expression, $map, $bnd, &$cnt) {
            if ($this->passes($this->call($expression, $value, $key))) {
                $map->set($key, $value);
                if (null !== $bnd && $bnd <= ++$cnt) {
                    return false;
                }
            }
        };

        // walk the array in the right direction
        if (0 <= $limit) {
            $this->walk($helper);

        } else {
            $this->walk_backward($helper);
        }

        return $map;
    }
}
