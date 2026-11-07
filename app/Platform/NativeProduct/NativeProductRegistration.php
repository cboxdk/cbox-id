<?php

declare(strict_types=1);

namespace App\Platform\NativeProduct;

use Illuminate\Database\Eloquent\Model;

/** @property string $environment_id
 * @property string $client_id
 */
final class NativeProductRegistration extends Model
{
    protected $table = 'native_product_clients';

    protected $primaryKey = 'product';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['product', 'environment_id', 'client_id'];
}
