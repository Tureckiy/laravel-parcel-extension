<?php

namespace App\PersistActions\Parcel;

use App\Http\Requests\Parcel\StoreRequest;
use App\Models\Detail;
use App\Models\Log;
use App\Models\Parcel;
use App\Models\ParcelAttachment;
use App\Models\ParcelComponent;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Kialex\BptStore\File as FileStore;
use Throwable;

class Send
{
    private ConnectionInterface $db;

    /**
     * @var Authenticatable|User
     */
    private Authenticatable $user;

    private FileStore $fileStore;

    private StoreRequest $request;

    private array $data;

    private Parcel $parcel;


    public function __construct(ConnectionInterface $db, Authenticatable $user, FileStore $fileStore, StoreRequest $request)
    {
        $this->db = $db;
        $this->user = $user;
        $this->fileStore = $fileStore;
        $this->request = $request;
        $this->data = $request->validated();
        $this->parcel = $this->instantiate();
        $this->fileStore = $fileStore;
    }

    /**
     * @return bool
     * @throws ValidationException
     * @throws Throwable
     */
    public function __invoke(): bool
    {
        $parts = Detail::with(['unit'])
            ->select(['details.*', 'dm.avg_price'])
            ->leftJoin('detail_manufacture AS dm', function (JoinClause $join): void {
                $join->on('details.id', 'dm.detail_id')
                    ->where('dm.manufacture_id', $this->user->bound_manufacture_id);
            })
            ->where('dm.manufacture_id', $this->user->bound_manufacture_id)
            ->whereIn('id', Arr::pluck($this->data['components'], 'detail_id'))
            ->get()
            ->keyBy('id');

        $this->db->transaction(function () use ($parts): void {
            $this->request->withinTransaction();
            $this->parcel->save();
            $this->createComponents($parts);
            $this->createAttachments();
            $this->decrementQuantity($parts);
            $this->createLogs();
        }, 2);
//        ResponseCache::clear([SentDataTable::class]);

        return true;
    }

    private function instantiate(): Parcel
    {
        $parcel = Parcel::make($this->data);
        $parcel->user_id = $this->user->id;
        if ($this->data['delivery_cost'] ?? null) {
            $parcel->logistics_currency_id = $this->data['currency_id'];
            $parcel->logistics_cost = $this->data['delivery_cost'];
        }

        return $parcel;
    }

    private function createComponents(Collection $parts): void
    {
        $components = [];
        $now = Carbon::now()->format('Y-m-d H:i:s');
        foreach ($this->data['components'] as $part) {
            $components[] = [
                'parcel_id' => $this->parcel->id,
                'detail_id' => $part['detail_id'],
                'price' => $parts->get($part['detail_id'])->avg_price,
                'quantity' => $part['quantity'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        ParcelComponent::insert($components);
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

    /**
     * @param Collection|Detail[] $parts
     */
    private function decrementQuantity(Collection $parts): void
    {
        $sql = 'INSERT INTO detail_manufacture (detail_id, manufacture_id, quantity, tested_quantity) VALUES ';
        $bindings = [];
        foreach ($this->data['components'] as $part) {
            /** @var Detail $detail */
            $detail = $parts->get($part['detail_id']);
            $sql .= '(?,?,?,?),';
            array_push(
                $bindings,
                $detail->id,
                $this->data['sender_manufacture_id'],
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
