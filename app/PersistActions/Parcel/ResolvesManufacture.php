<?php

namespace App\PersistActions\Parcel;

use App\Models\Manufacture;

trait ResolvesManufacture
{
    /**
     * @param string|int $id
     * @return int|null
     */
    private function resolveManufactureId($id): ?int
    {
        static $map = [];
        if (!isset($map[$id])) {
            if (is_numeric($id)) {
                $map[$id] = (int)$id;
            } else {
                $map[$id] = optional(Manufacture::where('mongo_id', $id)->first())->id;
            }
        }

        return $map[$id];
    }
}
