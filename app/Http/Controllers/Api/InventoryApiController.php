<?php

namespace Modules\Inventory\Http\Controllers\Api;

use Illuminate\Database\QueryException;
use Modules\Core\Http\Controllers\Api\ResourceApiController;
use RuntimeException;

/**
 * Inventory services signal rule violations with RuntimeException; surface
 * those as 422 (database errors excluded, so SQL never reaches clients).
 */
abstract class InventoryApiController extends ResourceApiController
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function domain(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new \DomainException($e->getMessage(), 0, $e);
        }
    }
}
