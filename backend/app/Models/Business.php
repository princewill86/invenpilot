<?php

namespace App\Models;

use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    protected $fillable = ['name', 'currency', 'allow_negative_stock'];

    protected function casts(): array
    {
        return ['allow_negative_stock' => 'boolean'];
    }
}
