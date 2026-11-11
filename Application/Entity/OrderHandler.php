<?php

namespace App\GP247\Plugins\DataImport\Application\Entity;

use App\GP247\Plugins\DataImport\Models\ImportJob;
use GP247\Shop\Models\ShopCurrency;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopPaymentStatus;
use GP247\Shop\Services\Catalog\WriteContext;
use GP247\Shop\Services\Order\HistoricalOrderWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Order history, as an archive (HistoricalOrderWriter: no stock, mail, promotion or event).
 * The file has one row per product; rows of one order number form one order whose fields come
 * from its first row. Customers and products are linked through the id map (else by e-mail /
 * SKU); unknown ones keep their name, e-mail and SKU on the order. Source statuses go through
 * the run's status mapping — an unmapped value is an error, never a new status. Orders are only
 * created: a number already imported from the same source is skipped.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-order-history, US-data-import-order-status-mapping
 */
class OrderHandler extends EntityHandler
{
    /** Order fields copied as they are (shop_order columns). */
    private const ORDER_FIELDS = [
        'email', 'first_name', 'last_name', 'phone', 'company', 'address1', 'address2', 'address3', 'city', 'district', 'ward', 'country', 'postcode',
        'currency', 'exchange_rate', 'payment_method', 'shipping_method', 'comment',
    ];

    /** Money of the order (non-negative magnitudes). */
    private const MONEY = ['subtotal', 'shipping', 'tax', 'discount', 'other_fee', 'total', 'received'];

    /** Fields of one product row. */
    private const LINE_FIELDS = ['line_sku', 'line_name', 'line_price', 'line_qty', 'line_total', 'line_tax', 'line_tax_rate', 'line_discount'];

    /** Status kinds and whether the order needs one. */
    public const STATUS_KINDS = ['status' => true, 'shipping_status' => false, 'payment_status' => false];

    public function entity(): string
    {
        return 'order';
    }

    public function fields(): array
    {
        return array_merge(self::ORDER_FIELDS, self::MONEY, array_keys(self::STATUS_KINDS), ['customer', 'created_at'], self::LINE_FIELDS);
    }

    public function naturalKeyField(): string
    {
        return 'key';
    }

    public function requiredFields(): array
    {
        return [['key'], ['total'], ['line_name', 'line_sku']];
    }

    public function codeFields(): array
    {
        return array_merge(self::MONEY, ['exchange_rate', 'line_sku', 'line_price', 'line_qty', 'line_total', 'line_tax', 'line_tax_rate', 'line_discount', 'postcode', 'phone']);
    }

    public function groupsRows(): bool
    {
        return true;
    }

    public function updatable(): bool
    {
        return false;
    }

    /** Statuses must be mapped unless the run came with a mapping (preset, CLI). */
    public function waitsForValueMapping(ImportJob $job): bool
    {
        return !array_key_exists('statuses', (array) $job->mapping);
    }

    public function resolve(array $values, ImportJob $job, bool $creating, array $pendingKeys = []): array
    {
        $flat = ['source' => $job->source];
        $errors = [];
        foreach (array_merge(self::ORDER_FIELDS, self::MONEY) as $field) {
            if (isset($values[$field]) && trim((string) $values[$field]) !== '') {
                $flat[$field] = trim((string) $values[$field]);
            }
        }
        if (isset($flat['email'])) {
            $flat['email'] = mb_strtolower($flat['email']);
        }
        $flat['currency'] = strtoupper((string) ($flat['currency'] ?? ShopCurrency::getBaseCode() ?? 'USD'));
        $flat['exchange_rate'] ??= '1';
        if (isset($values['created_at']) && trim((string) $values['created_at']) !== '') {
            $flat['created_at'] = self::date((string) $values['created_at']);
        }
        foreach (self::STATUS_KINDS as $kind => $required) {
            [$id, $error] = $this->status($job, $kind, (string) ($values[$kind] ?? ''), $required);
            if ($error !== null) {
                $errors[$kind] = [$error];
            } elseif ($id !== null) {
                $flat[$kind] = (string) $id;
            }
        }
        $customerKey = trim((string) ($values['customer'] ?? '')) ?: (string) ($flat['email'] ?? '');
        $flat['customer_id'] = $customerKey !== '' ? ($this->reference($job, new CustomerHandler, mb_strtolower($customerKey)) ?? '0') : '0';

        $lines = $this->lines($job, (array) ($values['_lines'] ?? [$values]));
        if ($lines === []) {
            $errors['line_name'] = [self::message('error.order_without_lines')];
        }
        $flat['lines'] = json_encode($lines, JSON_UNESCAPED_UNICODE);
        $flat['subtotal'] ??= self::amount(array_sum(array_map(static fn ($l) => (float) $l['total_price'], $lines)));

        return [$flat, $errors];
    }

    public function warnings(array $flat, ImportJob $job): array
    {
        $lines = (array) json_decode((string) ($flat['lines'] ?? '[]'), true);
        $tolerance = 10 ** -$this->precision((string) $flat['currency']) * (count($lines) + 2);
        $n = static fn (string $key): float => is_numeric($flat[$key] ?? null) ? (float) $flat[$key] : 0.0;
        $warnings = [];

        $lineSum = array_sum(array_map(static fn ($l) => is_numeric($l['total_price'] ?? null) ? (float) $l['total_price'] : 0.0, $lines));
        $expected = $n('subtotal') + $n('shipping') + $n('other_fee') + $n('tax') - $n('discount');
        if (abs($lineSum - $n('subtotal')) > $tolerance || abs($expected - $n('total')) > $tolerance) {
            $warnings['total_mismatch'] = self::message('warning.total_mismatch', ['expected' => self::amount($expected), 'total' => (string) ($flat['total'] ?? '')]);
        }
        if (isset($flat['payment_status'])) {
            $received = $n('received');
            $derived = $received <= 0 ? ShopPaymentStatus::UNPAID : ($received + $tolerance >= $n('total') ? ShopPaymentStatus::PAID : ShopPaymentStatus::PARTIAL);
            if ((int) $flat['payment_status'] !== $derived) {
                $warnings['payment_status_mismatch'] = self::message('warning.payment_status_mismatch');
            }
        }

        return $warnings;
    }

