<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Models;

use Cbox\Id\Whitelabel\Assets\DatabaseBrandAssetStore;
use Illuminate\Database\Eloquent\Model;

/**
 * One uploaded brand image, stored by {@see DatabaseBrandAssetStore}.
 *
 * NOT environment-scoped, on purpose: it is served to anonymous visitors of a hosted
 * sign-in page, which may be answered by any host, and it is found by its unguessable
 * path. Ownership is the `environment_key` column, which the store checks before it
 * removes anything.
 *
 * @property string $path
 * @property string $environment_key
 * @property string $mime
 * @property int $size
 * @property string $contents base64
 */
class BrandAsset extends Model
{
    protected $table = 'whitelabel_brand_assets';

    protected $primaryKey = 'path';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
