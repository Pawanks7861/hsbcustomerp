<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    protected $fillable = [
        'client_code',
        'name',
        'contact_person',
        'phone',
        'email',
        'address',
        'city',
        'state',
        'pincode',
        'gst_number',
        'pan_number',
        'payment_terms',
        'notes',
        'status',
    ];

    public function invoices(): HasMany
    {
        return $this->hasMany(ClientInvoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ClientPayment::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function billingAddress(): string
    {
        return collect([$this->address, $this->city, $this->state, $this->pincode])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->implode(', ');
    }
}
