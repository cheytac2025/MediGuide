<?php

namespace App\Models;

use App\Enums\AccountInvitationPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $invited_by
 * @property AccountInvitationPurpose $purpose
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_sent_at
 * @property int $send_count
 */
class AccountInvitation extends Model
{
    protected $fillable = [
        'user_id',
        'invited_by',
        'purpose',
        'token_hash',
        'expires_at',
        'consumed_at',
        'revoked_at',
        'last_sent_at',
        'send_count',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isOpen(): bool
    {
        return $this->consumed_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    protected function casts(): array
    {
        return [
            'purpose' => AccountInvitationPurpose::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'send_count' => 'integer',
        ];
    }
}
