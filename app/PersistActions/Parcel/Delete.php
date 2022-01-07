<?php

namespace App\PersistActions\Parcel;

use App\Models\Log;
use App\Models\Parcel;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class Delete
{
    private ConnectionInterface $db;

    private Parcel $parcel;

    public function __construct(ConnectionInterface $db, Parcel $parcel)
    {
        $this->parcel = $parcel;
        $this->db = $db;
    }

    /**
     * @return bool
     * @throws \Throwable
     */
    public function __invoke(): bool
    {
        $logs = $this->makeLogs();
        $this->db->transaction(function () use ($logs): void {
            $this->incrementQuantity();
            $this->parcel->delete();
            Log::insert($logs);
        });
//        ResponseCache::clear([DetailDataTable::class]);

        return true;
    }

    private function incrementQuantity(): void
    {
        $sql = 'INSERT INTO detail_manufacture (detail_id, manufacture_id, quantity, tested_quantity) VALUES ';
        $bindings = [];
        foreach ($this->parcel->components as $part) {
            $sql .= '(?,?,?,?),';
            $bindings[] = $part->detail_id;
            $bindings[] = $this->parcel->sender_manufacture_id;
            $bindings[] = $part->detail->requires_testing ? 0 : $part->quantity;
            $bindings[] = $part->detail->requires_testing ? $part->quantity : 0;
        }
        if ($bindings) {
            $sql = rtrim($sql, ',') . ' ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity),tested_quantity=tested_quantity+VALUES(tested_quantity)';
            $this->db->insert($sql, $bindings);
        }
    }

    /**
     * @return array
     */
    private function makeLogs(): array
    {
        $logs = [];
        $now = Carbon::now();
        $userId = Auth::id();
        foreach ($this->parcel->components as $part) {
            $logs[] = [
                'action' => Parcel::ACTION_DELETED,
                'quantity' => $part->quantity,
                'detail_id' => $part->detail_id,
                'warehouse_id' => $this->parcel->sender_manufacture_id,
                'user_id' => $userId,
                'comment' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        return $logs;
    }
}
