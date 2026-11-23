<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One CAC registry search for an institution (see the create_cac_lookups_table migration). */
class CacLookup extends Model
{
    use SerializesLocalDates;

    public const VERIFIED = 'verified';
    /** RC number found, registered under another name. */
    public const NAME_MISMATCH = 'name_mismatch';
    /** Name found, registered under another RC number. */
    public const RC_MISMATCH = 'rc_mismatch';
    public const NOT_FOUND = 'not_found';
    public const UNAVAILABLE = 'unavailable';

    protected $fillable = [
        'institution_id', 'rc_number', 'name', 'result', 'company', 'response', 'error', 'searched_by', 'searched_at',
    ];

    protected $casts = [
        'company' => 'array',
        'response' => 'array',
        'searched_at' => 'datetime',
    ];

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function searcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'searched_by');
    }
}
