<?php

namespace App\PersistActions\Parcel;

use App\Http\Requests\Parcel\PostRequest;
use App\Models\Log;
use App\Models\Manufacture;
use App\Models\Parcel;
use App\Models\ParcelComponent;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Throwable;

class Post
{
    private ConnectionInterface $db;

    /**
     * @var Authenticatable|User
     */
    private Authenticatable $user;

    private array $data;

    private PostRequest $request;

    private Parcel $parcel;

    public function __construct(ConnectionInterface $db, Authenticatable $user, PostRequest $request, Parcel $parcel)
    {
        $this->db = $db;
        $this->user = $user;
        $this->data = $request->validated();
        $this->request = $request;
        $this->parcel = $parcel;
    }

    /**
     * @throws Throwable
     */
    public function __invoke(): void
    {
        $this->db->transaction(function (): void {
            $components = $this->parcel->components()->sharedLock()->get();
            $this->parcel->setRelation('components', $components);
            $this->request->withinTransaction();
            $components = $components->keyBy('id');
            if ($this->updateComponentsAndIncrementQuantity($components)) {
                $this->parcel->update(['is_posted' => true]);
            }
            $this->createLogs($components);
        }, 2);

//        ResponseCache::clear([DetailDataTable::class]);
    }

    private function updateComponentsAndIncrementQuantity(Collection $components): bool
    {
        $sql = 'INSERT INTO detail_manufacture (detail_id,manufacture_id,quantity,tested_quantity,last_price) VALUES ';

        $recalculate = config('app.price_recalculation');
        if ($this->parcel->logistics_cost) {
            $currency = $this->parcel->currency()->sharedLock()->first();
            $logisticsCost = bcdiv($this->parcel->logistics_cost, $currency->rate ?? 1, 30);
        } else {
            $logisticsCost = '0';
        }

        $bindings = [];
        $isPosted = true;
        $totalQuantity = $this->parcel->getComponentsQuantity() ?? 0;

        foreach ($this->data['components'] as $component) {
            /** @var ParcelComponent $model */
            $model = $components[$component['id']];
            $isPosted &= bccomp(
                    bcadd($model->posted, $component['quantity'], 6),
                    $model->quantity,
                    6
                ) === 0;
            if (bccomp($component['quantity'], '0', 6) < 1) {
                continue;
            }
            ParcelComponent::whereId($model->id)->increment('posted', $component['quantity']);

            $model->price ??= $this->resolvePrice($model->detail_id);
            $sql .= '(?,?,?,?,?),';
            array_push(
                $bindings,
                $model->detail_id,
                $this->parcel->receiver_manufacture_id,
                $model->detail->requires_testing ? 0 : $component['quantity'],
                $model->detail->requires_testing ? $component['quantity'] : 0,
                $recalculate && $model->price ?
                    last_price_partial($component['quantity'], $totalQuantity, $model->price, $logisticsCost) :
                    null,
            );
        }

        if ($bindings) {
            $sql = rtrim($sql, ',') . ' ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity),
                                            tested_quantity=tested_quantity+VALUES(tested_quantity),
                                            last_price=COALESCE(VALUES(last_price),last_price)';
            $this->db->insert($sql, $bindings);
        }

        return $isPosted;
    }

    private function resolvePrice(int $detailId): ?string
    {
        $prices = $this->db->table('detail_manufacture')
            ->where('detail_id', $detailId)
            ->where(function (Builder $query): void {
                $query->where('manufacture_id', $this->parcel->receiver_manufacture_id)
                    ->orWhere('manufacture_id', Manufacture::fallbackManufacture()->id);
            })
            ->pluck('avg_price', 'manufacture_id');

        return $prices[$this->parcel->receiver_manufacture_id] ?? $prices[Manufacture::fallbackManufacture()->id];
    }

    private function createLogs(Collection $components): void
    {
        $logs = [];
        $now = Carbon::now();
        foreach ($this->data['components'] as $component) {
            $model = $components[$component['id']];
            $logs[] = [
                'action_id' => $this->parcel->id,
                'action_type' => Parcel::class,
                'action' => Parcel::ACTION_POSTED,
                'quantity' => $component['quantity'],
                'detail_id' => $model->detail_id,
                'warehouse_id' => $this->parcel->receiver_manufacture_id,
                'user_id' => $this->user->id,
                'comment' => $component['comment'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Log::insert($logs);
    }
}
