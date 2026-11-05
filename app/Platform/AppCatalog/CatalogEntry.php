<?php

declare(strict_types=1);

namespace App\Platform\AppCatalog;

use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $environment_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string $launch_url
 * @property string|null $icon_data
 * @property bool $enabled
 * @property int $position
 * @property string $audience
 * @property list<string> $organization_ids
 * @property list<string> $user_ids
 * @property list<string> $client_ids
 * @property string|null $required_feature
 * @property int $version
 */
final class CatalogEntry extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;

    protected $table = 'app_catalog_entries';

    protected $guarded = [];

    protected $hidden = ['icon_data'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'position' => 'integer', 'version' => 'integer', 'organization_ids' => 'array', 'user_ids' => 'array', 'client_ids' => 'array'];
    }
}
