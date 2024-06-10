<?php

namespace NabilHassen\LaravelUsageLimiter\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use NabilHassen\LaravelUsageLimiter\Contracts\Limit as ContractsLimit;
use NabilHassen\LaravelUsageLimiter\Exceptions\LimitAlreadyExists;
use NabilHassen\LaravelUsageLimiter\Exceptions\LimitDoesNotExist;
use NabilHassen\LaravelUsageLimiter\Traits\RefreshCache;

class Limit extends Model implements ContractsLimit
{
    use RefreshCache, SoftDeletes;

    protected $guarded = ['id', 'created_at', 'updated_at', 'deleted_at'];

    public static array $resetFrequencyPossibleValues = [
        'every second',
        'every minute',
        'every hour',
        'every day',
        'every week',
        'every two weeks',
        'every month',
        'every quarter',
        'every six months',
        'every year',
    ];

    public function __construct(array $attributes = [])
    {
        $this->table = config('limit.tables.limits') ?: parent::getTable();
    }

    public static function create(array $data): ContractsLimit
    {
        return static::findOrCreate($data, true);
    }

    public static function findOrCreate(array $data, bool $throw = false): ContractsLimit
    {
        $data = static::validateArgs($data);

        $limit = static::query()
            ->where('name', $data['name'])
            ->when(isset($data['plan']), fn(Builder $q) => $q->where('plan', $data['plan']), fn(Builder $q) => $q->whereNull('plan'))
            ->first();

        if ($limit && !$throw) {
            return $limit;
        }

        if ($limit && $throw) {
            throw new LimitAlreadyExists($data['name'], $data['plan'] ?? null);
        }

        return static::query()->create($data);
    }

    protected static function validateArgs(array $data): array
    {
        if (!Arr::has($data, ['name', 'allowed_amount'])) {
            throw new InvalidArgumentException('"name" and "allowed_amount" keys do not exist on the array.');
        }

        if (!is_numeric($data['allowed_amount']) || $data['allowed_amount'] < 0) {
            throw new InvalidArgumentException('"allowed_amount" should be a float|int type and greater than or equal to 0.');
        }

        if (
            Arr::has($data, ['reset_frequency']) &&
            filled($data['reset_frequency']) &&
            array_search($data['reset_frequency'], static::$resetFrequencyPossibleValues) === false
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid "reset_frequency" value. Value of "reset_frequency" should be one the following: %s',
                    implode(', ', static::$resetFrequencyPossibleValues)
                )
            );
        }

        if (isset($data['plan']) && blank($data['plan'])) {
            unset($data['plan']);
        }

        return $data;
    }

    public static function findByName(string $name, ?string $plan = null): ContractsLimit
    {
        $limit = static::query()
            ->where('name', $name)
            ->when(filled($plan), fn(Builder $q) => $q->where('plan', $plan), fn(Builder $q) => $q->whereNull('plan'))
            ->first();

        if (!$limit) {
            throw new LimitDoesNotExist($name, $plan);
        }

        return $limit;
    }

    public static function findById(int $id): ContractsLimit
    {
        $limit = static::find($id);

        if (!$limit) {
            throw new LimitDoesNotExist($id);
        }

        return $limit;
    }

    public function incrementBy(float|int $amount = 1.0): bool
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('"amount" should be greater than 0.');
        }

        $this->allowed_amount += $amount;

        return $this->save();
    }

    public function decrementBy(float|int $amount = 1.0): bool
    {
        $this->allowed_amount -= $amount;

        if ($this->allowed_amount < 0) {
            throw new InvalidArgumentException('"allowed_amount" should be greater than or equal to 0.');
        }

        return $this->save();
    }
}
