<?php

declare(strict_types=1);
use App\Platform\Actions\ActionRegistry;
use Symfony\Component\Yaml\Yaml;

/*
| The committed OpenAPI document is exactly what the base and the action registry build.
| A new action is documented by building, never by hand; this is what fails when nobody did.
*/

it('commits the OpenAPI document the base and the actions build', function (): void {
    $this->artisan('openapi:build', ['--check' => true])->assertSuccessful();
});

it('documents every action as the action declares it', function (): void {
    $spec = Yaml::parseFile(resource_path('openapi/environment.yaml'));

    foreach (app(ActionRegistry::class)->all() as $action) {
        expect($spec['paths'][$action->path][strtolower($action->method)] ?? null)
            ->toBeArray("{$action->name} is not in the OpenAPI document");
    }
});