    public function naturalMatches(string $store, array $flat): array
    {
        // Orders have no natural key: only the id map says an order was imported already.
        return [];
    }

    public function snapshot(string $store, string $id, array $keys): ?array
    {
        $order = ShopOrder::where('store_id', $store)->find($id);
        if ($order === null) {
            return null;
        }
        if ($keys === []) {
            return [];
        }

        // WHY fixed fields: orders are never updated by a run; the snapshot only tells whether the
        // order changed after it was imported (undo keeps a changed one).
        return [
            'status' => (string) $order->status, 'shipping_status' => (string) $order->shipping_status,
            'total' => (string) (float) $order->total, 'received' => (string) (float) $order->received, 'updated_at' => (string) $order->updated_at,
        ];
    }

    public function createdVersionKeys(): ?array
    {
        return ['status'];
    }

    public function payload(array $flat): array
    {
        $order = array_intersect_key($flat, array_flip(array_merge(self::ORDER_FIELDS, self::MONEY, ['status', 'shipping_status', 'customer_id', 'created_at'])));
        $history = [];
        if (isset($flat['status'])) {
            $history[] = ['order_status_id' => (int) $flat['status'], 'content' => 'Imported', 'add_date' => $flat['created_at'] ?? null];
        }
        $order['first_name'] = (string) ($order['first_name'] ?? ($order['email'] ?? '-'));

        return [
            'order' => $order,
            'lines' => (array) json_decode((string) ($flat['lines'] ?? '[]'), true),
            'history' => $history,
            'source' => (string) ($flat['source'] ?? ''),
        ];
    }

    public function validate(WriteContext $ctx, array $payload, ?string $id): array
    {
        try {
            (new HistoricalOrderWriter)->validate($payload);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    public function save(WriteContext $ctx, array $payload, ?string $id): Model
    {
        return (new HistoricalOrderWriter)->write($ctx, $payload);
    }

    public function delete(string $store, string $id): ?string
    {
        $reason = (new HistoricalOrderWriter)->remove(new WriteContext($store, WriteContext::ACTOR_IMPORT), $id);

        return $reason === null || $reason === 'not_found' ? null : self::message('undo.kept_not_archived');
    }

    /**
     * The order lines of the grouped rows (rows without a product name or SKU are left out).
     *
     * @param ImportJob $job
     * @param array<int, array<string, string>> $rows
     * @return array<int, array<string, string>>
     */
    private function lines(ImportJob $job, array $rows): array
    {
        $lines = [];
        $products = new ProductHandler;
        foreach ($rows as $row) {
            $sku = trim((string) ($row['line_sku'] ?? ''));
            $name = trim((string) ($row['line_name'] ?? ''));
            if ($sku === '' && $name === '') {
                continue;
            }
            $qty = trim((string) ($row['line_qty'] ?? '')) ?: '1';
            $price = trim((string) ($row['line_price'] ?? '')) ?: '0';
            $total = trim((string) ($row['line_total'] ?? ''));
            $line = [
                'sku' => $sku, 'name' => $name !== '' ? $name : $sku, 'price' => $price, 'qty' => $qty,
                'total_price' => $total !== '' ? $total : (is_numeric($price) && is_numeric($qty) ? self::amount((float) $price * (float) $qty) : $price),
                'product_id' => $sku !== '' ? ($this->reference($job, $products, $sku) ?? HistoricalOrderWriter::NO_PRODUCT) : HistoricalOrderWriter::NO_PRODUCT,
            ];
            foreach (['line_tax' => 'tax', 'line_tax_rate' => 'tax_rate', 'line_discount' => 'discount'] as $source => $target) {
                if (trim((string) ($row[$source] ?? '')) !== '') {
                    $line[$target] = trim((string) $row[$source]);
                }
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * A source status through the run's mapping.
     *
     * @param ImportJob $job
     * @param string $kind
     * @param string $value
     * @param bool $required
     * @return array{0: int|null, 1: string|null} [GP247 status id, error]
     */
    private function status(ImportJob $job, string $kind, string $value, bool $required): array
    {
        $map = (array) ((((array) $job->mapping)['statuses'] ?? [])[$kind] ?? []);
        $value = trim($value);
        if (array_key_exists($value, $map) && $map[$value] !== null && $map[$value] !== '') {
            return [(int) $map[$value], null];
        }
        if ($value === '' && !$required) {
            return [null, null];
        }

        return [null, self::message('error.status_unmapped', ['value' => $value])];
    }

    /**
     * Decimals of a currency (2 when the site does not know it).
     *
     * @param string $code
     * @return int
     */
    private function precision(string $code): int
    {
        static $cache = [];

        return $cache[$code] ??= (int) (ShopCurrency::where('code', $code)->value('precision') ?? 2);
    }

    /**
     * A date of the source as `Y-m-d H:i:s` in the site time zone (unparseable: kept, so the
     * write service reports it).
     *
     * @param string $value
     * @return string
     */
    private static function date(string $value): string
    {
        try {
            return Carbon::parse(trim($value))->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return trim($value);
        }
    }

    /**
     * @param float $value
     * @return string
     */
    private static function amount(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0';
    }
}
