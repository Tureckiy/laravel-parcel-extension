<?php

namespace App\PersistActions\Parcel;

use App\Http\Requests\Parcel\UpdateRequest;
use App\Models\Detail;
use App\Models\Log;
use App\Models\Parcel;
use App\Models\ParcelAttachment;
use App\Models\ParcelComponent;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Kialex\BptStore\File as FileStore;
use Throwable;

class Update
{
    private ConnectionInterface $db;

    /**
     * @var Authenticatable|User
     */
    private Authenticatable $user;

    private FileStore $fileStore;

    private array $data;

    private UpdateRequest $request;

    private Parcel $parcel;

    public function __construct(ConnectionInterface $db, Authenticatable $user, FileStore $fileStore, UpdateRequest $request, Parcel $parcel)
    {
        $this->db = $db;
        $this->user = $user;
        $this->fileStore = $fileStore;
        $this->data = $request->validated();
        $this->request = $request;
        $this->parcel = $parcel;
    }

    /**
     * @return bool
     * @throws ValidationException
     * @throws Throwable
     */
    public function __invoke(): bool
    {
        $this->db->transaction(function (): void {
            $this->request->withinTransaction();
            $this->incrementOldQuantity();
            $this->createLogs();
            $this->update();
            $this->parcel->components()->delete();
            $this->parcel->attachments()->delete();
            $this->parcel->setRelation('components', $this->createComponents());
            $this->createAttachments();
            $this->decrementQuantity();
        }, 2);

//        ResponseCache::clear([SentDataTable::class]);

        return true;
    }

    private function update(): void
    {
        $this->parcel->fill($this->data);
        $this->parcel->user_id = $this->user->id;
        if ($this->data['delivery_cost'] ?? null) {
            $this->parcel->logistics_currency_id = $this->data['currency_id'];
            $this->parcel->logistics_cost = $this->data['delivery_cost'];
        } else {
            $this->parcel->logistics_currency_id = null;
            $this->parcel->logistics_cost = null;
        }
        $this->parcel->save();
    }

    private function createComponents(): Collection
    {
        $details = $this->db->table('detail_manufacture')
            ->where('manufacture_id', $this->parcel->sender_manufacture_id)
            ->whereIn('detail_id', Arr::pluck($this->data['components'], 'detail_id'))
            ->pluck('avg_price', 'detail_id');

        $components = [];
        $now = Carbon::now()->format('Y-m-d H:i:s');
        foreach ($this->data['components'] as $part) {
            $components[] = [
                'parcel_id' => $this->parcel->id,
                'detail_id' => $part['detail_id'],
                'price' => $details->get($part['detail_id']),
                'quantity' => $part['quantity'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        ParcelComponent::insert($components);

        return (new \Illuminate\Database\Eloquent\Collection($components))->mapInto(ParcelComponent::class);
    }

    private function createAttachments(): void
    {
        $attachments = [];
        /** @var UploadedFile $document */
        foreach ($this->data['attachments'] ?? [] as $document) {
            $hash = $this->fileStore->add($document->getRealPath(), 1)->hash;

            $attachments[] = [
                'parcel_id' => $this->parcel->id,
                'url' => $this->fileStore->getPublicUrl($hash),
            ];
        }

        ParcelAttachment::insert($attachments);
    }

    private function incrementOldQuantity(): void
    {
        $sql = 'INSERT INTO detail_manufacture (detail_id, manufacture_id, quantity, tested_quantity) VALUES ';
        $bindings = [];
        foreach ($this->parcel->components as $part) {
            $sql .= '(?,?,?,?),';
            array_push(
                $bindings,
                $part->detail_id,
                $this->parcel->sender_manufacture_id,
                $part->detail->requires_testing ? 0 : $part->quantity,
                $part->detail->requires_testing ? $part->quantity : 0
            );
        }
        if ($bindings) {
            $sql = rtrim($sql, ',') . ' ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity),tested_quantity=tested_quantity+VALUES(tested_quantity)';
            $this->db->insert($sql, $bindings);
        }
    }

    private function decrementQuantity(): void
    {
        $sql = 'INSERT INTO detail_manufacture (detail_id, manufacture_id, quantity, tested_quantity) VALUES ';
        $bindings = [];
        $parts = $this->parcel->components->loadMissing('detail')->keyBy('detail_id');
        foreach ($this->data['components'] as $part) {
            /** @var Detail $detail */
            $detail = $parts->get($part['detail_id'])->detail;
            $sql .= '(?,?,?,?),';
            array_push(
                $bindings,
                $detail->id,
                $this->parcel->sender_manufacture_id,
                $detail->requires_testing ? 0 : $part['quantity'],
                $detail->requires_testing ? $part['quantity'] : 0
            );
        }
        if ($bindings) {
            $sql = rtrim($sql, ',') . ' ON DUPLICATE KEY UPDATE quantity=quantity-VALUES(quantity),tested_quantity=tested_quantity-VALUES(tested_quantity)';
            $this->db->insert($sql, $bindings);
        }
    }

    private function createLogs(): void
    {
        $logs = [];
        $now = Carbon::now()->format('Y-m-d H:i:s');
        foreach ($this->parcel->components as $part) {
            $logs[] = [
                'action_id' => $this->parcel->id,
                'action_type' => Parcel::class,
                'action' => Parcel::ACTION_DELETED,
                'quantity' => $part->quantity,
                'detail_id' => $part->detail_id,
                'warehouse_id' => $this->parcel->sender_manufacture_id,
                'user_id' => $this->user->id,
                'comment' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach ($this->data['components'] as $part) {
            $logs[] = [
                'action_id' => $this->parcel->id,
                'action_type' => Parcel::class,
                'action' => Parcel::ACTION_SENT,
                'quantity' => (int)$part['quantity'],
                'detail_id' => $part['detail_id'],
                'warehouse_id' => $this->data['sender_manufacture_id'],
                'user_id' => $this->user->id,
                'comment' => $this->data['comment'] ?? '',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Log::insert($logs);
    }
}
