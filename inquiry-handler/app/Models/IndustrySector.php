<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A row of the industry-sector catalog (feature 012, data-model.md): a sector
 * name and its triage-viability rating 0–100. The rating is both the match
 * signal for the `industry_sector` factor and the admin-visible ranking; it is
 * never compounded by the factor, which scores exactly the stored rating.
 */
class IndustrySector extends Model
{
    protected $table = 'industry_sectors';

    protected $fillable = ['name', 'rating'];

    protected function casts(): array
    {
        return [
            'rating' => 'int',
        ];
    }
}