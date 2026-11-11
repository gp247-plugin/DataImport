<?php

namespace App\GP247\Plugins\DataImport\Application\Entity;

use App\GP247\Plugins\DataImport\Models\ImportJob;
use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Services\Catalog\CustomerWriter;
use GP247\Shop\Services\Catalog\WriteContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Customers, identified by e-mail (compared in lower case). Passwords: only a bcrypt / argon2
 * hash is carried over; anything else is left out and the customer resets the password.
 * The password hash never enters the review diff or the undo log.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-customers
 */
class CustomerHandler extends EntityHandler
{
    public function entity(): string
    {
        return 'customer';
    }

    public function fields(): array
    {
        return array_merge(CustomerWriter::FIELDS, ['status', 'password_hash']);
    }

    public function naturalKeyField(): string
    {
        return 'email';
    }

    public function codeFields(): array
    {
        return ['phone', 'postcode', 'email'];
    }

    public function sourceKey(array $values): string
    {
        $key = trim((string) ($values['key'] ?? ''));

        return $key !== '' ? $key : mb_strtolower(trim((string) ($values['email'] ?? '')));
    }

    public function resolve(array $values, ImportJob $job, bool $creating, array $pendingKeys = []): array
    {
        $flat = [];
        foreach ($this->fields() as $field) {
            if (array_key_exists($field, $values)) {
                $flat[$field] = trim((string) $values[$field]);
            }
        }
        if (isset($flat['email'])) {
            $flat['email'] = mb_strtolower($flat['email']);
        }
        if (array_key_exists('password_hash', $flat) && $flat['password_hash'] !== '' && !CustomerWriter::acceptsHash($flat['password_hash'])) {
            // D7: not a hash GP247 can check — leave it out; the customer resets the password.
            unset($flat['password_hash']);
        }

        return [$flat, []];
    }

    public function naturalMatches(string $store, array $flat): array
    {
        $email = (string) ($flat['email'] ?? '');
        if ($email === '') {
            return [];
        }
        $rows = ShopCustomer::where('store_id', $store)->where('email', $email)->get(['id', 'email'])
            ->map(static function ($row) {
                $row->email = mb_strtolower((string) $row->email);

                return $row;
            });

        return self::exact($rows, 'email', $email);
    }

    public function snapshot(string $store, string $id, array $keys): ?array
    {
        $customer = ShopCustomer::where('store_id', $store)->find($id);
        if ($customer === null) {
            return null;
        }
        $out = [];
        foreach ($keys as $key) {
            if ($key === 'password_hash') {
                continue;
            }
            $out[$key] = $key === 'email' ? mb_strtolower((string) $customer->email) : (string) ($customer->{$key} ?? '');
        }
        if ($keys !== []) {
            $out['updated_at'] = (string) $customer->updated_at;
        }

        return $out;
    }

    public function payload(array $flat): array
    {
        return array_intersect_key($flat, array_flip($this->fields()));
    }

    public function validate(WriteContext $ctx, array $payload, ?string $id): array
    {
        return (new CustomerWriter)->validate($ctx, $payload, $id);
    }

    public function save(WriteContext $ctx, array $payload, ?string $id): Model
    {
        return (new CustomerWriter)->save($ctx, $payload, $id);
    }

    public function delete(string $store, string $id): ?string
    {
        $customer = ShopCustomer::where('store_id', $store)->find($id);
        if ($customer === null) {
            return null;
        }
        if (ShopOrder::where('customer_id', $id)->exists()) {
            return self::message('undo.kept_has_orders');
        }
        // ShopCustomer::boot() removes its addresses and custom fields.
        $customer->delete();

        return null;
    }
}
